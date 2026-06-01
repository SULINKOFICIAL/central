<?php

namespace App\Http\Controllers;

use App\Http\Requests\Api\CheckOnboardingIdentityRequest;
use App\Http\Requests\Api\FinalizeOnboardingRequest;
use App\Http\Requests\Api\SaveOnboardingStepRequest;
use App\Models\City;
use App\Models\State;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\TenantProvisioning;
use App\Services\CpanelProvisioningService;
use App\Services\TenantInitialTrialPlanService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class ApisOnboardingController extends Controller
{
    public function __construct(
        private readonly Tenant $tenantRepository,
        private readonly CpanelProvisioningService $cpanelProvisioningService,
        private readonly TenantInitialTrialPlanService $tenantInitialTrialPlanService
    ) {}

    /**
     * Lista estados disponíveis para o formulário público de onboarding.
     */
    public function states(): JsonResponse
    {
        return response()->json([
            'states' => State::where('status', 1)
                ->orderBy('name')
                ->get(['id', 'country_id', 'name', 'acronym', 'code', 'cuf']),
        ]);
    }

    /**
     * Lista cidades de um estado para o formulário público de onboarding.
     */
    public function cities(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'state_id' => ['required', 'integer', 'exists:states,id'],
        ]);

        return response()->json([
            'cities' => City::where('state_id', $validated['state_id'])
                ->where('status', 1)
                ->orderBy('name')
                ->get(['id', 'state_id', 'name', 'code_ibge']),
        ]);
    }

    /**
     * Verifica se já existe tenant para identidade informada no onboarding.
     * Permite continuidade apenas quando o cadastro ainda não foi concluído.
     */
    public function checkOnboardingIdentity(CheckOnboardingIdentityRequest $request): JsonResponse
    {
        /**
         * O FormRequest centraliza normalização e validação para a action
         * trabalhar apenas com dados dentro do contrato público da API.
         */
        $data = $request->validated();

        $hasEmail = !empty($data['email']);
        $hasDocument = !empty($data['document_type']);

        /**
         * Consulta isolada por e-mail cobre o primeiro passo do onboarding,
         * quando documento ainda não foi informado pelo cliente.
         */
        if ($hasEmail && !$hasDocument) {
            $email = $data['email'];

            if (!is_string($email)) {
                $email = '';
            }

            $tenantByEmail = Tenant::where('email', mb_strtolower($email))->first();
            if (!$tenantByEmail) {
                return response()->json([
                    'exists' => false,
                    'is_completed' => false,
                    'can_continue' => true,
                ]);
            }

            $isCompleted = !empty($tenantByEmail->onboarding_completed_at);

            return response()->json([
                'exists' => true,
                'is_completed' => $isCompleted,
                'can_continue' => !$isCompleted,
            ]);
        }

        /**
         * Quando há documento, a identidade passa a ser CPF ou CNPJ.
         * Isso evita misturar chaves de pessoa física e jurídica.
         */
        $tenant = $this->findTenantByIdentity($data);

        if (!$tenant) {
            /**
             * Identidade inexistente libera o início do cadastro.
             */
            return response()->json([
                'exists' => false,
                'is_completed' => false,
                'can_continue' => true,
            ]);
        }

        /**
         * O timestamp de conclusão é a fonte de verdade para diferenciar
         * rascunho retomável de conta já provisionada.
         */
        $isCompleted = !empty($tenant->onboarding_completed_at);

        /**
         * Conta concluída bloqueia avanço; rascunho mantém continuidade.
         */
        return response()->json([
            'exists' => true,
            'is_completed' => $isCompleted,
            'can_continue' => !$isCompleted,
        ]);
    }

    /**
     * Persiste incrementalmente os dados da etapa atual do onboarding.
     * Cria rascunho quando não houver tenant correspondente.
     */
    public function saveOnboardingStep(SaveOnboardingStepRequest $request): JsonResponse
    {
        /**
         * Payload validado evita persistir campos fora da etapa atual.
         */
        $data = $request->validated();

        /**
         * A etapa atual é persistida para o frontend poder retomar o fluxo.
         */
        $step = $data['step'];

        /**
         * A identidade resolve rascunhos existentes e impede duplicar
         * uma mesma pessoa/empresa durante o onboarding.
         */
        $tenant = $this->findTenantByIdentity($data);

        if ($tenant && !empty($tenant->onboarding_completed_at)) {
            /**
             * Cadastros finalizados não podem ser sobrescritos por salvamento incremental.
             */
            return response()->json([
                'message' => 'Cadastro já finalizado para este tenant.',
            ], 409);
        }

        /**
         * Apenas campos oficiais do onboarding podem atualizar o tenant.
         */
        $updatableData = $this->extractOnboardingUpdatableData($data);

        /**
         * Guarda o ponto exato onde o cliente parou.
         */
        $updatableData['onboarding_current_step'] = $step;

        if ($tenant) {
            /**
             * Garante data de início somente no primeiro salvamento incremental.
             */
            if (empty($tenant->onboarding_started_at)) {
                $updatableData['onboarding_started_at'] = now();
            }

            /**
             * Atualiza rascunho existente sem tocar em provisionamento.
             */
            $tenant->update($updatableData);
        } else {
            /**
             * Cria rascunho retomável sem provisionar infraestrutura.
             */
            $tenant = $this->tenantRepository->create(array_merge($this->buildDraftTenantDefaults($data), $updatableData, [
                'onboarding_started_at' => now(),
            ]));
        }

        /**
         * Objetivos são relacionais; salvar no step evita perder seleção
         * antes da finalização do cadastro.
         */
        if ($step === 'goal' && array_key_exists('main_goals', $data)) {
            $this->replaceTenantMainGoals($tenant, $data['main_goals'] ?? []);
        }

        /**
         * O tenant_id permite o frontend seguir no mesmo rascunho.
         */
        return response()->json([
            'tenant_id' => $tenant->id,
            'step' => $tenant->onboarding_current_step,
            'completed' => !empty($tenant->onboarding_completed_at),
        ]);
    }

    /**
     * Finaliza o onboarding, executa provisionamento e conclui o cadastro.
     * Mantém bloqueio para identidades já finalizadas.
     */
    public function finalizeOnboarding(FinalizeOnboardingRequest $request): JsonResponse
    {
        /**
         * A finalização também parte somente do contrato validado pelo Request.
         */
        $data = $request->validated();

        /**
         * A finalização resolve o rascunho pela identidade informada.
         */
        $tenant = $this->findTenantByIdentity($data);

        if (!$tenant) {
            /**
             * Sem rascunho resolvido não há registro central para receber
             * domínio, banco, plano e checkpoints de instalação.
             */
            return response()->json([
                'message' => 'Não foi possível localizar o tenant para finalização.',
            ], 404);
        }

        if (!empty($tenant->onboarding_completed_at)) {
            /**
             * Conta já finalizada não pode disparar provisionamento novamente.
             */
            return response()->json([
                'message' => 'Cadastro já finalizado para este tenant.',
            ], 409);
        }

        /**
         * Consolida dados da última etapa antes do provisionamento.
         * O snapshot precisa ficar persistido antes de gerar domínio/banco.
         */
        $this->updateTenantFinalStepData($tenant, $data);

        /**
         * Não permite finalizar se outro tenant já concluído usar a mesma identidade.
         * Rascunhos podem continuar, mas contas ativas não podem duplicar documento.
         */
        $this->assertNoCompletedConflicts($tenant);

        if (array_key_exists('main_goals', $data)) {
            /**
             * Se vier goals no payload final, substitui o conjunto atual
             * para refletir exatamente a última seleção do cliente.
             */
            $this->replaceTenantMainGoals($tenant, $data['main_goals'] ?? []);
        }

        /**
         * Garante dados técnicos sem sobrescrever tentativas já iniciadas.
         * Essa decisão torna o retry idempotente: domínio, banco, usuário,
         * senha e checkpoint já salvos continuam sendo a fonte de verdade.
         */
        $this->ensureProvisioningData($tenant, $data, $request);

        /**
         * Garante plano base antes da execução do provisionamento principal.
         * O tenant remoto precisa receber módulos e limites na etapa modules.
         */
        $this->tenantInitialTrialPlanService->ensureForTenant($tenant);

        /**
         * Dispara todas as etapas pendentes e guarda retorno operacional para resposta da API.
         */
        try {
            $provisioningResult = $this->runProvisioningUntilCompleted($tenant);
        } catch (Throwable $exception) {
            /**
             * Recarrega o checkpoint real para o consumidor saber de qual
             * etapa deve tentar novamente, sem receber tela HTML de erro.
             */
            $tenant->unsetRelation('provisioning');
            $tenant->loadMissing('provisioning');

            return response()->json([
                'message' => 'Falha ao finalizar provisionamento do tenant.',
                'error' => $exception->getMessage(),
                'provisioning' => [
                    'step' => $tenant->provisioning?->install,
                ],
            ], 422);
        }

        /**
         * Marca onboarding como concluído somente após processar provisionamento.
         * Falha parcial mantém o cadastro retomável no checkpoint salvo.
         */
        $tenant->onboarding_completed_at = now();
        $tenant->onboarding_current_step = 'address';
        $tenant->save();

        return response()->json([
            'tenant_id' => $tenant->id,
            'message' => 'Onboarding finalizado com sucesso.',
            'provisioning' => $provisioningResult,
        ]);
    }

    /**
     * Busca tenant por documento conforme tipo selecionado no onboarding.
     * Prioriza registros concluídos para manter bloqueio consistente.
     */
    private function findTenantByIdentity(array $data): ?Tenant
    {

        /**
         * Priorizamos tenants concluídos para não permitir bypass de bloqueio.
         */
        $query = Tenant::orderByDesc('onboarding_completed_at')->orderByDesc('id');

        /**
         * O tipo de documento define qual coluna representa a identidade.
         */
        $documentType = $data['document_type'] ?? '';

        if (!is_string($documentType)) {
            $documentType = '';
        }

        /**
         * CPF e CNPJ compartilham a mesma função, mas não a mesma coluna.
         */
        $documentValue = $documentType === 'cpf' ? ($data['cpf'] ?? '') : ($data['cnpj'] ?? '');

        if (!is_string($documentValue)) {
            $documentValue = '';
        }

        /**
         * A coluna segue diretamente o tipo validado no request.
         */
        $column = $documentType === 'cpf' ? 'cpf' : 'cnpj';

        /**
         * Retorna o registro mais relevante para continuidade ou bloqueio.
         */
        return $query->where($column, $documentValue)->first();

    }

    /**
     * Filtra campos de onboarding permitidos para atualização do tenant.
     * Centraliza saneamento de email, documentos e telefone.
     */
    private function extractOnboardingUpdatableData(array $data): array
    {
        /**
         * Whitelist explícita protege contra mass assignment acidental
         * e impede payload externo de alterar campos operacionais.
         */
        $allowedFields = [
            'name',
            'email',
            'whatsapp',
            'company',
            'cnpj',
            'cpf',
            'company_profile',
            'company_zip_code',
            'company_state_id',
            'company_city_id',
            'company_address',
            'company_neighborhood',
            'company_number',
            'company_complement',
            'tips_whatsapp',
            'tips_email',
            'has_coupon',
            'coupon_code',
            'document_type',
        ];

        /**
         * Remove qualquer chave extra que não pertença ao contrato do onboarding.
         */
        return array_intersect_key($data, array_flip($allowedFields));
    }

    /**
     * Define valores mínimos para criação de tenant em rascunho.
     * Gera domínio temporário e token inicial para o cadastro.
     */
    private function buildDraftTenantDefaults(array $data): array
    {
        /**
         * Nome padrão evita falha quando o payload vier sem identificação textual.
         */
        $name = $data['name'] ?? 'Cadastro em andamento';

        if (!is_string($name) || $name === '') {
            $name = 'Cadastro em andamento';
        }

        /**
         * Company usa name como fallback para manter regra de domínio consistente.
         */
        $company = $data['company'] ?? $name;

        if (!is_string($company) || $company === '') {
            $company = $name;
        }

        /**
         * Slug serve de base para domínio temporário de rascunho.
         */
        $draftSlug = Str::slug($company ?: $name);

        if ($draftSlug === '') {
            /**
             * Fallback cobre payload vazio ou inválido para slug.
             */
            $draftSlug = 'tenant-' . Str::lower(Str::random(8));
        }

        return [
            /**
             * Dados mínimos para persistir tenant em estado de onboarding.
             */
            'name' => $name,
            'company' => $company,
            'domain' => "draft-{$draftSlug}-" . time() . '.micore.com.br',
            'created_by' => 1,
            'status' => true,
            'token' => hash('sha256', $name . microtime(true)),
        ];
    }

    /**
     * Garante que não exista outro tenant concluído com mesma identidade.
     * Bloqueia finalização para evitar duplicidade de conta ativa.
     */
    private function assertNoCompletedConflicts(Tenant $tenant): void
    {
        /**
         * Só conflita contra cadastros já concluídos, não contra rascunhos.
         */
        $conflictQuery = Tenant::whereNotNull('onboarding_completed_at')
            ->where('id', '!=', $tenant->id);

        /**
         * A identidade pode conflitar por email, CNPJ ou CPF.
         */
        $conflictQuery->where(function ($query) use ($tenant) {
            if (!empty($tenant->email)) {
                /**
                 * Comparação de email em lowercase evita falso-negativo.
                 */
                $email = $tenant->email;

                if (is_string($email)) {
                    $query->orWhere('email', mb_strtolower($email));
                }
            }
            if (!empty($tenant->cnpj)) {
                $query->orWhere('cnpj', onlyNumbers($tenant->cnpj));
            }
            if (!empty($tenant->cpf)) {
                $query->orWhere('cpf', onlyNumbers($tenant->cpf));
            }
        });

        if ($conflictQuery->exists()) {
            /**
             * Retorno 409 explicita conflito de negócio para o consumidor da API.
             */
            throw new HttpResponseException(response()->json([
                'message' => 'Já existe uma conta finalizada com esses dados.',
            ], 409));
        }
    }

    /**
     * Atualiza os dados consolidados da etapa final no tenant rascunho.
     * Mantém a etapa atual marcada como address antes de provisionar.
     */
    private function updateTenantFinalStepData(Tenant $tenant, array $data): void
    {
        /**
         * Aproveita o mesmo filtro de campos usado no salvamento incremental.
         */
        $updatableData = $this->extractOnboardingUpdatableData($data);

        /**
         * A etapa final sempre precisa ser address para refletir fluxo concluído.
         */
        $updatableData['onboarding_current_step'] = 'address';

        if (empty($tenant->onboarding_started_at)) {
            /**
             * Segurança para cadastros antigos sem timestamp inicial.
             */
            $updatableData['onboarding_started_at'] = now();
        }

        /**
         * Persiste snapshot final de dados antes do provisionamento técnico.
         */
        $tenant->update($updatableData);
    }

    /**
     * Substitui os objetivos principais do tenant pelos selecionados no fluxo.
     * Garante persistência relacional consistente por finalização.
     */
    private function replaceTenantMainGoals(Tenant $tenant, array $mainGoals): void
    {
        /**
         * O conjunto é substituído por completo para evitar objetivos obsoletos.
         */
        $tenant->mainGoals()->delete();

        if (empty($mainGoals)) {
            /**
             * Sem objetivos selecionados, mantém relacionamento vazio.
             */
            return;
        }

        /**
         * Cria objetivos válidos como registros relacionais independentes.
         */
        $tenant->mainGoals()->createMany(array_map(function ($goal) {
            return ['goal' => $goal];
        }, $mainGoals));
    }

    /**
     * Monta o payload de provisionamento técnico e primeiro usuário.
     * Reaproveita dados já coletados no onboarding para instalação.
     */
    private function buildProvisioningData(Tenant $tenant, array $data, Request $request): array
    {
        /**
         * Company/nome definem slug de subdomínio e identificação técnica.
         */
        $tenantCompany = $tenant->company ?: $tenant->name;
        $rawDomain = verifyIfAllow($tenantCompany ?: ('tenant-' . $tenant->id));

        /**
         * Usuário de banco precisa evitar hífen para cumprir padrão técnico.
         */
        $domainClean = str_replace('-', '_', $rawDomain);

        /**
         * Prefixo mantém convenção de bancos por ambiente no cPanel.
         */
        $tableUser = env('CPANEL_PREFIX') . '_' . $domainClean;
        $tableName = env('CPANEL_PREFIX') . '_' . $domainClean;

        /**
         * Primeiro usuário usa nome do tenant como fallback seguro.
         */
        $firstUserName = $tenant->name ?: 'Usuário';
        $passwordInput = $data['password'] ?? $request->input('password', '');
        $plainPassword = is_string($passwordInput) ? $passwordInput : '';

        return [
            'domain' => $rawDomain . '.micore.com.br',
            'token' => hash('sha256', ($tenantCompany ?: $firstUserName) . microtime(true)),
            'provisioning' => [
                'table' => $tableName,
                'table_user' => $tableUser,
                'table_password' => Str::random(12),
                'first_user' => [
                    'name' => $firstUserName,
                    'email' => $tenant->email,
                    'password' => $plainPassword,
                    'short_name' => generateShortName($firstUserName),
                ],
                'install' => TenantProvisioning::STEP_SUBDOMAIN,
            ],
        ];
    }

    /**
     * Cria domínio/provisioning somente quando a finalização ainda não começou.
     * Em retentativa, preserva banco, usuário, senha e checkpoint já salvos.
     */
    private function ensureProvisioningData(Tenant $tenant, array $data, Request $request): void
    {
        $tenant->loadMissing('domains', 'provisioning', 'runtimeStatus');

        if ($tenant->provisioning && $tenant->domains->isNotEmpty()) {
            /**
             * Retentativa deve continuar do checkpoint existente.
             * Recalcular aqui poderia criar domínio/banco com sufixo novo.
             */
            return;
        }

        /**
         * Só chega aqui quando ainda não existe infraestrutura central mínima.
         */
        $provisioningData = $this->buildProvisioningData($tenant, $data, $request);

        if (empty($tenant->token)) {
            /**
             * Mantém token existente quando já houver integração ativa.
             */
            $tenant->token = $provisioningData['token'];
        }

        $tenant->save();

        if ($tenant->domains->isEmpty()) {
            /**
             * O domínio é salvo uma única vez para virar referência estável
             * das chamadas cPanel e da sincronização remota.
             */
            TenantDomain::create([
                'tenant_id' => $tenant->id,
                'auto_generate' => true,
                'domain' => $provisioningData['domain'],
                'description' => 'Domínio cadastrado ao finalizar onboarding pela API',
                'status' => true,
            ]);
        }

        if (!$tenant->provisioning) {
            /**
             * Em primeira finalização, cria registro técnico de provisionamento.
             */
            $tenant->provisioning()->create($provisioningData['provisioning']);
        }

        if (!$tenant->runtimeStatus) {
            /**
             * Runtime status é necessário para monitoramento pós-instalação.
             */
            $tenant->runtimeStatus()->create();
        }

        /**
         * Limpa relações carregadas para a próxima etapa ler o estado persistido.
         */
        $tenant->unsetRelation('domains');
        $tenant->unsetRelation('provisioning');
        $tenant->unsetRelation('runtimeStatus');
    }

    /**
     * A API final precisa concluir todas as etapas que a tela executa uma por vez.
     */
    private function runProvisioningUntilCompleted(Tenant $tenant): array
    {
        $steps = [];
        $lastResult = [];

        foreach (TenantProvisioning::INSTALL_STEPS as $step) {
            $tenant->unsetRelation('provisioning');
            $tenant->unsetRelation('domains');

            $lastResult = $this->cpanelProvisioningService->runProvisioning($tenant);
            $steps[] = $lastResult;

            if (($lastResult['step'] ?? null) === TenantProvisioning::STEP_COMPLETED) {
                break;
            }
        }

        $lastResult['steps'] = $steps;

        return $lastResult;
    }
}
