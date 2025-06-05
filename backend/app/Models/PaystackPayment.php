<?php

namespace HiEvents\Models;

use HiEvents\DomainObjects\Generated\PaystackPaymentDomainObjectAbstract;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaystackPayment extends BaseModel
{
    use SoftDeletes;

    protected function getTimestampsEnabled(): bool
    {
        return false;
    }

    protected function getCastMap(): array
    {
        return [
            'last_error' => 'array',
        ];
    }

    protected function getFillableFields(): array
    {
        return [
            'amount',
            'currency',
            'created_at',
            'updated_at',
            // 'sub_account',
            PaystackPaymentDomainObjectAbstract::ORDER_ID,
            PaystackPaymentDomainObjectAbstract::REFERENCE,
            PaystackPaymentDomainObjectAbstract::ACCESS_CODE,
            PaystackPaymentDomainObjectAbstract::AUTHORIZATION_URL,
            PaystackPaymentDomainObjectAbstract::CONNECTED_ACCOUNT_ID,
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
} 