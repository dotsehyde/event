<?php

namespace HiEvents\Services\Domain\Payment\Paystack;

use HiEvents\DomainObjects\PaystackPaymentDomainObject;
use HiEvents\Services\Domain\Order\OrderApplicationFeeCalculationService;
use HiEvents\Services\Domain\Payment\Paystack\DTOs\CreateTransactionRequestDTO;
use HiEvents\Services\Domain\Payment\Paystack\DTOs\CreateTransactionResponseDTO;
use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use Throwable;

class PaystackTransactionService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Repository $config,
        private readonly DatabaseManager $databaseManager,
        private readonly OrderApplicationFeeCalculationService $orderApplicationFeeCalculationService,
    ) {
    }

    /**
     * @throws Throwable
     */
    public function createTransaction(CreateTransactionRequestDTO $transactionDTO): CreateTransactionResponseDTO
    {
        try {
            $this->databaseManager->beginTransaction();

            $applicationFee = $this->orderApplicationFeeCalculationService->calculateApplicationFee(
                accountConfiguration: $transactionDTO->account->getConfiguration(),
                order: $transactionDTO->order,
            )->toMinorUnit();

            // Initialize Paystack transaction
            $paystack = new \Yabacon\Paystack($this->config->get('services.paystack.secret_key'));
            
            $tranx = $paystack->transaction->initialize([
                'amount' => $transactionDTO->amount->toMinorUnit(),
                'email' => $transactionDTO->customerEmail,
                'currency' => $transactionDTO->currencyCode,
                'callback_url' => $transactionDTO->callbackUrl,
                'metadata' => [
                    'order_id' => $transactionDTO->order->getId(),
                    'event_id' => $transactionDTO->order->getEventId(),
                    'order_short_id' => $transactionDTO->order->getShortId(),
                    'account_id' => $transactionDTO->account->getId(),
                ],
            ]);

            if (!$tranx->status) {
                throw new \Exception($tranx->message);
            }

            // Log reference and order_id to the database
            $paystackPaymentModel = \HiEvents\Models\PaystackPayment::class;
            $paystackPaymentModel::create([
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
                // 'sub_account'=>null,
                'amount' => $transactionDTO->amount->toMinorUnit(),
                'currency' => $transactionDTO->currencyCode,
                'order_id' => $transactionDTO->order->getId(),
                'reference' => $tranx->data->reference,
                'access_code' => $tranx->data->access_code,
                'authorization_url' => $tranx->data->authorization_url,
                'connected_account_id' => $transactionDTO->account->getPaystackAccountId(),
            ]);

            $this->logger->info('PaystackPayment created', [
                'order_id' => $transactionDTO->order->getId(),
                'reference' => $tranx->data->reference,
            ]);

            $this->databaseManager->commit();

            return new CreateTransactionResponseDTO(
                reference: $tranx->data->reference,
                accessCode: $tranx->data->access_code,
                authorizationUrl: $tranx->data->authorization_url,
                accountId: $transactionDTO->account->getPaystackAccountId(),
                applicationFeeAmount: $applicationFee,
            );
        } catch (Throwable $exception) {
            $this->logger->error("Paystack transaction initialization failed: {$exception->getMessage()}", [
                'exception' => $exception,
                'transactionDTO' => $transactionDTO->toArray(['account']),
            ]);

            $this->databaseManager->rollBack();

            throw $exception;
        }
    }
} 