<?php

namespace App\Http\Controllers;

use App\Models\TenantCard;
use App\Services\OrderService;
use App\Services\Payments\PaymentPlanService;
use Illuminate\Http\Request;

class ApisPaymentsController extends Controller
{

    /**
     * Processa o pagamento do pedido em andamento do cliente.
     * Resolve pedido/cartão e delega a cobrança para o serviço de pedidos.
     */
    public function orderPayment(Request $request, OrderService $service, PaymentPlanService $paymentPlanService)
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

        // Obtem o pacote do cliente
        $plan = $service->getPlanInProgress($data['tenant']);

        // Busca o pedido em andamento
        $order = $service->getOrderInProgress($data['tenant'], $plan);

        // Resolve cartão com validações de payload.
        $cardResult = $this->resolveCardFromRequest($data);

        // Interrompe fluxo quando cartão não for encontrado.
        if ($cardResult['error']) {
            return response()->json(['message' => $cardResult['error']], $cardResult['status']);
        }

        // Extrai cartão validado para envio ao serviço de pagamento.
        $card = $cardResult['card'];

        // Processa pagamento junto ao serviço de pedidos.
        $response = $service->createOrderPayment(
            $plan,
            $order,
            $data['tenant'],
            $data['client_info'],
            $card,
            $this->extractCardCvv($data),
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

    /**
     * Resolve o cartão que será utilizado no pagamento.
     * Aceita cartão existente por id ou cria/reutiliza cartão enviado no payload.
     */
    private function resolveCardFromRequest(array &$data): array
    {
        // Verifica se foi enviado um id de cartão.
        if (isset($data['card_id'])) {

            // Busca cartão existente do próprio cliente.
            $existingCard = TenantCard::where('tenant_id', $data['tenant']->id)
                ->where('id', $data['card_id'])
                ->first();

            // Impede uso de cartão inexistente para o cliente.
            if (!$existingCard) {
                return ['card' => null, 'error' => 'Cartão não encontrado para esse cliente', 'status' => 404];
            }

            // Retorna cartão existente para uso no pagamento.
            return ['card' => $existingCard, 'error' => null, 'status' => 200];
        }

        // Rejeita payload parcial de cartão novo.
        if ($this->hasIncompleteCardPayload($data)) {
            return ['card' => null, 'error' => 'Parâmetros faltando', 'status' => 400];
        }

        // Exige cartão quando não for informado card_id.
        if (!$this->hasNewCardPayload($data)) {
            return ['card' => null, 'error' => 'Cartão não informado', 'status' => 400];
        }

        // Normaliza número para comparação/armazenamento.
        $data['card']['number'] = $this->normalizeCardNumber($data['card']['number']);

        // Reaproveita cartão já salvo para esse cliente.
        $card = TenantCard::where('tenant_id', $data['tenant']->id)
            ->where('number', $data['card']['number'])
            ->first();

        // Cria novo cartão quando ainda não existe.
        if (!$card) {

            // Salva novo cartão no banco.
            $card = TenantCard::create([
                'tenant_id' => $data['tenant']->id,
                'main' => true,
                'name' => $data['card']['name'],
                'number' => $data['card']['number'],
                'expiration_month' => substr($data['card']['expiration'], 0, 2),
                'expiration_year' => '20' . substr($data['card']['expiration'], -2),
            ]);
        }

        // Retorna cartão para uso no pagamento.
        return ['card' => $card, 'error' => null, 'status' => 200];
    }

    /**
     * Verifica se o payload possui todos os campos obrigatórios do cartão novo.
     * Retorna true apenas quando nome, número, vencimento e cvv estão presentes.
     */
    private function hasNewCardPayload(array $data): bool
    {
        return isset($data['card'])
            && isset($data['card']['name'])
            && isset($data['card']['number'])
            && isset($data['card']['expiration'])
            && isset($data['card']['cvv']);
    }

    /**
     * Verifica se o payload de cartão foi enviado parcialmente.
     * Usado para responder erro de parâmetros faltando.
     */
    private function hasIncompleteCardPayload(array $data): bool
    {
        return isset($data['card']) && !$this->hasNewCardPayload($data);
    }

    /**
     * Normaliza o número do cartão removendo espaços.
     * Retorna o número limpo no formato inteiro.
     */
    private function normalizeCardNumber(string $number): int
    {
        return (int) str_replace(' ', '', $number);
    }

    /**
     * Extrai o CVV enviado no payload.
     * Retorna null quando não informado.
     */
    private function extractCardCvv(array $data): ?string
    {
        return $data['card']['cvv'] ?? null;
    }
}
