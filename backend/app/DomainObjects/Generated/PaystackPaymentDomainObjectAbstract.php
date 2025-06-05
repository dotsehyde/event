<?php

namespace HiEvents\DomainObjects\Generated;

abstract class PaystackPaymentDomainObjectAbstract extends \HiEvents\DomainObjects\AbstractDomainObject
{
    final public const SINGULAR_NAME = 'paystack_payment';
    final public const PLURAL_NAME = 'paystack_payments';
    final public const ID = 'id';
    final public const ORDER_ID = 'order_id';
    final public const REFERENCE = 'reference';
    final public const ACCESS_CODE = 'access_code';
    final public const AUTHORIZATION_URL = 'authorization_url';
    final public const CONNECTED_ACCOUNT_ID = 'connected_account_id';
    final public const CREATED_AT = 'created_at';
    final public const UPDATED_AT = 'updated_at';
    final public const DELETED_AT = 'deleted_at';

    protected int $id;
    protected int $order_id;
    protected string $reference;
    protected string $access_code;
    protected string $authorization_url;
    protected ?string $connected_account_id = null;
    protected ?string $created_at = null;
    protected ?string $updated_at = null;
    protected ?string $deleted_at = null;
} 