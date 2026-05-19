<?php

namespace App\DTOs\Payments;

class BoletoChargeDataDTO
{
    public function __construct(
        public readonly string  $provider,
        public readonly string  $providerMethod,
        public readonly string  $providerTransactionId,
        public readonly string  $status,
        public readonly string  $providerMessage,
        public readonly ?string $digitableLine,
        public readonly ?string $urlSlip,
        public readonly ?string $urlSlipPdf,
        public readonly ?string $dueDate,
        public readonly array   $rawResponse,
    ) {
    }
}
