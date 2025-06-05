<?php

namespace HiEvents\Http\Actions\Orders\Payment\Paystack;

use HiEvents\Services\Domain\Payment\Paystack\PaystackTransactionService;
use Illuminate\Http\Request;
use HiEvents\Services\Domain\Payment\Paystack\DTOs\CreateTransactionRequestDTO;
use HiEvents\Services\Domain\Payment\Paystack\DTOs\CreateTransactionResponseDTO;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use HiEvents\Mail\Order\OrderSummary;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use Illuminate\Support\Facades\Mail;
use Illuminate\Http\RedirectResponse;

class CreatePaystackTransactionActionPublic
{
    public function __construct(private PaystackTransactionService $paystackTransactionService) {}

    public function __invoke(Request $request, $event_id, $order_short_id)
    {
        $orderRepository = app(\HiEvents\Repository\Interfaces\OrderRepositoryInterface::class);
        $accountRepository = app(\HiEvents\Repository\Interfaces\AccountRepositoryInterface::class);
        $accountConfigRepository = app(\HiEvents\Repository\Interfaces\AccountConfigurationRepositoryInterface::class);

        $order = $orderRepository->findByShortId($order_short_id);
        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        // Eager load configuration relation for account
        if (method_exists($accountRepository, 'loadRelation')) {
            $account = $accountRepository
                ->loadRelation(new \HiEvents\Repository\Eloquent\Value\Relationship(
                    domainObject: \HiEvents\DomainObjects\AccountConfigurationDomainObject::class,
                    name: 'configuration',
                ))
                ->findByEventId((int)$event_id);
        } else {
            $account = $accountRepository->findByEventId((int)$event_id);
        }

        if (!$account) {
            return response()->json(['message' => 'Account not found.'], 404);
        }

        // If configuration is still null, fetch and set it manually
        if ($account->getConfiguration() === null && $account->getAccountConfigurationId()) {
            $config = $accountConfigRepository->findById($account->getAccountConfigurationId());
            if ($config) {
                $account->setConfiguration($config);
            }
        }

        // Optionally, verify session or order status as in Stripe handler
        // if (!$order || !$this->sessionIdentifierService->verifySession($order->getSessionId())) { ... }

        $callbackUrl = config('app.frontend_url') . "/payment/callback/paystack?event_id={$event_id}&order_id={$order_short_id}";

        $transactionDTO = new CreateTransactionRequestDTO(
            \HiEvents\Values\MoneyValue::fromFloat($order->getTotalGross(), $order->getCurrency()),
            $order->getCurrency(),
            $order->getEmail(),
            $callbackUrl,
            $order,
            $account
        );
		
        try {
			$response = $this->paystackTransactionService->createTransaction($transactionDTO);
        } catch (\Throwable $e) {
			// log("PRes: ".var_dump($e));
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($response);
    }
}

