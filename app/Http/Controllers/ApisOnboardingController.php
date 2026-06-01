<?php

namespace App\Http\Controllers;

use App\Http\Requests\Api\CheckOnboardingIdentityRequest;
use App\Http\Requests\Api\FinalizeOnboardingRequest;
use App\Http\Requests\Api\SaveOnboardingStepRequest;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\TenantProvisioning;
use App\Services\CpanelProvisioningService;
use App\Services\TenantInitialTrialPlanService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ApisOnboardingController extends Controller
{
    public function __construct(
        private readonly Tenant $tenantRepository,
        private readonly CpanelProvisioningService $cpanelProvisioningService,
        private readonly TenantInitialTrialPlanService $tenantInitialTrialPlanService
    ) {}

    /**
     * Verifica se já existe tenant para identidade informada no onboarding.
     * Permite continuidade apenas quando o cadastro ainda não foi concluído.
     */
    public function checkOnboardingIdentity(CheckOnboardingIdentityRequest $request): JsonResponse
    {
        // O FormRequest já entrega os dados normalizados e validados.
        $data = $request->validated();

        $hasEmail = !empty($data['email']);
        $hasDocument = !empty($data['document_type']);

        // Consulta de e-mail é usada apenas para bloquear avanço quando já existe cadastro.
        if ($hasEmail && !$hasDocument) {
            $tenantByEmail = Tenant::where('email', mb_strtolower((string) $data['email']))->first();
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

        // Para documento, a consulta segue o tipo informado no formulário (cpf/cnpj).
        $tenant = $this->findTenantByIdentity($data);

        if (!$tenant) {
            // Quando não existe identidade na base, o onboarding pode iniciar normalmente.
            return response()->json([
                'exists' => false,
                'is_completed' => false,
                'can_continue' => true,
            ]);
        }

        // O status finalizado é inferido pelo timestamp de conclusão do onboarding.
        $isCompleted = !empty($tenant->onboarding_completed_at);

        // Se já concluiu, bloqueia continuidade; se está em rascunho, permite prosseguir.
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
        // Payload já chega com tipos e formatos consistentes por etapa.
        $data = $request->validated();

        // A etapa atual é usada para controle de progresso no tenant.
        $step = $data['step'];

        // O tenant sempre é resolvido por identidade (email/cnpj/cpf).
        $tenant = $this->findTenantByIdentity($data);

        if ($tenant && !empty($tenant->onboarding_completed_at)) {
            // Protege contra sobrescrita de cadastro que já finalizou provisionamento.
            return response()->json([
                'message' => 'Cadastro já finalizado para este tenant.',
            ], 409);
        }

        // Apenas campos oficiais do onboarding podem atualizar o tenant.
        $updatableData = $this->extractOnboardingUpdatableData($data);

        // Persistimos também em qual etapa o usuário parou.
        $updatableData['onboarding_current_step'] = $step;

        if ($tenant) {
            // Garante data de início somente no primeiro salvamento incremental.
            if (empty($tenant->onboarding_started_at)) {
                $updatableData['onboarding_started_at'] = now();
            }

            // Atualização incremental da etapa atual.
            $tenant->update($updatableData);
        } else {
            // Criação de rascunho: permite retomada sem provisionar infraestrutura.
            $tenant = $this->tenantRepository->create(array_merge($this->buildDraftTenantDefaults($data), $updatableData, [
                'onboarding_started_at' => now(),
            ]));
        }

        // Objetivos são relacionais; salvamos no step para manter progresso persistido.
        if ($step === 'goal' && array_key_exists('main_goals', $data)) {
            $this->replaceTenantMainGoals($tenant, $data['main_goals'] ?? []);
        }

        // Retorna identificador para o frontend manter continuidade do fluxo.
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
        // Na finalização também usamos somente dados já validados pelo Request.
        $data = $request->validated();

        // A finalização sempre resolve tenant por identidade.
        $tenant = $this->findTenantByIdentity($data);

        if (!$tenant) {
            // Sem tenant resolvido não há como seguir com provisionamento.
            return response()->json([
                'message' => 'Não foi possível localizar o tenant para finalização.',
            ], 404);
        }

        if (!empty($tenant->onboarding_completed_at)) {
            // Impede provisionamento duplicado para o mesmo cadastro finalizado.
            return response()->json([
                'message' => 'Cadastro já finalizado para este tenant.',
            ], 409);
        }

        /**
         * Consolida dados da última etapa antes de qualquer provisionamento.
         */
        $this->updateTenantFinalStepData($tenant, $data);

        /**
         * Não permite finalizar se outro tenant já concluído usar a mesma identidade.
         */
        $this->assertNoCompletedConflicts($tenant);

        if (array_key_exists('main_goals', $data)) {
            /**
             * Se vier goals no payload final, substitui o conjunto atual.
             */
            $this->replaceTenantMainGoals($tenant, $data['main_goals'] ?? []);
        }

        /**
         * Calcula domínio, token e dados técnicos necessários para instalação.
         */
        $provisioningData = $this->buildProvisioningData($tenant, $data, $request);

        /**
         * Persiste os dados técnicos antes de chamar o fluxo de provisionamento.
         */
        $this->applyProvisioningData($tenant, $provisioningData);

        /**
         * Garante plano base antes da execução do provisionamento principal.
         */
        $this->tenantInitialTrialPlanService->ensureForTenant($tenant);

        /**
         * Dispara todas as etapas pendentes e guarda retorno operacional para resposta da API.
         */
        $provisioningResult = $this->runProvisioningUntilCompleted($tenant);

        /**
         * Marca onboarding como concluído somente após processar provisionamento.
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

        // Priorizamos tenants concluídos para não permitir bypass de bloqueio.
        $query = Tenant::orderByDesc('onboarding_completed_at')->orderByDesc('id');

        // Obtém o tipo de documento
        $documentType = (string) ($data['document_type'] ?? '');

        // Obtém o documento que deseja ser validado
        $documentValue = $documentType === 'cpf' ? (string) ($data['cpf'] ?? '') : (string) ($data['cnpj'] ?? '');

        // Obtém a coluna que será buscada
        $column = $documentType === 'cpf' ? 'cpf' : 'cnpj';

        // Retorna resultados
        return $query->where($column, $documentValue)->first();

    }

    /**
     * Filtra campos de onboarding permitidos para atualização do tenant.
     * Centraliza saneamento de email, documentos e telefone.
     */
    private function extractOnboardingUpdatableData(array $data): array
    {
        // Whitelist explícita para proteger contra mass assignment acidental.
        $allowedFields = [
            'name',
            'email',
            'whatsapp',
            'company',
            'cnpj',
            'cpf',
            'company_profile',
            'company_zip_code',
            'company_city_state',
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

        // Remove qualquer chave extra que não pertença ao contrato do onboarding.
        return array_intersect_key($data, array_flip($allowedFields));
    }

    /**
     * Define valores mínimos para criação de tenant em rascunho.
     * Gera domínio temporário e token inicial para o cadastro.
     */
    private function buildDraftTenantDefaults(array $data): array
    {
        // Nome padrão evita falha quando o payload vier sem identificação textual.
        $name = (string) ($data['name'] ?? 'Cadastro em andamento');

        // Company usa name como fallback para manter regra de domínio consistente.
        $company = (string) ($data['company'] ?? $name ?: 'Cadastro em andamento');

        // Slug serve de base para domínio temporário de rascunho.
        $draftSlug = Str::slug($company ?: $name);

        if ($draftSlug === '') {
            // Fallback quando nome/empresa vier vazio ou com caracteres inválidos.
            $draftSlug = 'tenant-' . Str::lower(Str::random(8));
        }

        return [
            // Dados mínimos para persistir tenant em estado de onboarding.
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
        // Só conflita contra cadastros já concluídos, não contra rascunhos.
        $conflictQuery = Tenant::whereNotNull('onboarding_completed_at')
            ->where('id', '!=', $tenant->id);

        // A identidade pode conflitar por email, CNPJ ou CPF.
        $conflictQuery->where(function ($query) use ($tenant) {
            if (!empty($tenant->email)) {
                // Comparação de email em lowercase para evitar falso-negativo.
                $query->orWhere('email', mb_strtolower((string) $tenant->email));
            }
            if (!empty($tenant->cnpj)) {
                $query->orWhere('cnpj', onlyNumbers((string) $tenant->cnpj));
            }
            if (!empty($tenant->cpf)) {
                $query->orWhere('cpf', onlyNumbers((string) $tenant->cpf));
            }
        });

        if ($conflictQuery->exists()) {
            // Retorno 409 explicita conflito de negócio para o consumidor da API.
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
        // Aproveita o mesmo filtro de campos usado no salvamento incremental.
        $updatableData = $this->extractOnboardingUpdatableData($data);

        // A etapa final sempre precisa ser address para refletir fluxo concluído.
        $updatableData['onboarding_current_step'] = 'address';

        if (empty($tenant->onboarding_started_at)) {
            // Segurança para cadastros antigos sem timestamp inicial.
            $updatableData['onboarding_started_at'] = now();
        }

        // Persiste snapshot final de dados antes do provisionamento técnico.
        $tenant->update($updatableData);
    }

    /**
     * Substitui os objetivos principais do tenant pelos selecionados no fluxo.
     * Garante persistência relacional consistente por finalização.
     */
    private function replaceTenantMainGoals(Tenant $tenant, array $mainGoals): void
    {
        // O conjunto é substituído por completo para evitar objetivos obsoletos.
        $tenant->mainGoals()->delete();

        if (empty($mainGoals)) {
            // Sem objetivos selecionados, mantém relacionamento vazio.
            return;
        }

        // Cria objetivos válidos como registros relacionais independentes.
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
     * Persiste domínio/token e dados de provisionamento do tenant.
     * Cria runtime status quando ainda não existir registro técnico.
     */
    private function applyProvisioningData(Tenant $tenant, array $provisioningData): void
    {
        if (empty($tenant->token)) {
            /**
             * Mantém token existente quando já houver integração ativa.
             */
            $tenant->token = $provisioningData['token'];
        }

        $tenant->save();

        TenantDomain::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'auto_generate' => true,
            ],
            [
                'domain' => $provisioningData['domain'],
                'description' => 'Domínio cadastrado ao finalizar onboarding pela API',
                'status' => true,
            ]
        );

        if ($tenant->provisioning) {
            /**
             * Em retentativa de finalização, atualiza dados técnicos existentes.
             */
            $tenant->provisioning()->update($provisioningData['provisioning']);
        } else {
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
