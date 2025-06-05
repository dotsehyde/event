<?php

namespace HiEvents\Services\Domain\Payment\Paystack\DTOs;

readonly class CreateTransactionResponseDTO
{
    public function __construct(
        public string $reference,
        public string $accessCode,
        public string $authorizationUrl,
        public ?string $accountId = null,
        public int $applicationFeeAmount = 0,
    ) {
    }
} 