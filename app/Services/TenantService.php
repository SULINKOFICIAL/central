<?php

namespace App\Services;

use App\Models\OrderTransaction;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;

class TenantService
{
    /**
     *
     * Retorna o tenant a partir do identificador e do tipo de provider.
     *
     */
    public function findTenant(string $customerId, string $provider = 'id'): Tenant
    {

        // Obtem a coluna pelo tipo de provedor
        $column = match ($provider) {
            'pagarme' => 'pagarme_customer_id',
            default   => 'id',
        };

        // Busca o tenant pela coluna e pelo customerId
        $tenant = Tenant::where($column, $customerId)->first();

        // Retorna o tenant
        return $tenant;

    }

    /**
     *
     * Resolve tenant por transação de provider já persistida no fluxo de pagamento.
     *
     */
    public function findTenantByTransaction(string $provider, string $providerTransactionId): Tenant
    {
        /**
         * Localiza a transação responsável e devolve o tenant
         */
        $transaction = OrderTransaction::where('provider', $provider)->where('provider_transaction_id', $providerTransactionId)->first();

        return $transaction->order->tenant;

    }

    public function systemStatus(Tenant $tenant): string
    {
        if (!$tenant->token) {
            return 'Token Empty';
        }

        try {
            $domain = $tenant->domains()->orderBy('id')->value('domain');
            if (!$domain) {
                return 'Error';
            }

            $response = Http::withToken($tenant->token)->get("https://{$domain}/api/sistema/status");

            if ($response->successful() && ($response->json()['status'] ?? null) === 'ok') {
                return 'OK';
            }

            return 'Error';
        } catch (\Throwable $e) {
            return 'Error';
        }
    }
}
