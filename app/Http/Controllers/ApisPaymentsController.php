<?php

namespace App\Http\Controllers;

use App\Services\OrderService;
use App\Services\Payments\CardService;
use App\Services\Payments\PaymentPlanService;
use App\Services\Payments\SubscriptionPaymentService;
use Illuminate\Http\Request;

class ApisPaymentsController extends Controller
{

    /**
     * Processa o pagamento do pedido em andamento do cliente.
     * Resolve pedido/cartão e delega a cobrança para o serviço de pedidos.
     */
    public function orderPayment(Request $request, OrderService $service, PaymentPlanService $paymentPlanService, CardService $cardService, SubscriptionPaymentService $subscriptionPaymentService)
    {
        // Obtém dados enviados pelo front.
        $data = $request->all();

        // Se for do tipo pix
        if (($data['payment_type'] ?? null) === 'pix') {
            $response = $paymentPlanService->processPlanPayment(
                $data['tenant'],
                $data['billing_cycle'],
                $data['client_info'],
            );

            if (!$response['success']) {
                return response()->json(['message' => $response['message']], 422);
            }

            return response()->json($response, 200);
        }

        // Se for do tipo boleto
        if (($data['payment_type'] ?? null) === 'boleto') {
            $response = $paymentPlanService->processPlanBoletoPayment(
                $data['tenant'],
                $data['billing_cycle'],
                $data['client_info'],
            );

            if (!$response['success']) {
                return response()->json(['message' => $response['message']], 422);
            }

            return response()->json($response, 200);
        }

        /**
         * Obtém o plano em andamento do tenant.
         */
        $plan = $service->getPlanInProgress($data['tenant']);

        // Busca o pedido em andamento
        $order = $service->getOrderInProgress($data['tenant'], $plan);

        // Resolve cartão com validações de payload.
        $cardResult = $cardService->resolveForPayment($data);

        // Interrompe fluxo quando cartão não for encontrado.
        if ($cardResult['error']) {
            return response()->json(['message' => $cardResult['error']], $cardResult['status']);
        }

        // Extrai cartão validado para envio ao serviço de pagamento.
        $card = $cardResult['card'];

        // Processa pagamento junto ao serviço de assinatura.
        $response = $subscriptionPaymentService->createOrderPayment(
            $plan,
            $order,
            $data['tenant'],
            $data['client_info'],
            $card,
            $cardService->extractCvv($data),
            $data['billing_cycle'],
        );

        // Retorna resposta
        return response()->json([
            'message' => $response
        ], 200);
    }

    /**
     * Consulta status canônico da transação do pedido de assinatura.
     * Roteia para o método correto de acordo com o provider_method da transação.
     */
    public function paymentStatus(Request $request, PaymentPlanService $paymentPlanService)
    {
        // Obtém dados
        $data = $request->all();

        // Busca o provider_method da transação para rotear corretamente
        $transaction = \App\Models\OrderTransaction::where('provider_transaction_id', $data['transaction_id'])
            ->where('provider', 'paghiper')
            ->first();

        // Retorna erro quando a transação não existir
        if (!$transaction) {
            return response()->json(['success' => false, 'message' => 'Transação não encontrada.'], 404);
        }

        // Roteia para o status de boleto quando o método for boleto
        if ($transaction->provider_method === 'boleto') {
            $response = $paymentPlanService->getBoletoStatus($data['tenant'], $data['transaction_id']);
        } else {
            $response = $paymentPlanService->getPixStatus($data['tenant'], $data['transaction_id']);
        }

        // Interrompe fluxo quando não encontrar a transação
        if (!$response['success']) {
            return response()->json($response, 404);
        }

        // Retorna resposta
        return response()->json($response, 200);
    }
}
