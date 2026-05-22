<?php

namespace App\Services;

use App\DTOs\PagHiper\PagHiperDTO;
use DateTime;

class PagHiperResponseService
{
    protected PagHiperService $pagHiperService;

    public function __construct()
    {
        $this->pagHiperService = app(PagHiperService::class);
    }

    /**
     *
     * Normaliza o webhook da PagHiper em um DTO canônico para o pipeline interno.
     *
     */
    public function process(array $data): PagHiperDTO
    {

        /**
         * Para notificações recebidas pela PagHiper PIX, tem url pré definida.
         */
        $type = $data['source_api'] == 'https://pix.paghiper.com' ? 'pix' : 'boleto';

        return $type == 'pix' ? $this->fromPix($data) : $this->fromBoleto($data);
    }

    /**
     *
     * O PIX envia apenas os identificadores no webhook, então consulta a
     * notificação completa na API para montar o DTO. Os valores já vêm em
     * centavos e o status no vocabulário canônico.
     *
     */
    private function fromPix(array $data): PagHiperDTO
    {
        $notification = $this->pagHiperService->notification($data['source_api'], $data['transaction_id'], $data['notification_id'])['status_request'];

        return new PagHiperDTO(
            transactionId:  $notification['transaction_id'],
            orderId:        $notification['order_id'],
            value:          $notification['value_cents'],
            valueFee:       $notification['value_fee_cents'],
            discount:       $notification['discount_cents'],
            paidValue:      $notification['value_cents_paid'],
            paidAt:         new DateTime($notification['paid_date']),
            status:         $notification['status'],
            type:           'pix',
        );
    }

    /**
     *
     * O boleto já chega com o corpo completo no webhook, dispensando a
     * consulta. Os valores vêm em reais (string) e o status em português,
     * então são convertidos para centavos e para o vocabulário canônico.
     *
     */
    private function fromBoleto(array $data): PagHiperDTO
    {
        $value    = $this->toCents($data['valorOriginal']);
        $netValue = $this->toCents($data['valorLoja']);

        return new PagHiperDTO(
            transactionId:  $data['transaction_id'],
            orderId:        $data['idPlataforma'],
            value:          $value,
            valueFee:       $value - $netValue,
            discount:       $this->toCents($data['descontoBoleto'] ?? '0'),
            paidValue:      $this->toCents($data['value_cents_paid']),
            paidAt:         new DateTime($data['dataPagamento']),
            status:         $this->normalizeBoletoStatus($data['status']),
            type:           'boleto',
        );
    }

    /**
     *
     * Converte um valor monetário em reais (ex.: "548.95") para centavos.
     *
     */
    private function toCents(string $amount): int
    {
        return intval(round(floatval($amount) * 100));
    }

    /**
     *
     * Traduz o status em português do boleto para o vocabulário canônico
     * usado pelo restante do pipeline (alinhado ao retorno do PIX).
     *
     */
    private function normalizeBoletoStatus(string $status): string
    {
        return match (mb_strtolower($status)) {
            'aprovado', 'pago'                                    => 'completed',
            'pendente', 'aguardando', 'em análise', 'processando' => 'pending',
            'cancelado'                                           => 'canceled',
            'estornado', 'devolvido', 'reembolsado'               => 'refunded',
            'reprovado', 'recusado'                               => 'failed',
            default                                               => mb_strtolower($status),
        };
    }

}
