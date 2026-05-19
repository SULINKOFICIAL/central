<?php

namespace App\Services\Payments;

use App\DTOs\Payments\BoletoChargeDataDTO;
use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PagHiperBoletoProviderService
{
    public string $apiKey;
    public string $createUrl;
    public string $webhookUrl;

    public function __construct()
    {
        $this->apiKey     = env('PAG_HIPER_API_KEY', '');
        $this->createUrl  = env('PAG_HIPER_BOLETO_CREATE_URL', 'https://api.paghiper.com/transaction/create/');
        $this->webhookUrl = env('PAG_HIPER_WEBHOOK_URL', '');
    }

    /**
     * Cria a cobrança de boleto no provider e normaliza para DTO canônico.
     */
    public function createCharge(Order $order, array $clientInfo): BoletoChargeDataDTO
    {
        $payload = [
            'apiKey'       => $this->apiKey,
            'order_id'     => 'plan-order-' . $order->id . '-' . now()->timestamp,
            'payer_email'  => $clientInfo['email'] ?? null,
            'payer_name'   => $clientInfo['name'] ?? null,
            'payer_cpf_cnpj' => $clientInfo['document'] ?? null,
            'payer_phone'  => $this->resolvePhone($clientInfo['phone'] ?? null),
            'type_bank_slip' => 'boletoA4',
            'days_due_date'  => 3,
            'items' => [
                [
                    'description' => 'Assinatura miCore - Pedido #' . $order->id,
                    'quantity'    => 1,
                    'item_id'     => $order->id,
                    'price_cents' => intval(round(((float) $order->total_amount) * 100)),
                ],
            ],
            'notification_url' => $this->webhookUrl,
        ];

        Log::debug('[PagHiperBoleto] payload enviado', $payload);

        $response = Http::acceptJson()
            ->timeout(20)
            ->post($this->createUrl, $payload);

        Log::debug('[PagHiperBoleto] status HTTP', ['status' => $response->status()]);
        Log::debug('[PagHiperBoleto] body bruto', ['body' => $response->body()]);

        $responseData = $response->json() ?? [];

        Log::debug('[PagHiperBoleto] json parseado', $responseData);

        // PagHiper encapsula boleto em 'create_request'
        $resultNode = $responseData['create_request'] ?? [];

        if ($response->failed() || ($resultNode['result'] ?? null) !== 'success') {
            return new BoletoChargeDataDTO(
                provider:               'paghiper',
                providerMethod:         'boleto',
                providerTransactionId:  $resultNode['transaction_id'] ?? '',
                status:                 'failed',
                providerMessage:        $resultNode['response_message'] ?? 'Falha ao gerar boleto.',
                digitableLine:          null,
                urlSlip:                null,
                urlSlipPdf:             null,
                dueDate:                null,
                rawResponse:            $responseData,
            );
        }

        $bankSlip = $resultNode['bank_slip'] ?? [];

        return new BoletoChargeDataDTO(
            provider:               'paghiper',
            providerMethod:         'boleto',
            providerTransactionId:  $resultNode['transaction_id'] ?? '',
            status:                 $this->normalizeStatus($resultNode['status'] ?? null),
            providerMessage:        $resultNode['response_message'] ?? 'Boleto gerado com sucesso.',
            digitableLine:          $bankSlip['digitable_line'] ?? null,
            urlSlip:                $bankSlip['url_slip'] ?? null,
            urlSlipPdf:             $bankSlip['url_slip_pdf'] ?? null,
            dueDate:                $resultNode['due_date'] ?? null,
            rawResponse:            $responseData,
        );
    }

    /**
     * Monta o telefone em formato numérico contínuo para o payload da PagHiper.
     */
    private function resolvePhone($phone): string
    {
        $countryCode = $phone['country_code'] ?? '';
        $areaCode    = $phone['area_code'] ?? '';
        $number      = $phone['phone'] ?? '';

        return onlyNumbers($countryCode . $areaCode . $number);
    }

    /**
     * Traduz status da PagHiper para o status canônico interno do billing.
     */
    public function normalizeStatus($providerStatus): string
    {
        return match (strtolower($providerStatus ?? '')) {
            'paid'                       => 'approved',
            'pending', 'created', 'waiting' => 'pending',
            'canceled', 'cancelled'      => 'canceled',
            'expired', 'overdue'         => 'expired',
            'failed', 'error'            => 'failed',
            default                      => strtolower($providerStatus ?? ''),
        };
    }
}
