<?php

namespace App\Services\Payments;

use App\DTOs\PagHiper\PagHiperDTO;
use App\DTOs\Payments\{
    CycleDataDTO,
    OrderDataDTO,
    PaymentDataDTO,
    TransactionDataDTO
};
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantPlan;
use Carbon\Carbon;

class PagHiperPayloadService
{
    public function __construct(private PaymentService $paymentService){}

    /**
     * Monta os DTOs de pagamento PagHiper e delega persistência ao PaymentService.
     */
    public function create(PagHiperDTO $pagHiperDTO, Tenant $tenant, ?Subscription $subscription, TenantPlan $plan, array $rawRequest): void
    {
        $paidAt = $pagHiperDTO->paidAt->format('Y-m-d H:i:s');

        $orderData = new OrderDataDTO(
            status:           $pagHiperDTO->status,
            provider_method:  $pagHiperDTO->type,
            provider_message: null,
            currency:         'BRL',
            total_amount:     $pagHiperDTO->value,
            paid_at:          $paidAt,
        );

        $transactionData = new TransactionDataDTO(
            provider_method:         $pagHiperDTO->type,
            provider_transaction_id: $pagHiperDTO->transactionId,
            gateway_code:            $pagHiperDTO->orderId,
            status:                  $pagHiperDTO->status,
            currency:                'BRL',
            recurrency:              false,
            amount:                  $pagHiperDTO->value,
            paid_at:                 $paidAt,
            response:                $rawRequest,
        );

        // Gera ciclo apenas quando há assinatura vinculada (pagamento confirmado)
        $cycleData = null;
        if ($subscription) {
            $billingAt = Carbon::instance($pagHiperDTO->paidAt);
            $cycleData = new CycleDataDTO(
                provider_cycle_id: $pagHiperDTO->transactionId,
                start_date:        $billingAt->toDateTimeString(),
                end_date:          $billingAt->copy()->addMonth()->toDateTimeString(),
                status:            $pagHiperDTO->status,
                cycle:             null,
                billing_at:        $billingAt->toDateTimeString(),
                next_billing_at:   $billingAt->copy()->addMonth()->toDateTimeString(),
            );
        }

        $paymentData = new PaymentDataDTO(
            provider:        'paghiper',
            tenant_id:       $tenant->id,
            subscription_id: $subscription?->id,
            plan_id:         $plan->id,
            order:           $orderData,
            transaction:     $transactionData,
            cycle:           $cycleData,
        );

        $this->paymentService->create($paymentData);
    }

}
