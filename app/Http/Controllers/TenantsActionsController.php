<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TenantRuntimeStatus;
use App\Models\Module;
use App\Models\ModuleCategory;
use App\Models\Resource;
use App\Models\ScheduledTaskDispatch;
use App\Models\ScheduledTaskDispatchItem;
use App\Services\GuzzleService;
use App\Services\TenantUpdateNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use App\Jobs\RefreshTokenIntegrationsJob;

class TenantsActionsController extends Controller
{

    protected $request;
    private $repository;
    protected $guzzleService;

    public function __construct(
        Request $request,
        Tenant $content,
        GuzzleService $guzzleService,
        private TenantUpdateNotificationService $tenantUpdateNotificationService
    )
    {
        $this->request = $request;
        $this->repository = $content;
        $this->guzzleService = $guzzleService;
    }

    private function runtimeStatusFor(Tenant $tenant): TenantRuntimeStatus
    {
        $runtimeStatus = $tenant->runtimeStatus()->first();

        if (!$runtimeStatus) {
            throw new RuntimeException("Runtime status não encontrado para o cliente {$tenant->id}.");
        }

        return $runtimeStatus;
    }

    private function runtimeStatusSnapshot(TenantRuntimeStatus $runtimeStatus): array
    {
        return [
            'db_last_version'   => $runtimeStatus->db_last_version ? 1 : 0,
            'db_error'          => $runtimeStatus->db_error,
            'git_last_version'  => $runtimeStatus->git_last_version ? 1 : 0,
            'git_error'         => $runtimeStatus->git_error,
            'sp_last_version'   => $runtimeStatus->sp_last_version ? 1 : 0,
            'sp_error'          => $runtimeStatus->sp_error,
            'js_last_version'   => $runtimeStatus->js_last_version ? 1 : 0,
            'js_error'          => $runtimeStatus->js_error,
        ];
    }

    private function shouldReturnJson(Request $request): bool
    {
        return $request->ajax() || $request->wantsJson();
    }

    private function tenantApiRawError(array $response): string
    {
        return $response['data'] ?? $response['message'] ?? $response ?? 'Erro desconhecido';
    }

    /**
     * Atualiza todos os bancos de dados via API
     */
    public function updateAllDatabase()
    {

        /**
         * Obtém todos os clientes
         */
        $clientsId = $this->repository->all();
        
        /**
         * Sinaliza todos como desatualizados
         */
        TenantRuntimeStatus::query()->update(['db_last_version' => false]);
        
        /**
         * Contador de erros
         */
        $errors = 0;

        /**
         * Total de clientes
         */
        $totalTenants = count($clientsId);

        /**
         * Loop para percorrer todos os clientes
         */
        foreach ($clientsId as $tenant) {
            /**
             * Se a atualização retornar false incrementa o contador de erros
             */
            if ($this->updateDatabase($tenant->id)) {
                $errors++;
            }
        }

        /**
         * Define a mensagem final com base no número de erros
         */
        $message = $errors > 0
        ? "$errors de $totalTenants cliente(s) apresentaram erro(s) durante a atualização."
        : 'Bancos de dados atualizados com sucesso';

        /**
         * Redireciona com a mensagem final
         */
        return redirect()
            ->route('index')
            ->with('message', $message);
    }

    /**
     * Atualiza todos os sistemas (banco de dados e git)
     * de todos os clientes via API
     */
    public function updateAllSystems(Request $request)
    {
        $data = $request->all();

        /**
         * Define explicitamente quais ações podem ser recebidas da UI.
         * Qualquer valor fora dessa lista é descartado para manter o fluxo seguro.
         */
        $allowedActions = ['git', 'database', 'supervisor', 'npm_build'];
        $requestedActions = $data['actions'] ?? [];

        if (!is_array($requestedActions)) {
            $requestedActions = [];
        }

        $selectedActions = collect($requestedActions)
            ->filter(function ($action) use ($allowedActions) {
                return in_array($action, $allowedActions, true);
            })
            ->values()
            ->all();

        $updateScope = $data['update_scope'] ?? null;
        $selectedTenantId = $data['tenant_id'] ?? null;

        /**
         * Se nenhuma ação válida vier no payload:
         * - responde JSON 422 para chamadas AJAX
         * - mantém redirect com flash para chamadas web comuns
         */
        if (empty($selectedActions)) {
            return $this->invalidUpdateSystemsRequest(
                $request,
                'Selecione ao menos uma ação para atualizar os sistemas.'
            );
        }

        /**
         * O escopo é obrigatório para evitar o comportamento antigo de atualizar
         * todos os ambientes sem o usuário escolher o alvo.
         */
        if (!in_array($updateScope, ['all', 'individual', 'shared'], true)) {
            return $this->invalidUpdateSystemsRequest(
                $request,
                'Selecione qual sistema atualizar.'
            );
        }

        /**
         * Atualização individual só aceita tenant dedicado.
         */
        if ($updateScope === 'individual') {
            if (!$selectedTenantId) {
                return $this->invalidUpdateSystemsRequest(
                    $request,
                    'Selecione o sistema individual.'
                );
            }

            $selectedTenant = $this->repository
                ->where('type_installation', 'dedicated')
                ->find($selectedTenantId);

            if (!$selectedTenant) {
                return $this->invalidUpdateSystemsRequest(
                    $request,
                    'Sistema individual não encontrado.'
                );
            }

            $selectedTenantId = $selectedTenant->id;
        }

        $selectedActionLabels = $this->selectedActionLabels($selectedActions);
        $successMessage = 'Processo concluído para: ' . $selectedActionLabels . '.';

        /**
         * No fluxo AJAX, devolve resposta imediata e agenda processamento no
         * ciclo de término da requisição para liberar a UI sem bloqueio.
         */
        if ($this->shouldReturnJson($request)) {
            app()->terminating(function () use ($selectedActions, $updateScope, $selectedTenantId) {
                $this->processAllSystemsUpdate($selectedActions, $updateScope, $selectedTenantId);
            });

            return response()->json([
                'success' => true,
                'message' => 'Processo iniciado para: ' . $selectedActionLabels . '.',
                'selected_actions' => $selectedActions,
                'update_scope' => $updateScope,
                'tenant_id' => $selectedTenantId,
            ]);
        }

        /**
         * No fluxo não-AJAX mantém comportamento síncrono tradicional.
         */
        $this->processAllSystemsUpdate($selectedActions, $updateScope, $selectedTenantId);

        /**
         * Redireciona com a mensagem final
         */
        return redirect()
            ->route('tenants.index')
            ->with('message', $successMessage);
    }

    private function invalidUpdateSystemsRequest(Request $request, string $message)
    {
        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'success' => false,
                'message' => $message,
            ], 422);
        }

        return redirect()
            ->route('tenants.index')
            ->with('type', 'warning')
            ->with('message', $message);
    }

    private function selectedActionLabels(array $selectedActions): string
    {
        /**
         * Tradução de identificadores técnicos para nomes de exibição no retorno.
         */
        $actionLabels = [
            'git' => 'Git pull',
            'database' => 'banco de dados',
            'supervisor' => 'reinício de filas',
            'npm_build' => 'build de Javascript',
        ];

        return collect($selectedActions)
            ->map(function ($action) use ($actionLabels) {
                return $actionLabels[$action] ?? $action;
            })
            ->implode(', ');
    }

    /**
     * Adiciona uma falha ao resumo somente quando a ação retornou erro.
     * O runtime status é recarregado para pegar a mensagem persistida pela ação.
     */
    private function collectUpdateFailure(
        array &$failures,
        Tenant $tenant,
        string $action,
        bool $success,
        array $extra = []
    ): void {
        /**
         * Ações concluídas com sucesso não entram no e-mail de alerta.
         */
        if ($success) {
            return;
        }

        /**
         * Usa o erro salvo no banco para manter o alerta igual ao status da tela.
         */
        $runtimeStatus = $this->runtimeStatusFor($tenant)->refresh();
        $failures[] = array_merge(
            $this->buildUpdateFailure($tenant, $runtimeStatus, $action),
            $extra
        );
    }

    /**
     * Normaliza os dados que serão usados no corpo do e-mail de alerta.
     * O domínio principal ajuda a identificar rapidamente qual instalação falhou.
     */
    private function buildUpdateFailure(Tenant $tenant, TenantRuntimeStatus $runtimeStatus, string $action): array
    {
        /**
         * Evita consulta repetida quando o domínio já foi carregado no lote.
         */
        $tenant->loadMissing('domains');
        $primaryDomain = $tenant->domains->first();
        $errorColumn = $this->updateFailureErrorColumn($action);

        return [
            'tenant_id' => $tenant->id,
            'tenant_name' => $tenant->name,
            'installation_type' => $tenant->type_installation,
            'domain' => $primaryDomain?->domain ?? '-',
            'action' => $action,
            'error' => $runtimeStatus->{$errorColumn} ?: 'Erro desconhecido',
        ];
    }

    /**
     * Resolve qual coluna guarda a mensagem de erro de cada ação técnica.
     * O alerta usa essas mesmas colunas que alimentam a listagem dos tenants.
     */
    private function updateFailureErrorColumn(string $action): string
    {
        $columns = [
            'database' => 'db_error',
            'git' => 'git_error',
            'supervisor' => 'sp_error',
            'npm_build' => 'js_error',
        ];

        return $columns[$action] ?? 'git_error';
    }

    private function processAllSystemsUpdate(array $selectedActions, string $updateScope, $selectedTenantId = null): void
    {
        /**
         * Mapeia as flags de execução para manter o fluxo explícito e legível.
         */
        $shouldUpdateGit = in_array('git', $selectedActions, true);
        $shouldUpdateDatabase = in_array('database', $selectedActions, true);
        $shouldRestartSupervisor = in_array('supervisor', $selectedActions, true);
        $shouldBuildJavascript = in_array('npm_build', $selectedActions, true);

        /**
         * Guarda as falhas encontradas durante toda a execução para enviar
         * um único resumo no final, em vez de disparar um e-mail por tenant.
         */
        $updateFailures = [];

        /**
         * Obtém todos os clientes
         */
        $clients = $this->repository->all();

        /**
         * Define os tenants afetados pelo escopo escolhido no modal.
         * O escopo "all" mantém a coleção completa.
         */
        if ($updateScope === 'individual') {
            $clients = $clients->filter(function($tenant) use ($selectedTenantId) {
                return $tenant->id == $selectedTenantId && $tenant->type_installation == 'dedicated';
            });
        }

        if ($updateScope === 'shared') {
            $clients = $clients->filter(function($tenant) {
                return $tenant->type_installation == 'shared';
            });
        }

        if ($clients->isEmpty()) {
            return;
        }

        /**
         * Carrega os domínios uma vez para o resumo não gerar consultas extras.
         */
        $clients->load('domains');
        
        /**
         * Sinaliza como desatualizado somente os eixos solicitados para a UI
         * conseguir refletir progresso gradativo durante o processamento.
         */
        if ($shouldUpdateDatabase || $shouldUpdateGit || $shouldRestartSupervisor || $shouldBuildJavascript) {
            $updateColumns = [];

            if ($shouldUpdateDatabase) {
                $updateColumns['db_last_version'] = false;
            }

            if ($shouldUpdateGit) {
                $updateColumns['git_last_version'] = false;
            }

            if ($shouldRestartSupervisor) {
                $updateColumns['sp_last_version'] = false;
            }

            if ($shouldBuildJavascript) {
                $updateColumns['js_last_version'] = false;
            }

            if (!empty($updateColumns)) {
                $affectedTenantIds = [];

                foreach ($clients as $tenant) {
                    $affectedTenantIds[] = $tenant->id;
                }

                TenantRuntimeStatus::whereIn('tenant_id', $affectedTenantIds)->update($updateColumns);
            }
        }

        /**
         * Obtém todos os clientes com instalações dedicadas
         */
        $clientsDedicateds = $clients->filter(function($tenant) {
            return $tenant->type_installation == 'dedicated';
        });

        /**
         * Atualiza sistemas das instalações dedicadas.
         */
        foreach ($clientsDedicateds as $tenant) {
            if ($shouldUpdateGit) {
                $updated = $this->updateGit($tenant->id);

                /**
                 * Se o Git falhar, registra o erro retornado pela API do tenant.
                 */
                $this->collectUpdateFailure($updateFailures, $tenant, 'git', $updated);
            }

            if ($shouldRestartSupervisor) {
                $updated = $this->restartSupervisor($tenant->id);

                /**
                 * Se o supervisor falhar, registra o erro salvo em sp_error.
                 */
                $this->collectUpdateFailure($updateFailures, $tenant, 'supervisor', $updated);
            }

            if ($shouldBuildJavascript) {
                $updated = $this->runNpmBuild($tenant->id);

                /**
                 * Se o build falhar, registra o erro salvo em js_error.
                 */
                $this->collectUpdateFailure($updateFailures, $tenant, 'npm_build', $updated);
            }
        }

        /**
         * Busca um cliente compartilhado para aplicar operações compartilhadas.
         */
        $sharedTenant = $clients->first(function($tenant) {
            return $tenant->type_installation == 'shared';
        });

        /**
         * Para instalação compartilhada executa no tenant base e replica o status
         * final para os demais compartilhados, mantendo consistência visual.
         */
        if ($sharedTenant) {
            /**
             * Usado apenas no resumo para indicar quantos tenants receberam
             * o status replicado da ação executada no tenant compartilhado base.
             */
            $sharedTenantsCount = $clients->where('type_installation', 'shared')->count();

            if ($shouldUpdateGit) {
                /**
                 * Verifica se o cliente compartilhado foi atualizado com sucesso.
                 */
                $updated = $this->updateGit($sharedTenant->id);

                /**
                 * No shared, a falha é reportada uma vez no tenant base.
                 */
                $this->collectUpdateFailure($updateFailures, $sharedTenant, 'git', $updated, [
                    'shared_replicated_count' => $sharedTenantsCount,
                ]);

                /**
                 * Atualiza o git de todas as hospedagens compartilhadas.
                 */
                $sharedRuntimeStatus = $this->runtimeStatusFor($sharedTenant)->refresh();
                foreach ($clients->where('type_installation', 'shared') as $tenant) {
                    $this->runtimeStatusFor($tenant)->update([
                        'git_last_version' => $sharedRuntimeStatus->git_last_version,
                        'git_error' => $sharedRuntimeStatus->git_error,
                    ]);
                }
            }

            if ($shouldRestartSupervisor) {
                /**
                 * Verifica se o restart de filas no cliente compartilhado foi concluído.
                 */
                $updated = $this->restartSupervisor($sharedTenant->id);

                /**
                 * No shared, a falha é reportada uma vez no tenant base.
                 */
                $this->collectUpdateFailure($updateFailures, $sharedTenant, 'supervisor', $updated, [
                    'shared_replicated_count' => $sharedTenantsCount,
                ]);

                /**
                 * Atualiza o status do supervisor em todas as hospedagens compartilhadas.
                 */
                $sharedRuntimeStatus = $this->runtimeStatusFor($sharedTenant)->refresh();
                foreach ($clients->where('type_installation', 'shared') as $tenant) {
                    $this->runtimeStatusFor($tenant)->update([
                        'sp_last_version' => $sharedRuntimeStatus->sp_last_version,
                        'sp_error' => $sharedRuntimeStatus->sp_error,
                    ]);
                }
            }

            if ($shouldBuildJavascript) {
                $updated = $this->runNpmBuild($sharedTenant->id);

                /**
                 * No shared, a falha é reportada uma vez no tenant base.
                 */
                $this->collectUpdateFailure($updateFailures, $sharedTenant, 'npm_build', $updated, [
                    'shared_replicated_count' => $sharedTenantsCount,
                ]);

                /**
                 * O build em ambiente compartilhado roda uma vez e o resultado
                 * visual precisa aparecer em todos os tenants compartilhados.
                 */
                $sharedRuntimeStatus = $this->runtimeStatusFor($sharedTenant)->refresh();
                foreach ($clients->where('type_installation', 'shared') as $tenant) {
                    $this->runtimeStatusFor($tenant)->update([
                        'js_last_version' => $sharedRuntimeStatus->js_last_version,
                        'js_error' => $sharedRuntimeStatus->js_error,
                    ]);
                }
            }
        }
        
        /**
         * Atualização de banco segue tenant a tenant por depender do contexto
         * individual de migrations pendentes.
         */
        if ($shouldUpdateDatabase) {
            /**
             * Loop para percorrer todos os clientes quando banco foi selecionado.
             */
            foreach ($clients as $tenant) {
                $updated = $this->updateDatabase($tenant->id);

                /**
                 * Banco é individual mesmo em hospedagem compartilhada.
                 */
                $this->collectUpdateFailure($updateFailures, $tenant, 'database', $updated);
            }
        }

        /**
         * Envia o alerta depois de todas as ações e replicações.
         * Sem falhas ou sem destinatários, o serviço apenas encerra/loga.
         */
        $this->tenantUpdateNotificationService->notifyFailures([
            'scope' => $updateScope,
            'actions' => $selectedActions,
            'total_tenants' => $clients->count(),
            'selected_tenant_id' => $selectedTenantId,
        ], $updateFailures);
    }

    /**
     * Atualiza o banco de dados do cliente via API
     */
    public function updateDatabase($id){

        /**
         * Encontra o cliente
         */
        $tenant = $this->repository->find($id);
        $runtimeStatus = $this->runtimeStatusFor($tenant);

        /**
         * Realiza solicitação
         */
        $response = $this->guzzleService->request('POST', 'sistema/atualizar-banco', $tenant);

        /**
         * Verifica a resposta antes de tentar acessar as chaves
         */
        if (!$response['success']) {
            /**
             * Registra a mensagem de erro
             */
            $runtimeStatus->db_last_version = false;
            $runtimeStatus->db_error = $this->tenantApiRawError($response);
        } else {
            /**
             * Atualiza db_last_version
             */
            $runtimeStatus->db_last_version = true;
            $runtimeStatus->db_error = null;
        }

        /**
         * Atualiza no banco de dados
         */
        $runtimeStatus->save();

        /**
         * Retorna a página
         */
        return $runtimeStatus->db_last_version;

    }
    
    /**
     * Atualiza o Git do cliente via API
     */
    public function updateGit($id){

        /**
         * Encontra o cliente
         */
        $tenant = $this->repository->find($id);
        $runtimeStatus = $this->runtimeStatusFor($tenant);

        /**
         * Realiza solicitação
         */
        $response = $this->guzzleService->request('POST', 'sistema/atualizar-git', $tenant);

        Log::info($response);

        /**
         * Verifica a resposta antes de tentar acessar as chaves
         */
        if (!$response['success']) {
            $runtimeStatus->git_last_version = false;
            $runtimeStatus->git_error = $this->tenantApiRawError($response);
        } else {
            $runtimeStatus->git_last_version = true;
            $runtimeStatus->git_error = null;
        }

        /**
         * Atualiza no banco de dados
         */
        $runtimeStatus->save();

        /**
         * Retorna a página
         */
        return $runtimeStatus->git_last_version;

    }

    /**
     * Reinicia as filas do cliente via API
     */
    public function restartSupervisor($id){

        /**
         * Encontra o cliente
         */
        $tenant = $this->repository->find($id);
        $runtimeStatus = $this->runtimeStatusFor($tenant);

        /**
         * Realiza solicitação
         */
        $response = $this->guzzleService->request('POST', 'sistema/supervisor-restart', $tenant);

        if (!$response['success']) {
            $runtimeStatus->sp_last_version = false;
            $runtimeStatus->sp_error = $this->tenantApiRawError($response);
        } else {
            $responseData = json_decode($response['data'] ?? null, true);
            $apiSuccess = true;

            if (is_array($responseData) && array_key_exists('success', $responseData)) {
                $apiSuccess = $responseData['success'] === true;
            }

            if (!$apiSuccess) {
                $runtimeStatus->sp_last_version = false;
                $runtimeStatus->sp_error = $response['data'] ?? 'Erro desconhecido';
            } else {
                $runtimeStatus->sp_last_version = true;
                $runtimeStatus->sp_error = null;
            }
        }

        /**
         * Atualiza no banco de dados
         */
        $runtimeStatus->save();

        /**
         * Retorna a página
         */
        return $runtimeStatus->sp_last_version;

    }

    /**
     * Atualiza o banco de dados do cliente via API
     */
    public function updateDatabaseManual(Request $request, $id){

        /**
         * Encontra o cliente
         */
        $tenant = $this->repository->find($id);

        /**
         * Realiza solicitação
         */
        $updated = $this->updateDatabase($tenant->id);
        $runtimeStatus = $this->runtimeStatusFor($tenant)->refresh();

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'success' => $updated,
                'message' => $updated ? 'Migração executada com sucesso' : 'Falha ao atualizar banco de dados',
                'status' => $this->runtimeStatusSnapshot($runtimeStatus),
            ], $updated ? 200 : 422);
        }

        /**
         * Retorna a página
         */
        return redirect()
                ->route('tenants.index')
                ->with('message', $updated ? 'Migração executada com sucesso' : 'Falha ao atualizar banco de dados');

    }

    /**
     * Reseta plano, assinaturas, pedidos e transações do tenant no core_business.
     * Disponível apenas em ambiente de testes.
     */
    public function removePackagesManual(Request $request, $id)
    {
        if (!app()->environment(['local', 'testing'])) {
            abort(403, 'Ação permitida apenas em ambiente de testes.');
        }

        $tenant = $this->repository->findOrFail($id);

        DB::transaction(function () use ($tenant) {
            $subscriptionIds = DB::table('subscriptions')
                ->where('tenant_id', $tenant->id)
                ->pluck('id')
                ->all();

            $planIds = DB::table('tenants_plans')
                ->where('tenant_id', $tenant->id)
                ->pluck('id')
                ->all();

            $orderIds = DB::table('orders')
                ->where('tenant_id', $tenant->id)
                ->pluck('id')
                ->all();

            if (!empty($orderIds)) {
                DB::table('orders_transactions')->whereIn('order_id', $orderIds)->delete();
            }

            if (!empty($subscriptionIds)) {
                DB::table('subscriptions_cycles')->whereIn('subscription_id', $subscriptionIds)->delete();
            }

            DB::table('orders')->where('tenant_id', $tenant->id)->delete();
            DB::table('subscriptions')->where('tenant_id', $tenant->id)->delete();

            if (!empty($planIds)) {
                DB::table('tenants_plans_items')->whereIn('plan_id', $planIds)->delete();
            }

            DB::table('tenants_plans')->where('tenant_id', $tenant->id)->delete();
        });

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'success' => true,
                'message' => 'Plano, assinaturas e pedidos resetados com sucesso.',
            ]);
        }

        return redirect()
            ->route('tenants.index')
            ->with('message', 'Plano, assinaturas e pedidos resetados com sucesso.');
    }

    /**
     * Atualiza os arquivos do cliente via API
     */
    public function updateGitManual(Request $request, $id){

        /**
         * Encontra o cliente
         */
        $tenant = $this->repository->find($id);

        /**
         * Realiza solicitação
         */
        $updated = $this->updateGit($tenant->id);

        /**
         * Em hospedagem compartilhada o Git é único, então o status precisa
         * ser refletido em todos os tenants compartilhados.
         */
        if ($tenant->type_installation === 'shared') {
            $sharedRuntimeStatus = $this->runtimeStatusFor($tenant)->refresh();
            $sharedTenants = $this->repository->where('type_installation', 'shared')->get();

            foreach ($sharedTenants as $sharedTenant) {
                $this->runtimeStatusFor($sharedTenant)->update([
                    'git_last_version' => $sharedRuntimeStatus->git_last_version,
                    'git_error' => $sharedRuntimeStatus->git_error,
                ]);
            }
        }

        $runtimeStatus = $this->runtimeStatusFor($tenant)->refresh();

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'success' => $updated,
                'message' => $updated ? 'GIT Pull executado com sucesso' : 'Falha ao atualizar git',
                'status' => $this->runtimeStatusSnapshot($runtimeStatus),
                'shared_status_updated' => $tenant->type_installation === 'shared',
                'shared_action_type' => $tenant->type_installation === 'shared' ? 'git' : null,
            ], $updated ? 200 : 422);
        }

        /**
         * Retorna a página
         */
        return redirect()
                ->route('tenants.index')
                ->with('message', $updated ? 'GIT Pull executado com sucesso' : 'Falha ao atualizar git');

    }

    /**
     * Reinicia as filas do cliente via API
     */
    public function updateSupervisorManual(Request $request, $id){

        /**
         * Encontra o cliente
         */
        $tenant = $this->repository->find($id);

        /**
         * Realiza solicitação
         */
        $updated = $this->restartSupervisor($tenant->id);

        /**
         * Mantém o status sincronizado para todos os clientes compartilhados.
         */
        if ($tenant->type_installation === 'shared') {
            $sharedRuntimeStatus = $this->runtimeStatusFor($tenant)->refresh();
            $sharedTenants = $this->repository->where('type_installation', 'shared')->get();

            foreach ($sharedTenants as $sharedTenant) {
                $this->runtimeStatusFor($sharedTenant)->update([
                    'sp_last_version' => $sharedRuntimeStatus->sp_last_version,
                    'sp_error' => $sharedRuntimeStatus->sp_error,
                ]);
            }
        }

        $runtimeStatus = $this->runtimeStatusFor($tenant)->refresh();

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'success' => $updated,
                'message' => $updated ? 'Filas reiniciadas com sucesso' : 'Falha ao reiniciar as filas',
                'status' => $this->runtimeStatusSnapshot($runtimeStatus),
                'shared_status_updated' => $tenant->type_installation === 'shared',
                'shared_action_type' => $tenant->type_installation === 'shared' ? 'sp' : null,
            ], $updated ? 200 : 422);
        }

        /**
         * Retorna a página
         */
        return redirect()
                ->route('tenants.index')
                ->with('message', $updated ? 'Filas reiniciadas com sucesso' : 'Falha ao reiniciar as filas');

    }

    /**
     * Executa npm build do cliente via API
     */
    public function runNpmBuild($id)
    {
        /**
         * Encontra o cliente
         */
        $tenant = $this->repository->find($id);
        $runtimeStatus = $this->runtimeStatusFor($tenant);

        /**
         * Realiza solicitação com timeout maior pois build pode demorar.
         */
        $response = $this->guzzleService->request('POST', 'sistema/npm-build', $tenant, null, [
            'connect_timeout' => 10,
            'timeout' => 1200,
        ]);

        if (!$response['success']) {
            $runtimeStatus->js_last_version = false;
            $runtimeStatus->js_error = $this->tenantApiRawError($response);
            $runtimeStatus->save();

            Log::warning('Falha ao executar npm build no cliente.', [
                'tenant_id' => $tenant->id,
                'client_name' => $tenant->name,
                'message' => $runtimeStatus->js_error,
            ]);

            return false;
        }

        $responseData = json_decode($response['data'] ?? null, true);
        $apiSuccess = true;

        /**
         * Quando a API do tenant retorna JSON, respeitamos o campo success.
         * Sem JSON válido, mantemos sucesso porque a chamada HTTP já funcionou.
         */
        if (is_array($responseData) && array_key_exists('success', $responseData)) {
            $apiSuccess = $responseData['success'] == true;
        }

        if (!$apiSuccess) {
            $runtimeStatus->js_last_version = false;
            $runtimeStatus->js_error = $response['data'] ?? 'Erro desconhecido';
            $runtimeStatus->save();

            Log::warning('API do tenant retornou erro no npm build.', [
                'tenant_id' => $tenant->id,
                'client_name' => $tenant->name,
                'error' => $runtimeStatus->js_error,
            ]);

            return false;
        }

        $runtimeStatus->js_last_version = true;
        $runtimeStatus->js_error = null;
        $runtimeStatus->save();

        return true;
    }

    /**
     * Executa npm build manualmente e retorna no mesmo contrato AJAX das demais ações.
     */
    public function runNpmBuildManual(Request $request, $id)
    {
        /**
         * Encontra o cliente para executar a ação e atualizar o status visual.
         */
        $tenant = $this->repository->find($id);
        $updated = $this->runNpmBuild($tenant->id);

        /**
         * Em hospedagem compartilhada o build é único, então replica o resultado
         * para manter a listagem coerente com Git e Supervisor.
         */
        if ($tenant->type_installation === 'shared') {
            $sharedRuntimeStatus = $this->runtimeStatusFor($tenant)->refresh();
            $sharedTenants = $this->repository->where('type_installation', 'shared')->get();

            foreach ($sharedTenants as $sharedTenant) {
                $this->runtimeStatusFor($sharedTenant)->update([
                    'js_last_version' => $sharedRuntimeStatus->js_last_version,
                    'js_error' => $sharedRuntimeStatus->js_error,
                ]);
            }
        }

        $runtimeStatus = $this->runtimeStatusFor($tenant)->refresh();

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'success' => $updated,
                'message' => $updated ? 'Build de Javascript executado com sucesso' : 'Falha ao buildar Javascript',
                'status' => $this->runtimeStatusSnapshot($runtimeStatus),
                'shared_status_updated' => $tenant->type_installation === 'shared',
                'shared_action_type' => $tenant->type_installation === 'shared' ? 'js' : null,
            ], $updated ? 200 : 422);
        }

        return redirect()
            ->route('tenants.index')
            ->with('message', $updated ? 'Build de Javascript executado com sucesso' : 'Falha ao buildar Javascript');
    }

    /**
     * Dispara manualmente um ou mais jobs para clientes selecionados na central.
     * Também grava o histórico do lote e separa quais jobs devem aguardar resposta.
     * Isso permite tratar jobs rápidos sem travar o envio dos jobs mais demorados.
     */
    public function runScheduledNow(Request $request, $id = null)
    {
        /**
         * Define os jobs que compõem o lote manual.
         */
        $jobs = $this->scheduledJobs();

        /**
         * Permite executar um job específico quando informado pela tela.
         */
        $selectedJob = $request->get('job');

        /**
         * Restringe a execução ao job solicitado quando ele for válido.
         */
        if (!empty($selectedJob) && $selectedJob !== 'all') {
            if (!in_array($selectedJob, $jobs, true)) {
                return redirect()
                    ->back()
                    ->with('type', 'error')
                    ->with('message', 'A tarefa selecionada é inválida.');
            }

            $jobs = [$selectedJob];
        }

        /**
         * Busca um cliente específico ou todos os clientes ativos.
         */
        if($id !== null){
            $clients = $this->repository->where('id', $id)->where('status', true)->get();
        } else {
            $clients = $this->repository->where('status', true)->get();
        }

        /**
         * Cria o registro pai para agrupar todas as execuções do clique manual.
         */
        $dispatch = ScheduledTaskDispatch::create([
            'job_name' => 'manual_batch',
            'job_data' => [
                'jobs' => $jobs,
                'target_tenant_id' => $id,
            ],
            'source' => 'manual',
            'dispatched_by' => Auth::id(),
            'total_clients' => $clients->count(),
            'success_count' => 0,
            'failure_count' => 0,
            'started_at' => now(),
        ]);

        /**
         * Mantém os totais consolidados do lote para a listagem principal.
         */
        $successCount = 0;
        $failureCount = 0;
        
        /**
         * Finaliza o lote vazio quando não existir cliente elegível.
         */
        if ($clients->isEmpty()) {
            $dispatch->update([
                'finished_at' => now(),
            ]);

            return redirect()
                ->back()
                ->with('type', 'warning')
                ->with('message', 'Nenhum cliente ativo encontrado para executar as tarefas.');
        }

        /**
         * Percorre os clientes e executa todos os jobs definidos no lote.
         * Cada combinação cliente + job gera um item filho no histórico.
         */
        foreach ($clients as $tenant) {
            foreach ($jobs as $jobName) {
                /**
                 * Marca o início do disparo individual para auditoria.
                 */
                $startedAt = now();

                /**
                 * O tenant sempre responde apenas com o aceite do disparo.
                 */
                $response = $this->guzzleService->request('post', 'sistema/processar-tarefa', $tenant, [
                    'job' => $jobName,
                    'data' => [],
                ], [
                    'timeout' => 5,
                ]);

                /**
                 * Converte a resposta para um resumo simples da execução.
                 */
                $success = ($response['success'] ?? false) === true;
                $message = $response['message'] ?? ($success ? 'Tarefa aceita para processamento.' : 'Falha ao aceitar tarefa para processamento.');

                /**
                 * Tenta reaproveitar a mensagem vinda do próprio cliente quando existir.
                 */
                if (!empty($response['data'])) {
                    $decodedResponse = json_decode($response['data'], true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decodedResponse) && !empty($decodedResponse['message'])) {
                        $message = $decodedResponse['message'];
                    }
                }

                /**
                 * Registra o item filho para rastrear esse cliente e job.
                 */
                ScheduledTaskDispatchItem::create([
                    'dispatch_id' => $dispatch->id,
                    'tenant_id' => $tenant->id,
                    'job_name' => $jobName,
                    'success' => $success,
                    'response_status_code' => $response['status_code'] ?? null,
                    'response_message' => $message,
                    'response_body' => $response['data'] ?? null,
                    'requested_at' => $startedAt,
                    'finished_at' => now(),
                ]);

                /**
                 * Atualiza os totais consolidados do lote conforme o resultado.
                 */
                if ($success) {
                    $successCount++;
                } else {
                    $failureCount++;
                }
            }
        }

        /**
         * Fecha o lote com os totais finais após processar todos os clientes.
         */
        $dispatch->update([
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'finished_at' => now(),
        ]);

        /**
         * Retorna para a tela anterior informando o identificador do lote criado.
         */
        return redirect()
                ->back()
                ->with('message', 'Lote #' . $dispatch->id . ' aceito para processamento em ' . $clients->count() . ' cliente(s).');
    }

    /**
     * Função responsável por atualizar os tokens das integrações cadastradas na central.
     */
    public function runIntegrationsNow($id = null)
    {
        if(empty($id)) {
            return redirect()
                ->back()
                ->with('type', 'error')
                ->with('message', 'Identificador da integração não informado.'); 
        }

        /**
         * Dispara o job de atualizaçao dos tokens de integracao
         */
        RefreshTokenIntegrationsJob::dispatch($id);

        /**
         * Retorna para a tela anterior informando o identificador do lote criado.
         */
        return redirect()
                ->back()
                ->with('message', 'Job de atualizaçao dos tokens de integracao disparado com sucesso.'); 
    }

    /**
     * Retorna os jobs liberados para disparo manual na tela de clientes.
     * A lista fica centralizada aqui para a validação do controller usar a mesma base.
     * Isso evita aceitar na URL um job que não foi previsto pela central.
     */
    private function scheduledJobs()
    {
        /**
         * Mantém a mesma lista configurada para o scheduler da central.
         */
        return [
            'finish_calls_24h',
            'finish_order_access',
            'update_s3_metrics',
            'archive_finished_tasks',
            'emails:process-actions',
            'email-inbox:import',
            'notify_commitments_10m',
            'import_cfop_table',
            'import_ncm_table',
        ];
    }

    /**
     * Obtém permissões do usuário
     */
    public function getResources(Request $request)
    {

        /**
         * Obtém dados do formulário
         */
        $data = $request->all();

        /**
         * Encontra o Tenante modelo 1
         */
        $tenant = $this->repository->where('type_installation', 'shared')->first();

        /**
         * Realiza solicitação
         */
        $categories = $this->guzzleService->request('post', 'sistema/permissoes-recursos', $tenant, $data);

        /**
         * Decodifica a resposta
         */
        $categories = json_decode($categories['data'], true);

        /**
         * Define o status de todos os registros como 0 antes da verificação.
         * Em seguida, verifica se a permissão recebida das rotas do Core
         * corresponde a algum registro existente na tabela de Recursos.
         * Se houver correspondência, o status será atualizado para 1.
         * Se o status permanecer 0, significa que o nome da permissão recebida
         * não corresponde a nenhum registro existente em Recursos.
         */
        Resource::where('status', true)->update([
            'status' => 0,
        ]);

        ModuleCategory::where('status', true)->update([
            'status' => 0,
        ]);

        Module::where('status', true)->update([
            'status' => 0,
        ]);

        /**
         * Armazena os IDs dos módulos retornados na sincronização atual.
         */
        $syncedModuleIds = [];

        /**
         * Faz looping pelas categorias
         */
        foreach ($categories as $category) {

            /**
             * Verifica se veio com pacote ou sem
             */
            if($category['name'] != 'Sem Pacote') {

                /**
                 * Cria a categoria no sistema
                 */
                $moduleCategory = ModuleCategory::updateOrCreate([
                    'slug' => $category['slug'], 
                ], [
                    'name' => $category['name'],
                    'status' => true,
                    'created_by' => Auth::id()
                ]);

                $categoryId = $moduleCategory->id;

            } else {
                $categoryId = null;
            }
            
            /**
             * Faz looping entre modulos
             */
            foreach ($category['modules'] as $key => $module) {

                /**
                 * Cria os módulos
                 */
                $modelModule = Module::updateOrCreate([
                    'slug' => $key,
                ], [
                    'name' => $module['name'],
                    'description' => $module['description'],
                    'module_category_id' => $categoryId,
                    'status' => true,
                    'created_by' => Auth::id()
                ]);

                $syncedModuleIds[] = $modelModule->id;

                /**
                 * Faz looping entre os recursos
                 */
                foreach ($module['resources'] as $resource) {

                    /**
                     * Busca um registro onde o campo 'name' seja igual a $permission.
                     * Se o registro existir, atualiza o campo 'status' para true.
                     * Se o registro não existir, cria um novo com 'name' = $permission
                     * e 'status' = true.
                     */
                    Resource::updateOrCreate(
                        [
                            'name' => $resource,
                            'module_id' => $modelModule->id,
                        ],
                        [
                            'status' => true,
                            'created_by' => Auth::id()
                        ]
                    );
                }

            }
        }

        /**
         * Desativa módulos não nativos que não vieram no payload atual da sincronização.
         */
        Module::where('is_native', false)
            ->whereNotIn('id', array_unique($syncedModuleIds))
            ->update([
                'status' => false,
            ]);
        
        /**
         * Retorna a página
         */
        return redirect()
        ->route('index')
        ->with('message', 'Permissões atualizadas com sucesso! Os recursos foram sincronizados com o sistema.');

    }

}
