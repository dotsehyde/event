<?php

namespace HiEvents\Http\Actions\Common\Webhooks;

use HiEvents\Http\Actions\BaseAction;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Services\Domain\Order\OrderApplicationFeeService;
use HiEvents\Services\Domain\Order\OrderApplicationFeeCalculationService;
use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Mail\Order\OrderSummary;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class PaystackIncomingWebhookAction extends BaseAction
{
    public function __invoke(Request $request): Response
    {
        try {
            $payload = json_decode($request->getContent(), true);
            if (!$payload || !isset($payload['event']) || !isset($payload['data'])) {
                logger()?->error('Invalid Paystack webhook payload', ['payload' => $payload]);
                return $this->noContentResponse(400);
            }

            // Only handle successful charge events
            if ($payload['event'] !== 'charge.success') {
                return $this->noContentResponse();
            }

            $reference = $payload['data']['reference'] ?? null;
            if (!$reference) {
                logger()?->error('Paystack webhook missing reference', ['payload' => $payload]);
                return $this->noContentResponse(400);
            }

            $orderRepository = app(OrderRepositoryInterface::class);
            $eventRepository = app(EventRepositoryInterface::class);
            $attendeeRepository = app(AttendeeRepositoryInterface::class);
            $order = $orderRepository->findFirstWhere(['paystack_reference' => $reference]);
            if (!$order) {
                // Fallback: try by order_id if present in metadata
                $orderId = $payload['data']['metadata']['order_id'] ?? null;
                if ($orderId) {
                    $order = $orderRepository->findById($orderId);
                }
            }
            if (!$order) {
                logger()?->error('Order not found for Paystack webhook', ['reference' => $reference, 'payload' => $payload]);
                return $this->noContentResponse(400);
            }

            // Mark the order as paid
            $order->setPaymentStatus(\HiEvents\DomainObjects\Status\OrderPaymentStatus::PAYMENT_RECEIVED->name);
            $order->setStatus(\HiEvents\DomainObjects\Status\OrderStatus::COMPLETED->name);
            $orderRepository->updateFromDomainObject($order->getId(), $order);

            // Create application fee record for Paystack
            $orderApplicationFeeService = app(OrderApplicationFeeService::class);
            $orderApplicationFeeCalculationService = app(OrderApplicationFeeCalculationService::class);
            $eventForFee = $eventRepository
                ->loadRelation(new Relationship(\HiEvents\DomainObjects\AccountDomainObject::class, nested: [
                    new Relationship(\HiEvents\DomainObjects\AccountConfigurationDomainObject::class, name: 'configuration'),
                ], name: 'account'))
                ->findById($order->getEventId());
            $accountConfig = $eventForFee->getAccount()?->getConfiguration();
            if ($accountConfig) {
                $applicationFeeAmount = $orderApplicationFeeCalculationService->calculateApplicationFee(
                    accountConfiguration: $accountConfig,
                    order: $order,
                )->toMinorUnit();
                $orderApplicationFeeService->createOrderApplicationFee(
                    orderId: $order->getId(),
                    applicationFeeAmountMinorUnit: $applicationFeeAmount,
                    orderApplicationFeeStatus: \HiEvents\DomainObjects\Status\OrderApplicationFeeStatus::PAID,
                    paymentMethod: \HiEvents\DomainObjects\Enums\PaymentProviders::PAYSTACK,
                    currency: $order->getCurrency(),
                );
            }

            // Update attendee statuses
            $attendeeRepository->updateWhere(
                attributes: [
                    'status' => \HiEvents\DomainObjects\Status\AttendeeStatus::ACTIVE->name,
                ],
                where: [
                    'order_id' => $order->getId(),
                    'status' => \HiEvents\DomainObjects\Status\AttendeeStatus::AWAITING_PAYMENT->name,
                ],
            );

            // Send order confirmation email
            $event = $eventRepository
                ->loadRelation(new Relationship(\HiEvents\DomainObjects\EventSettingDomainObject::class))
                ->loadRelation(new Relationship(\HiEvents\DomainObjects\OrganizerDomainObject::class, name: 'organizer'))
                ->findById($order->getEventId());
            $eventSettings = $event->getEventSettings();
            $organizer = $event->getOrganizer();
            $invoice = method_exists($order, 'getLatestInvoice') ? $order->getLatestInvoice() : null;
            try {
                Mail::to($order->getEmail())
                    ->locale($order->getLocale())
                    ->send(new OrderSummary(
                        order: $order,
                        event: $event,
                        organizer: $organizer,
                        eventSettings: $eventSettings,
                        invoice: $invoice,
                    ));
            } catch (\Throwable $exception) {
                Log::error('Order confirmation email failed to send', [
                    'error' => $exception->getMessage(),
                    'order_id' => $order->getId(),
                    'email' => $order->getEmail(),
                ]);
            }

            // Dispatch event to update event statistics and trigger listeners
            event(new OrderStatusChangedEvent($order));

            return $this->noContentResponse();
        } catch (\Throwable $exception) {
            logger()?->error($exception->getMessage(), $exception->getTrace());
            return $this->noContentResponse(400);
        }
    }
} 