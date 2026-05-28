<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TenantAppRoute;
use App\Models\TenantDomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class ApisDiscoveryController extends Controller
{
    /**
     * Localiza tenant por email, CNPJ ou CPF e retorna domínio ativo.
     * Mantém contrato de descoberta usado por integrações externas.
     */
    public function findTenant(Request $request): JsonResponse
    {
        $data = $request->all();

        /**
         * Busca por identidade para manter compatibilidade com integrações antigas.
         */
        $tenant = $this->findTenantByIdentity($data);

        /**
         * Interrompe quando nenhum tenant for encontrado pela identidade enviada.
         */
        if (!$tenant) {
            return response()->json(['message' => 'Não foi possível encontrar um cliente relacionado.'], 404);
        }

        $domain = $tenant->domains()->where('status', true)->first()?->domain;
        /**
         * Impede descoberta sem domínio ativo para o tenant encontrado.
         */
        if (!$domain) {
            return response()->json(['message' => 'Tenante encontrado, mas sem domínio ativo vinculado.'], 404);
        }

        return response()->json(['domain' => $domain]);
    }

    /**
     * Resolve credenciais de banco pelo domínio solicitado no endpoint.
     * Trata domínio órfão para evitar retorno técnico inconsistente.
     */
    public function getDatabase(Request $request): JsonResponse
    {
        $domain = $request->query('domain');
        /**
         * Exige domínio porque este endpoint resolve banco a partir do host web.
         */
        if (!$domain) {
            return response()->json(['error' => 'Domínio não fornecido.'], 400);
        }

        /**
         * Normaliza o domínio para reduzir falso-negativo entre host com e sem www.
         */
        $domain = str_replace('www.', '', $domain);
        $domain = TenantDomain::where('domain', $domain)->first();
        /**
         * Bloqueia quando a Central não reconhece o domínio informado.
         */
        if (!$domain) {
            return response()->json(['error' => 'Domínio não encontrado.'], 404);
        }

        $tenant = $domain->tenant;
        /**
         * Evita retornar credenciais quando o domínio estiver órfão na Central.
         */
        if (!$tenant) {
            return response()->json(['error' => 'Domínio sem cliente vinculado.'], 404);
        }

        return response()->json([
            'tenant' => $tenant->id,
            'db_name' => $tenant->provisioning?->table,
            'db_user' => $tenant->provisioning?->table_user,
            'db_password' => $tenant->provisioning?->table_password,
        ]);
    }

    /**
     * Resolve credenciais de banco pelo ID explícito do tenant.
     */
    public function getDatabaseByTenant(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'integer'],
        ]);

        /**
         * A busca por tenant_id substitui a descoberta por domínio usada no SaaS web.
         */
        $tenant = Tenant::with('provisioning')->find($data['tenant_id']);
        /**
         * Retorna erro quando o ID da empresa não existir na Central.
         */
        if (!$tenant) {
            return response()->json([
                'error' => 'Tenant não encontrado.',
            ], 404);
        }

        /**
         * Sem provisioning a Central não tem credenciais técnicas para entregar ao MiCore.
         */
        /**
         * Bloqueia tenants sem provisioning técnico vinculado.
         */
        if (!$tenant->provisioning) {
            return response()->json([
                'error' => 'Tenant sem provisioning vinculado.',
            ], 404);
        }

        /**
         * Banco e usuário são obrigatórios para aplicar conexão do tenant no app.
         */
        /**
         * Bloqueia provisioning que não tem banco ou usuário de conexão.
         */
        if (!$tenant->provisioning->table || !$tenant->provisioning->table_user) {
            return response()->json([
                'error' => 'Provisioning do tenant incompleto.',
            ], 422);
        }

        return response()->json([
            'tenant'      => $tenant->id,
            'db_name'     => $tenant->provisioning->table,
            'db_user'     => $tenant->provisioning->table_user,
            'db_password' => $tenant->provisioning->table_password,
        ]);
    }

    /**
     * Resolve como a App API deve atender o tenant informado.
     */
    public function getAppTenantResolution(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'integer'],
        ]);

        /**
         * A Central atua como registry; autenticação de usuário fica fora dela.
         */
        $tenant = Tenant::with(['appRoute', 'provisioning'])->find($data['tenant_id']);
        /**
         * Retorna erro quando o tenant informado pelo app não existe.
         */
        if (!$tenant) {
            return response()->json([
                'error' => 'Tenant não encontrado.',
            ], 404);
        }

        /**
         * Exige rota própria de aplicativo para decidir como atender o tenant.
         */
        if (!$tenant->appRoute) {
            return response()->json([
                'error' => 'Tenant sem rota de aplicativo vinculada.',
            ], 404);
        }

        /**
         * Bloqueia tenants desativados para uso pelos aplicativos.
         */
        if (!$tenant->appRoute->status || $tenant->appRoute->mode === TenantAppRoute::MODE_DISABLED) {
            return response()->json([
                'error' => 'Tenant bloqueado para aplicativos.',
            ], 403);
        }

        /**
         * Encaminha a resolução para o contrato de API externa do cliente.
         */
        if ($tenant->appRoute->mode === TenantAppRoute::MODE_REMOTE_API) {
            return $this->remoteApiResolution($tenant);
        }

        /**
         * Encaminha a resolução para o contrato de banco local/SaaS.
         */
        if ($tenant->appRoute->mode === TenantAppRoute::MODE_LOCAL_DATABASE) {
            return $this->localDatabaseResolution($tenant);
        }

        return response()->json([
            'error' => 'Modo de roteamento do tenant inválido.',
        ], 422);
    }

    /**
     * Monta resposta de roteamento para tenant atendido por API externa.
     */
    private function remoteApiResolution(Tenant $tenant): JsonResponse
    {
        $route = $tenant->appRoute;

        /**
         * Exige URL e token técnico para o centralizador chamar a API externa.
         */
        if (!$route->remote_base_url || !$route->remote_service_token) {
            return response()->json([
                'error' => 'Rota remota do tenant incompleta.',
            ], 422);
        }

        return response()->json([
            'tenant_id' => $tenant->id,
            'mode'      => TenantAppRoute::MODE_REMOTE_API,
            'api'       => [
                'base_url'      => $route->remote_base_url,
                'service_token' => Crypt::decryptString($route->remote_service_token),
            ],
        ]);
    }

    /**
     * Monta resposta de roteamento para tenant atendido por banco local/SaaS.
     */
    private function localDatabaseResolution(Tenant $tenant): JsonResponse
    {
        /**
         * Exige provisioning para resolver credenciais do banco local/SaaS.
         */
        if (!$tenant->provisioning) {
            return response()->json([
                'error' => 'Tenant sem provisioning vinculado.',
            ], 404);
        }

        /**
         * Exige banco e usuário para montar a resposta de conexão do tenant.
         */
        if (!$tenant->provisioning->table || !$tenant->provisioning->table_user) {
            return response()->json([
                'error' => 'Provisioning do tenant incompleto.',
            ], 422);
        }

        return response()->json([
            'tenant_id' => $tenant->id,
            'mode'      => TenantAppRoute::MODE_LOCAL_DATABASE,
            'database'  => [
                'name'     => $tenant->provisioning->table,
                'user'     => $tenant->provisioning->table_user,
                'password' => $tenant->provisioning->table_password,
            ],
        ]);
    }

    /**
     * Localiza tenant por identidade recebida em payload simples.
     * Preserva prioridade de busca usada no fluxo legado da API.
     */
    private function findTenantByIdentity(array $data): ?Tenant
    {
        /**
         * Usa e-mail como primeira prioridade de descoberta de tenant.
         */
        if (!empty($data['email'])) {
            $tenant = Tenant::where('email', $data['email'])->first();
            /**
             * Retorna imediatamente quando o e-mail encontrar um tenant.
             */
            if ($tenant) {
                return $tenant;
            }
        }

        /**
         * Usa CNPJ como segunda prioridade de descoberta de tenant.
         */
        if (!empty($data['cnpj'])) {
            $tenant = Tenant::where('cnpj', $data['cnpj'])->first();
            /**
             * Retorna imediatamente quando o CNPJ encontrar um tenant.
             */
            if ($tenant) {
                return $tenant;
            }
        }

        /**
         * Usa CPF como última prioridade de descoberta de tenant.
         */
        if (!empty($data['cpf'])) {
            return Tenant::where('cpf', $data['cpf'])->first();
        }

        return null;
    }
}
