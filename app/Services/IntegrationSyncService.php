<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * Sincroniza as integrações ativas de cada tenant (micore) com o gateway
 * externo de integrações na AWS.
 *
 * Fluxo por tenant:
 * 1. Consulta a rota do micore que lista as integrações ativas.
 * 2. Reenvia cada integration_key ao gateway, junto do domínio e do tenant_id.
 *
 * A central não monta a integration_key: o micore já devolve a chave pronta
 * (ex.: "mercado_livre#SELLER_ID"); aqui apenas repassamos.
 */
class IntegrationSyncService
{
    /**
     * Rota no micore (CoreController::getIntegrations) que devolve as
     * integrações ativas do tenant. Resolvida em api/sistema/integracoes,
     * sob o middleware auth.central.
     *
     * Contrato de resposta:
     * { "success": true, "data": [ { "integration_key": "mercado_livre#SELLER_ID" } ] }
     *
     * O SELLER_ID da integration_key sai de integrations_accounts_entities.external_id
     * (model IntegrationAccountEntity), não de integrations_accounts.external_account_id.
     */
    private const TENANT_INTEGRATIONS_PATH = 'sistema/integracoes';

    public function __construct(
        private GuzzleService $guzzleService,
        private RequestService $requestService,
    ) {
    }

    /**
     * Sincroniza as integrações de todos os tenants ativos que possuem domínio.
     *
     * Nunca interrompe o lote por falha de um tenant: cada erro é registrado
     * e a varredura continua, devolvendo um resumo agregado no final.
     */
    public function syncAllTenants(): array
    {
        // Apenas tenants ativos entram na varredura, alinhado às rotinas em massa da central.
        $tenants = Tenant::where('status', true)->get();

        // Carrega os domínios uma vez para evitar consulta por tenant dentro do loop.
        $tenants->load('domains');

        // Resumo agregado devolvido ao chamador (migration/command).
        $summary = [
            'tenants_total'       => $tenants->count(),
            'tenants_processed'   => 0,
            'tenants_skipped'     => 0,
            'integrations_sent'   => 0,
            'integrations_failed' => 0,
            'failures'            => [],
        ];

        foreach ($tenants as $tenant) {

            // Tenant sem domínio não tem como receber a chamada do micore.
            if ($tenant->domains->isEmpty()) {
                $summary['tenants_skipped']++;

                continue;
            }

            $result = $this->syncTenant($tenant);

            $summary['tenants_processed']++;
            $summary['integrations_sent']   += $result['sent'];
            $summary['integrations_failed'] += $result['failed'];

            // Guarda o detalhe do tenant apenas quando houver algum problema.
            if (!$result['success'] || $result['failed'] > 0) {
                $summary['failures'][] = [
                    'tenant_id' => $tenant->id,
                    'message'   => $result['message'],
                    'failed'    => $result['failed'],
                ];
            }
        }

        return $summary;
    }

    /**
     * Sincroniza as integrações de um único tenant com o gateway externo.
     *
     * Retorna a contagem de chaves enviadas/falhas e uma mensagem de contexto.
     */
    public function syncTenant(Tenant $tenant): array
    {
        $tenant->loadMissing('domains');

        // Domínio principal usado para chamar o micore e compor o payload do gateway.
        $domain = $tenant->domains->first()?->domain;

        if (!$domain) {
            return [
                'success' => false,
                'message' => 'Tenant sem domínio ativo.',
                'sent'    => 0,
                'failed'  => 0,
            ];
        }

        // Busca a lista de integrações ativas diretamente no micore.
        $integrationKeys = $this->fetchIntegrationKeys($tenant);

        // null diferencia "micore inacessível" de "tenant sem integrações".
        if ($integrationKeys === null) {
            return [
                'success' => false,
                'message' => 'Falha ao consultar integrações no micore.',
                'sent'    => 0,
                'failed'  => 0,
            ];
        }

        $sent = 0;
        $failed = 0;

        foreach ($integrationKeys as $integrationKey) {

            // Reenvia cada chave ao gateway externo, isolando falhas por item.
            $delivered = $this->pushIntegration($integrationKey, $domain, (int) $tenant->id);

            if ($delivered) {
                $sent++;
            } else {
                $failed++;
            }
        }

        return [
            'success' => true,
            'message' => 'Integrações processadas.',
            'sent'    => $sent,
            'failed'  => $failed,
        ];
    }

    /**
     * Consulta o micore e devolve a lista de integration_key ativas.
     *
     * Retorna null quando a consulta falha, para o chamador diferenciar
     * "tenant sem integrações" de "micore inacessível".
     */
    private function fetchIntegrationKeys(Tenant $tenant): ?array
    {
        // Helper padrão da central: injeta o Bearer da central e monta a URL do tenant.
        $response = $this->guzzleService->request('get', self::TENANT_INTEGRATIONS_PATH, $tenant);

        if (!($response['success'] ?? false)) {
            Log::warning('integrations.sync.fetch_failed', [
                'tenant_id' => $tenant->id,
                'response'  => $response,
            ]);

            return null;
        }

        // O GuzzleService devolve o corpo cru; aqui decodificamos o JSON do micore.
        $body = json_decode($response['data'] ?? '', true);

        if (!is_array($body) || !isset($body['data']) || !is_array($body['data'])) {
            Log::warning('integrations.sync.invalid_body', [
                'tenant_id' => $tenant->id,
                'body'      => $response['data'] ?? null,
            ]);

            return null;
        }

        // Extrai apenas as chaves válidas, ignorando itens sem integration_key.
        $keys = [];
        foreach ($body['data'] as $integration) {
            $key = $integration['integration_key'] ?? null;

            if (is_string($key) && trim($key) !== '') {
                $keys[] = trim($key);
            }
        }

        return $keys;
    }

    /**
     * Envia uma integration_key ao gateway externo de integrações.
     *
     * Retorna true apenas quando o gateway confirma o recebimento (HTTP 2xx).
     */
    private function pushIntegration(string $integrationKey, string $domain, int $tenantId): bool
    {
        $url = config('services.integrations_sync.url');

        if (empty($url)) {
            Log::error('integrations.sync.missing_url');

            return false;
        }

        // Contrato fixo do gateway: chave da integração + domínio + id do tenant na central.
        $response = $this->requestService->request('POST', $url, [
            'json' => [
                'integration_key' => $integrationKey,
                'domain'          => $domain,
                'tenant_id'       => $tenantId,
            ],
        ]);

        // http_errors está desligado no RequestService, então confirmamos pelo status 2xx.
        $status = (int) ($response['status'] ?? 0);
        $delivered = ($response['success'] ?? false) && $status >= 200 && $status < 300;

        if (!$delivered) {
            Log::warning('integrations.sync.push_failed', [
                'tenant_id'       => $tenantId,
                'integration_key' => $integrationKey,
                'response'        => $response,
            ]);
        }

        return $delivered;
    }
}
