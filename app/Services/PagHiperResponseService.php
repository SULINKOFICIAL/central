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
    public function process(array $data): ?PagHiperDTO
    {

        /**
         * Para notificações recebidas pela PagHiper PIX, tem url pré definida.
         */
        $type = $data['source_api'] == 'https://pix.paghiper.com' ? 'pix' : 'boleto';

        /**
         * Consulta a notificação completa na API da PagHiper.
         */
        $notification = $this->pagHiperService->notification($data['source_api'], $data['transaction_id'], $data['notification_id']);

        /**
         * Extrai corpo 
         */
        $notification = $notification['status_request'];

        return new PagHiperDTO(
            transactionId:  $notification['transaction_id'],
            orderId:        $notification['order_id'],
            value:          $notification['value_cents'],
            valueFee:       $notification['value_fee_cents'],
            discount:       $notification['discount_cents'],
            paidValue:      $notification['value_cents_paid'],
            paidAt:         new DateTime($notification['paid_date']),
            status:         $notification['status'],
            type:           $type,
        );
    }

}
