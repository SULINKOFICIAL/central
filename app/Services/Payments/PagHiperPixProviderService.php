<?php

namespace App\Services\Payments;

use App\DTOs\Payments\PixChargeDataDTO;
use App\Models\Order;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

class PagHiperPixProviderService
{
    public string $apiKey;
    public string $createUrl;
    public string $webhookUrl;

    public function __construct()
    {
        $this->apiKey     = env('PAG_HIPER_API_KEY', '');
        $this->createUrl  = env('PAG_HIPER_PIX_CREATE_URL', 'https://pix.paghiper.com/invoice/create/');
        $this->webhookUrl = env('PAG_HIPER_WEBHOOK_URL', '');
    }

    /**
     * Cria a cobrança PIX no provider e normaliza para DTO canônico.
     */
    public function createCharge(Order $order, array $clientInfo): PixChargeDataDTO
    {
        // Monta o payload da requisição com os dados do pedido e do pagador
        $payload = [
            'apiKey' => $this->apiKey,
            'order_id' => 'plan-order-' . $order->id . '-' . now()->timestamp,
            'payer_email' => $clientInfo['email'] ?? null,
            'payer_name' => $clientInfo['name'] ?? null,
            'payer_cpf_cnpj' => $clientInfo['document'] ?? null,
            'payer_phone' => $this->resolvePhone($clientInfo['phone'] ?? null),
            'fixed_description' => true,
            'days_due_date' => 2,
            'items' => [
                [
                    'description' => 'Assinatura miCore - Pedido #' . $order->id,
                    'quantity' => 1,
                    'item_id' => $order->id,
                    'price_cents' => intval(round(((float) $order->total_amount) * 100)),
                ],
            ],
            'notification_url' => $this->webhookUrl,
        ];

        // Envia a requisição de criação de cobrança ao provider
        $response = Http::acceptJson()
            ->timeout(20)
            ->post($this->createUrl, $payload);

        // Obtem o json de resposta
        $responseData = $response->json() ?? [];

        // Obtem a chave onde a PagHiper encapsula os dados úteis
        $resultNode = $responseData['pix_create_request'] ?? [];

        // Retorna DTO de falha quando o provider rejeita a cobrança
        if ($response->failed() || ($resultNode['result'] ?? null) !== 'success') {
            return new PixChargeDataDTO(
                provider: 'paghiper',
                providerMethod: 'pix',
                providerTransactionId: $resultNode['transaction_id'] ?? '',
                status: 'failed',
                providerMessage: $resultNode['response_message'] ?? 'Falha ao gerar cobrança PIX.',
                qrCodeBase64: null,
                copyPaste: null,
                expiresAt: null,
                rawResponse: $responseData,
            );
        }

        // Obtem a data de vencimento retornada pelo provider
        $dueDate = $resultNode['due_date'] ?? null;

        // Normaliza a data de vencimento para datetime local
        $expiresAt = $dueDate ? Carbon::parse($dueDate)->toDateTimeString() : null;

        // Devolve DTO com os dados da cobrança aprovada ou pendente
        return new PixChargeDataDTO(
            provider: 'paghiper',
            providerMethod: 'pix',
            providerTransactionId: $resultNode['transaction_id'] ?? '',
            status: $this->normalizeStatus($resultNode['status'] ?? null),
            providerMessage: $resultNode['response_message'] ?? 'PIX gerado com sucesso.',
            qrCodeBase64: $resultNode['pix_code']['qrcode_base64'] ?? null,
            copyPaste: $resultNode['pix_code']['emv'] ?? null,
            expiresAt: $expiresAt,
            rawResponse: $responseData,
        );
    }

    /**
     * Monta o telefone em formato numérico contínuo para o payload da PagHiper.
     */
    private function resolvePhone($phone): string
    {
        // Obtem o código do país
        $countryCode = $phone['country_code'] ?? '';

        // Obtem o DDD
        $areaCode = $phone['area_code'] ?? '';

        // Obtem o número do telefone
        $number = $phone['phone'] ?? '';

        // Concatena os três em uma única string numérica
        return onlyNumbers($countryCode . $areaCode . $number);
    }

    /**
     * Traduz status da PagHiper para o status canônico interno do billing.
     */
    public function normalizeStatus($providerStatus): string
    {
        return match (strtolower($providerStatus ?? '')) {
            'paid' => 'approved',
            'pending', 'created', 'waiting' => 'pending',
            'canceled', 'cancelled' => 'canceled',
            'expired', 'overdue' => 'expired',
            'failed', 'error' => 'failed',
            default => strtolower($providerStatus ?? ''),
        };
    }
}
