<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\OrderTransaction;
use App\Models\Subscription;
use App\Models\SubscriptionCycle;
use App\Services\PagarMeService;
use App\Services\TenantConfigurationSyncService;

class SubscriptionPaymentService
{
    public function __construct(
        private PagarMeService $pagarMeService,
        private TenantConfigurationSyncService $syncService
    ) {
    }

    /**
     * Cria a cobrança recorrente do pedido via cartão na PagarMe.
     *
     * Garante customer e cartão no provider antes de abrir a assinatura.
     */
    public function createOrderPayment($plan, $orderPayment, $tenant, $clientInfo, $card, $cvv = null, $intervalCycle)
    {
        /**
         * Retorna o customer na PagarMe
         */
        $customer = $this->pagarMeService->findOrCreateCustomer([
            'id'           => $tenant->id,
            'name'         => $tenant->name ?? $clientInfo['name'],
            'email'        => $tenant->email ?? $clientInfo['email'],
            'type'         => (isset($tenant->company) || $clientInfo['type'] == 1) ? 'company' : 'individual',
            'document'     => isset($tenant->company) ? ($tenant->cnpj ?? $clientInfo['document']) : ($tenant->cpf ?? $clientInfo['document']),
            'country_code' => $clientInfo['phone']['country_code'],
            'area_code'    => $clientInfo['phone']['area_code'],
            'number'       => $clientInfo['phone']['phone'],
        ]);

        /**
         * Retorna o cartão na PagarMe
         */
        $card = $this->pagarMeService->findOrCreateCard($tenant->id, $card->id, $cvv ?? null, $clientInfo['address']);

        /**
         * Retorna a assinatura na PagarMe
         */
        $subscription = $this->pagarMeService->createSubscription($customer['id'], $card['id'], $plan, $orderPayment, $intervalCycle);

        // Se retornar sucesso da requisição
        if (isset($subscription) && isset($subscription['id'])) {

            // Atualiza ou cria a assinatura
            $subscription = Subscription::updateOrCreate([
                'provider'                => 'pagarme',
                'provider_subscription_id'=> $subscription['id'],
            ], [
                'provider_card_id'        => $subscription['card']['id'],
                'interval'                => $subscription['interval'],
                'payment_method'          => $subscription['payment_method'],
                'currency'                => $subscription['currency'],
                'installments'            => $subscription['installments'],
                'status'                  => $subscription['status'],
            ]);

            // Obtem o ultimo pedido pago
            $lastOrder = Order::where('tenant_id', $tenant->id)
                ->where('status', 'paid')
                ->orderBy('created_at', 'desc')
                ->first();

            if(isset($lastOrder) && isset($lastOrder->subscription) && $lastOrder->subscription->provider_subscription_id) {

                // Obtem a assinatura do ultimo pedido pago
                $lastSubscription = $lastOrder->subscription;

                // Cancela a assinatura do ultimo pedido pago
                $this->pagarMeService->cancelSubscription($lastSubscription->provider_subscription_id);

            }

            // Atualiza o pedido com o id da assinatura
            $orderPayment->update([
                'subscription_id' => $subscription->id,
            ]);

            /**
             * Busca o pedido de assinatura, cria transação e ciclo.
             */
            return $this->processSubscriptionPayment($orderPayment, $subscription, $plan);

        }
    }

    /**
     * Concilia a fatura da assinatura, persistindo transação, ciclo e status.
     *
     * Propaga os acessos ao tenant quando o pagamento é aprovado.
     */
    public function processSubscriptionPayment($orderPayment, $subscription, $plan)
    {
        // Busca o pedido de assinatura
        $transaction = $this->pagarMeService->getSubscriptionInvoices($subscription->provider_subscription_id);

        // Se retornar sucesso da requisição
        if (isset($transaction) && isset($transaction['data']) && isset($transaction['data'][0]['charge'])) {

            // Obtem o array de cobrança
            $charge = $transaction['data'][0]['charge'] ?? null;

            // Atualiza ou cria a transação
            OrderTransaction::updateOrCreate([
                'order_id'                => $orderPayment->id,
                'subscription_id'         => $subscription->id,
                'provider'                => 'pagarme',
                'provider_transaction_id' => $charge['id'],
            ], [
                'status'                  => $charge['status'],
                'gateway_code'            => $charge['gateway_id'] ?? null,
                'amount'                  => $charge['paid_amount'] / 100 ?? 0,
                'currency'                => $charge['currency'] ?? null,
                'provider_method'         => $charge['payment_method'] ?? null,
                'recurrency'              => $charge['recurrence_cycle'] ?? null,
                'response'                => $transaction,
                'paid_at'                 => $charge['paid_at'] ?? null,

            ]);

            // Atualiza o status do pedido
            $orderPayment->update([
                'status'  => $charge['status'],
            ]);

            $plan->update([
                'progress' => $charge['status'] == 'paid' ? 'completed' : 'draft',
            ]);

            // Mapeia de acordo com o status
            $statusMap = [
                'paid' => [
                    'subscription_status' => 'active',
                    'message' => 'Assinatura aprovada com sucesso.',
                ],
                'pending' => [
                    'subscription_status' => 'pending',
                    'message' => 'Pagamento pendente.',
                ],
                'processing' => [
                    'subscription_status' => 'pending',
                    'message' => 'Pagamento em processamento.',
                ],
                'failed' => [
                    'subscription_status' => 'failed',
                    'message' => 'Pagamento recusado.',
                ],
                'canceled' => [
                    'subscription_status' => 'canceled',
                    'message' => 'Pagamento cancelado.',
                ],
                'refunded' => [
                    'subscription_status' => 'refunded',
                    'message' => 'Pagamento estornado.',
                ],
            ];

            // Pega o status da transação
            $status = $charge['status'];

            // Verifica se o status existe no mapeamento
            if (!isset($statusMap[$status])) {
                return 'Status desconhecido.';
            }

            // Atualiza assinatura
            $subscription->update([
                'status' => $statusMap[$status]['subscription_status'],
            ]);

            // Se for pago, atualiza dados extras
            if ($status === 'paid') {
                $orderPayment->update([
                    'paid_at' => $charge['paid_at'],
                    'provider_method' => $charge['payment_method'],
                ]);

                // Extrai resposta
                $transaction = $transaction['data'][0];

                // Cria ciclo
                SubscriptionCycle::updateOrCreate([
                    'provider'          => 'pagarme',
                    'provider_cycle_id' => $transaction['cycle']['id'],
                ],[
                    'subscription_id'   => $subscription->id,
                    'start_date'        => $transaction['cycle']['start_at'],
                    'end_date'          => $transaction['cycle']['end_at'],
                    'status'            => $transaction['cycle']['status'],
                    'cycle'             => $transaction['cycle']['cycle'],
                    'billing_at'        => $transaction['cycle']['billing_at'],
                    'next_billing_at'   => $transaction['subscription']['next_billing_at'],
                ]);

                /**
                 * Após pagamento aprovado, propaga ao tenant remoto
                 * o estado consolidado de módulos, vigência e limites.
                 */
                $this->syncService->syncFromCurrentPlan(
                    $orderPayment->tenant,
                    source: 'order_paid',
                    operatorId: null,
                    reason: 'Pagamento aprovado',
                    startDate: $transaction['cycle']['start_at'] ?? null,
                    endDate: $transaction['cycle']['end_at'] ?? null,
                );

            }

            // Retorna a mensagem
            return $statusMap[$status]['message'];
        }

    }
}
