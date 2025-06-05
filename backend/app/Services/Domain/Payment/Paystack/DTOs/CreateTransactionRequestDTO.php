<?php

namespace HiEvents\Services\Domain\Payment\Paystack\DTOs;

use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\Values\MoneyValue;

readonly class CreateTransactionRequestDTO
{
    public function __construct(
        public MoneyValue $amount,
        public string $currencyCode,
        public string $customerEmail,
        public string $callbackUrl,
        public OrderDomainObject $order,
        public AccountDomainObject $account,
    ) {
    }

    public function toArray(array $exclude = []): array
    {
        $data = [
            'amount' => $this->amount->toMinorUnit(),
            'currencyCode' => $this->currencyCode,
            'customerEmail' => $this->customerEmail,
            'callbackUrl' => $this->callbackUrl,
            'order' => $this->order->toArray(),
            'account' => in_array('account', $exclude) ? null : $this->account->toArray(),
        ];

        return array_filter($data, fn($value) => $value !== null);
    }
} 