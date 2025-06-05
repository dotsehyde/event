<?php

namespace HiEvents\Http\Actions\Orders\Payment\Paystack;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use HiEvents\Mail\Order\OrderSummary;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use Illuminate\Support\Facades\Mail;
use Illuminate\Http\RedirectResponse;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Services\Domain\Order\OrderApplicationFeeService;
use HiEvents\Services\Domain\Order\OrderApplicationFeeCalculationService;

class PaystackCallbackAction
{
    public function __invoke(Request $request, $event_id, $order_short_id): RedirectResponse
    {
        $orderRepository = app(\HiEvents\Repository\Interfaces\OrderRepositoryInterface::class);
        $eventRepository = app(EventRepositoryInterface::class);
        $attendeeRepository = app(\HiEvents\Repository\Interfaces\AttendeeRepositoryInterface::class);
        $order = $orderRepository->findByShortId($order_short_id);
        if (!$order) {
            return redirect()->to(config('app.frontend_url') . '/checkout/' . $event_id . '/' . $order_short_id . '/summary?status=failed');
        }

        $reference = $request->query('reference');
        if (!$reference) {
            return redirect()->to(config('app.frontend_url') . '/checkout/' . $event_id . '/' . $order_short_id . '/summary?status=failed');
        }

        try {
            $paystack = new \Yabacon\Paystack(config('services.paystack.secret_key'));
            $tranx = $paystack->transaction->verify([ 'reference' => $reference ]);
        } catch (\Throwable $e) {
            Log::error('Paystack verification failed', ['error' => $e->getMessage(), 'reference' => $reference]);
            return redirect()->to(config('app.frontend_url') . '/checkout/' . $event_id . '/' . $order_short_id . '/summary?status=failed');
        }

        if (!$tranx->status || $tranx->data->status !== 'success') {
            Log::warning('Paystack transaction not successful', ['reference' => $reference, 'paystack_status' => $tranx->data->status ?? null]);
            return redirect()->to(config('app.frontend_url') . '/checkout/' . $event_id . '/' . $order_short_id . '/summary?status=failed');
        }

        // Mark the order as paid
        $order->setPaymentStatus(\HiEvents\DomainObjects\Status\OrderPaymentStatus::PAYMENT_RECEIVED->name);
        $order->setStatus(\HiEvents\DomainObjects\Status\OrderStatus::COMPLETED->name);
        $orderRepository->updateFromDomainObject($order->getId(), $order);

        // Dispatch event to update event statistics (like Stripe)
        event(new \HiEvents\Events\OrderStatusChangedEvent($order));

        // Create application fee record for Paystack (like Stripe)
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

        // Send order confirmation email (like Stripe)
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
        } catch (\Throwable $e) {
            Log::error('Order confirmation email failed to send', [
                'error' => $e->getMessage(),
                'order_id' => $order->getId(),
                'email' => $order->getEmail(),
            ]);
        }

        // Redirect to frontend summary page with success status
        return redirect()->to(config('app.frontend_url') . '/checkout/' . $event_id . '/' . $order_short_id . '/summary?status=success');
    }
} 