<?php

namespace App\Services\Payments;

use App\Models\Subscription;

class SubscriptionService
{
    /**
     * Busca a assinatura pelo identificador externo e provedor.
     * Caso não encontre, cria uma nova sem atualizar registros existentes.
     */
    public function findSubscription(string $subscriptionId, string $provider, ?object $subscriptionDTO = null): Subscription
    {
        $subscription = Subscription::where('provider_subscription_id', $subscriptionId)
            ->where('provider', $provider)
            ->first();

        if (!$subscription && $subscriptionDTO) {
            $subscription = $this->createSubscription($subscriptionDTO, $provider);
        }

        return $subscription;
    }

    /**
     *
     * Salva a assinatura com base no evento recebido, criando quando é nova e atualizando quando já existe.
     *
     */
    public function saveSubscription(object $subscriptionDTO, string $provider): Subscription
    {
        /**
         * Busca a assinatura pelo identificador e provider.
         */
        $subscription = Subscription::where('provider_subscription_id', $subscriptionDTO->id)
            ->where('provider', $provider)
            ->first();

        /**
         * Se não existir, cria uma nova assinatura com os dados do evento.
         * 
         * Se já existir, apenas atualiza os dados que podem mudar ao longo do ciclo.
         */
        if (!$subscription) {
            $subscription = $this->createSubscription($subscriptionDTO, $provider);
        } else {
            $subscription = $this->updateSubscription($subscription, $subscriptionDTO, $provider);
        }

        /**
         * Retorna sucesso para o fluxo do job.
         */
        return $subscription;
    }

    /**
     *
     * Cria a assinatura com os dados vindos do provedor no primeiro recebimento desse vínculo.
     *
     */
    private function createSubscription(object $subscriptionDTO, string $provider): Subscription
    {
        return Subscription::create([
            'provider'                 => $provider,
            'provider_subscription_id' => $subscriptionDTO->id,
            'provider_card_id'         => $subscriptionDTO->cardId,
            'interval'                 => $subscriptionDTO->interval,
            'payment_method'           => $subscriptionDTO->method,
            'currency'                 => $subscriptionDTO->currency,
            'installments'             => $subscriptionDTO->installments,
            'status'                   => $subscriptionDTO->status,
        ]);
    }

    /**
     * Busca assinatura PagHiper existente do tenant ou cria uma nova para o primeiro ciclo pago.
     */
    public function findOrCreateForPagHiper(int $tenantId, int $planId, string $transactionId, string $paymentMethod): Subscription
    {
        $subscription = Subscription::where('tenant_id', $tenantId)
            ->where('provider', 'paghiper')
            ->latest('id')
            ->first();

        if ($subscription) {
            return $subscription;
        }

        return Subscription::create([
            'tenant_id'                => $tenantId,
            'plan_id'                  => $planId,
            'provider'                 => 'paghiper',
            'provider_subscription_id' => $transactionId,
            'interval'                 => 'monthly',
            'payment_method'           => $paymentMethod,
            'currency'                 => 'BRL',
            'installments'             => 1,
            'status'                   => 'active',
        ]);
    }

    /**
     *
     * Atualiza os dados principais da assinatura para manter o cadastro alinhado ao status atual.
     *
     */
    private function updateSubscription(Subscription $subscription, object $subscriptionDTO, string $provider): Subscription
    {
        $subscription->update([
            'provider'                 => $provider,
            'provider_subscription_id' => $subscriptionDTO->id,
            'provider_card_id'         => $subscriptionDTO->cardId,
            'interval'                 => $subscriptionDTO->interval,
            'payment_method'           => $subscriptionDTO->payment_method,
            'currency'                 => $subscriptionDTO->currency,
            'installments'             => $subscriptionDTO->installments,
            'status'                   => $subscriptionDTO->status,
        ]);

        return $subscription;
    }
}
