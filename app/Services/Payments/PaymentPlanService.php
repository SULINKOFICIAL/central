<?php

namespace App\Services\Payments;

use App\Models\OrderTransaction;
use App\Services\OrderService;
use App\Services\PagHiperService;
use Carbon\Carbon;

class PaymentPlanService
{
    public function __construct(
        private OrderService $orderService,
        private PagHiperPixProviderService $pixProviderService,
        private PagHiperBoletoProviderService $boletoProviderService,
        private PagHiperService $pagHiperService,
    ) {
    }

    /**
     * Processa pagamento de plano via PIX e persiste a transação com idempotência.
     */
    public function processPlanPayment($tenant, string $billingCycle, array $clientInfo): array
    {
        // Obtem o plano em progresso
        $plan = $this->orderService->getPlanInProgress($tenant);

        // Obtem o pedido atual
        $order = $this->orderService->getOrderInProgress($tenant, $plan);

        // Retorna erro quando a cobrança de pedido sem valor
        if ($order->total_amount <= 0) {
            return [
                'success' => false,
                'message' => 'Pedido sem valor para gerar PIX.',
            ];
        }

        // Obtem a transação pendente
        $existingPending = OrderTransaction::where('order_id', $order->id)
            ->where('provider', 'paghiper')
            ->where('provider_method', 'pix')
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        // Se existir uma transação pendente, retorna ela
        if ($existingPending && $this->isPendingStillValid($existingPending)) {

            // Monta a resposta do pagamento
            return $this->buildPixPaymentResponse($existingPending);
        }

        // Gera um charge PIX
        $chargeData = $this->pixProviderService->createCharge($order, $clientInfo);

        // Interrompe o fluxo quando o provider rejeita a cobrança
        if ($chargeData->status === 'failed') {
            return [
                'success' => false,
                'message' => $chargeData->providerMessage,
            ];
        }

        // Define se a cobrança já foi aprovada na criação
        $approved = $chargeData->status === 'approved';

        // Atualiza o pedido com os dados retornados pelo provider
        $order->update([
            'status' => $approved ? 'paid' : 'pending',
            'provider' => $chargeData->provider,
            'provider_method' => $chargeData->providerMethod,
            'provider_message' => $chargeData->providerMessage,
        ]);

        // Avança o progresso do plano de acordo com o resultado da cobrança
        $plan->update([
            'progress' => $approved ? 'completed' : 'draft',
        ]);

        // Pega o snapshot bruto retornado pelo provider
        $rawResponse = $chargeData->rawResponse;

        // Anexa metadados do checkout ao snapshot
        $rawResponse['_meta'] = [
            'billing_cycle' => $billingCycle,
            'created_at' => now()->toDateTimeString(),
        ];

        // Persiste a transação local de forma idempotente pelo identificador do provider
        $transaction = OrderTransaction::updateOrCreate(
            [
                'provider' => $chargeData->provider,
                'provider_transaction_id' => $chargeData->providerTransactionId,
            ],
            [
                'order_id' => $order->id,
                'subscription_id' => $order->subscription_id,
                'provider_method' => $chargeData->providerMethod,
                'status' => $chargeData->status,
                'currency' => $order->currency,
                'amount' => $order->total_amount,
                'response' => $rawResponse,
                'raw_response_snapshot' => $rawResponse,
                'paid_at' => $approved ? now() : null,
            ],
        );

        // Monta payload para coresulink na etapa PIX
        return $this->buildPixPaymentResponse($transaction);
    }

    /**
     * Processa pagamento de plano via boleto e persiste a transação com idempotência.
     */
    public function processPlanBoletoPayment($tenant, string $billingCycle, array $clientInfo): array
    {
        $plan  = $this->orderService->getPlanInProgress($tenant);
        $order = $this->orderService->getOrderInProgress($tenant, $plan);

        if ($order->total_amount <= 0) {
            return [
                'success' => false,
                'message' => 'Pedido sem valor para gerar boleto.',
            ];
        }

        // Reutiliza boleto pendente ainda dentro do vencimento
        $existingPending = OrderTransaction::where('order_id', $order->id)
            ->where('provider', 'paghiper')
            ->where('provider_method', 'boleto')
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        if ($existingPending && $this->isBoletoStillValid($existingPending)) {
            return $this->buildBoletoPaymentResponse($existingPending);
        }

        $chargeData = $this->boletoProviderService->createCharge($order, $clientInfo);

        if ($chargeData->status === 'failed') {
            return [
                'success' => false,
                'message' => $chargeData->providerMessage,
            ];
        }

        $order->update([
            'status'           => 'pending',
            'provider'         => $chargeData->provider,
            'provider_method'  => $chargeData->providerMethod,
            'provider_message' => $chargeData->providerMessage,
        ]);

        $plan->update(['progress' => 'draft']);

        $rawResponse = $chargeData->rawResponse;
        $rawResponse['_meta'] = [
            'billing_cycle' => $billingCycle,
            'created_at'    => now()->toDateTimeString(),
        ];

        $transaction = OrderTransaction::updateOrCreate(
            [
                'provider'                => $chargeData->provider,
                'provider_transaction_id' => $chargeData->providerTransactionId,
            ],
            [
                'order_id'        => $order->id,
                'subscription_id' => $order->subscription_id,
                'provider_method' => $chargeData->providerMethod,
                'status'          => $chargeData->status,
                'currency'        => $order->currency,
                'amount'          => $order->total_amount,
                'response'        => $rawResponse,
                'raw_response_snapshot' => $rawResponse,
            ],
        );

        return $this->buildBoletoPaymentResponse($transaction);
    }

    /**
     * Consulta status da transação de boleto e atualiza o snapshot local.
     */
    public function getBoletoStatus($tenant, string $providerTransactionId): array
    {
        // Busca a transação de boleto persistida pelo identificador do provider
        $transaction = OrderTransaction::with(['order'])
            ->where('provider', 'paghiper')
            ->where('provider_method', 'boleto')
            ->where('provider_transaction_id', $providerTransactionId)
            ->first();

        // Garante que a transação existe e pertence ao tenant solicitante
        if (!$transaction || !$transaction->order || $transaction->order->tenant_id !== $tenant->id) {
            return [
                'success' => false,
                'message' => 'Transação de boleto não encontrada.',
            ];
        }

        // Pega o payload preservado da transação
        $response = $transaction->response;

        // Busca o notification_id mais recente disponível
        $notificationId = $response['status_request']['notification_id']
            ?? $response['create_request']['transaction_id']
            ?? $providerTransactionId;

        // Consulta o status atual da cobrança no provider
        $rawResponse = $this->pagHiperService->notification(
            'https://api.paghiper.com',
            $providerTransactionId,
            $notificationId,
        );

        // Pega o nó de status retornado pelo provider
        $statusNode = $rawResponse['status_request'] ?? [];

        // Normaliza o status para o contrato canônico
        $status = $this->boletoProviderService->normalizeStatus($statusNode['status'] ?? null);

        // Mescla o snapshot de status no payload preservado
        $response['status_request'] = $statusNode;

        // Monta os campos a atualizar na transação
        $transactionUpdates = [
            'status'               => $status,
            'response'             => $response,
            'raw_response_snapshot' => $rawResponse,
        ];

        // Monta os campos a atualizar no pedido
        $orderUpdates = [
            'status' => match ($status) {
                'approved' => 'paid',
                'canceled' => 'canceled',
                'expired'  => 'expired',
                'failed'   => 'failed',
                default    => 'pending',
            },
            'provider_message' => $statusNode['response_message'] ?? null,
        ];

        // Marca o pagamento somente quando confirmado pelo provider
        if ($status === 'approved') {
            $transactionUpdates['paid_at'] = now();
            $orderUpdates['paid_at']       = now();
        }

        // Persiste o estado da transação
        $transaction->update($transactionUpdates);

        // Persiste o estado do pedido
        $transaction->order->update($orderUpdates);

        // Devolve a resposta canônica com os dados recarregados
        return $this->buildBoletoPaymentResponse($transaction->fresh(['order']));
    }

    /**
     * Consulta status da transação PIX e atualiza o snapshot local.
     */
    public function getPixStatus($tenant, string $providerTransactionId): array
    {
        // Busca a transação PIX persistida pelo identificador do provider
        $transaction = OrderTransaction::with(['order'])
            ->where('provider', 'paghiper')
            ->where('provider_method', 'pix')
            ->where('provider_transaction_id', $providerTransactionId)
            ->first();

        // Garante que a transação existe e pertence ao tenant solicitante
        if (!$transaction || !$transaction->order || $transaction->order->tenant_id !== $tenant->id) {
            return [
                'success' => false,
                'message' => 'Transação PIX não encontrada.',
            ];
        }

        // Pega o payload preservado da transação
        $response = $transaction->response;

        // Busca o notification_id mais recente disponível
        $notificationId = $response['status_request']['notification_id']
            ?? $response['notification_request']['notification_id']
            ?? $transaction->provider_transaction_id;

        // Consulta o status atual da cobrança no provider
        $rawResponse = $this->pagHiperService->notification(
            'https://pix.paghiper.com',
            $providerTransactionId,
            $notificationId,
        );

        // Pega o nó de status retornado pelo provider
        $statusNode = $rawResponse['status_request'];

        // Normaliza o status para o contrato canônico
        $status = $this->pixProviderService->normalizeStatus($statusNode['status']);

        // Mescla o snapshot de status no payload preservado
        $response['status_request'] = $statusNode;

        // Monta os campos a atualizar na transação
        $transactionUpdates = [
            'status' => $status,
            'response' => $response,
            'raw_response_snapshot' => $rawResponse,
        ];

        // Monta os campos a atualizar no pedido
        $orderUpdates = [
            'status' => match ($status) {
                'approved' => 'paid',
                'canceled' => 'canceled',
                'expired' => 'expired',
                'failed' => 'failed',
                default => 'pending',
            },
            'provider_message' => $statusNode['response_message'],
        ];

        // Marca o pagamento somente quando confirmado pelo provider
        if ($status === 'approved') {
            $transactionUpdates['paid_at'] = now();
            $orderUpdates['paid_at'] = now();
        }

        // Persiste o estado da transação
        $transaction->update($transactionUpdates);

        // Persiste o estado do pedido
        $transaction->order->update($orderUpdates);

        // Devolve a resposta canônica com os dados recarregados
        return $this->buildPixPaymentResponse($transaction->fresh(['order']));
    }

    /**
     * Verifica se uma transação PIX pendente ainda pode ser usada no checkout.
     */
    private function isPendingStillValid(OrderTransaction $transaction): bool
    {
        $dueDate = $transaction->response['pix_create_request']['due_date'] ?? null;

        return !$dueDate || Carbon::parse($dueDate)->isFuture();
    }

    /**
     * Verifica se um boleto pendente ainda está dentro do vencimento.
     */
    private function isBoletoStillValid(OrderTransaction $transaction): bool
    {
        $dueDate = $transaction->response['create_request']['due_date'] ?? null;

        return !$dueDate || Carbon::parse($dueDate)->isFuture();
    }

    /**
     * Monta payload para coresulink na etapa boleto.
     */
    private function buildBoletoPaymentResponse(OrderTransaction $transaction): array
    {
        $boletoNode = $transaction->response['create_request'] ?? [];
        $bankSlip   = $boletoNode['bank_slip'] ?? [];

        return [
            'success'          => true,
            'payment_flow'     => 'boleto',
            'transaction_id'   => $transaction->provider_transaction_id,
            'status'           => $this->boletoProviderService->normalizeStatus($transaction->status),
            'provider'         => $transaction->provider,
            'provider_method'  => $transaction->provider_method,
            'message'          => $boletoNode['response_message'] ?? null,
            'boleto' => [
                'digitable_line' => $bankSlip['digitable_line'] ?? null,
                'url_slip'       => $bankSlip['url_slip'] ?? null,
                'url_slip_pdf'   => $bankSlip['url_slip_pdf'] ?? null,
                'due_date'       => $boletoNode['due_date'] ?? null,
            ],
        ];
    }

    /**
     * Monta payload para coresulink na etapa PIX.
     */
    private function buildPixPaymentResponse(OrderTransaction $transaction): array
    {
        // Pega o nó da criação PIX preservado na transação
        $pixNode = $transaction->response['pix_create_request'] ?? [];

        // Extrai a data de vencimento do QR Code
        $dueDate = $pixNode['due_date'] ?? null;

        return [
            'success' => true,
            'payment_flow' => 'pix',
            'transaction_id' => $transaction->provider_transaction_id,
            'status' => $this->pixProviderService->normalizeStatus($transaction->status),
            'provider' => $transaction->provider,
            'provider_method' => $transaction->provider_method,
            'message' => $pixNode['response_message'] ?? null,
            'pix' => [
                'qr_code_base64' => $pixNode['pix_code']['qrcode_base64'] ?? null,
                'copy_paste' => $pixNode['pix_code']['emv'] ?? null,
                'expires_at' => $dueDate ? Carbon::parse($dueDate)->toDateTimeString() : null,
            ],
        ];
    }

}
