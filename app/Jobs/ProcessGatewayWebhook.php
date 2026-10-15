<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use App\Models\WebhookEvent;
use App\Services\Payments\PaymentService;
use App\Services\Payments\RefundService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ProcessGatewayWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public int $timeout = 120;

    public function __construct(public int $eventId) {}

    public function backoff(): array
    {
        return [15, 30, 60, 120, 300, 600, 1200];
    }

    public function handle(PaymentService $payments, RefundService $refunds): void
    {
        Cache::lock('webhook:'.$this->eventId, 180)->block(5, function () use ($payments, $refunds): void {
            $event = WebhookEvent::findOrFail($this->eventId);
            if ($event->processed_at) {
                return;
            }
            $query = PaymentAttempt::where('gateway', $event->provider)->where('environment', $event->environment);
            if ($event->provider === 'stripe') {
                $resource = data_get($event->payload, 'data.object', []);
                if (in_array($event->type, ['refund.created', 'refund.updated', 'refund.failed'], true)) {
                    $refunds->import($event->environment, $resource['id'], 'stripe');
                    $event->update(['processed_at' => now()]);

                    return;
                }
                $providerId = str_starts_with($event->type, 'checkout.session.') ? ($resource['id'] ?? null) : null;
                $captureId = str_starts_with($event->type, 'payment_intent.') ? ($resource['id'] ?? null) : ($resource['payment_intent'] ?? null);
                $attempt = $providerId ? (clone $query)->where('provider_order_id', $providerId)->first() : null;
                $attempt ??= is_string($captureId) ? (clone $query)->where('capture_id', $captureId)->first() : null;
                $attemptUuid = data_get($resource, 'metadata.attempt_uuid');
                if (! $attempt && is_string($attemptUuid)) {
                    $attempt = (clone $query)->where('uuid', $attemptUuid)->first();
                    if ($attempt && $providerId && ! $attempt->provider_order_id) {
                        $payments->attach($attempt, $providerId);
                    }
                }
                if (! $attempt && is_string($captureId)) {
                    $attempt = (clone $query)->whereHas('order', fn ($orders) => $orders->where('uuid', data_get($resource, 'metadata.order_uuid')))->first();
                }
            } else {
                if ($event->type === 'payout') {
                    $refund = Refund::where('provider_refund_id', $event->payload['payout_reference'])->whereHas('paymentAttempt', fn ($attempts) => $attempts->where('gateway', 'triple-a')->where('environment', $event->environment))->first();
                    if (! $refund && ($event->payload['type'] ?? null) === 'refund' && preg_match('/^Store refund ([a-f0-9-]{36})$/D', $event->payload['remarks'] ?? '', $matches)) {
                        $refund = Refund::where('request_key', $matches[1])->whereNull('provider_refund_id')->whereHas('paymentAttempt', fn ($attempts) => $attempts->where('gateway', 'triple-a')->where('environment', $event->environment))->first();
                        if ($refund) {
                            $refunds->attach($refund, $event->payload['payout_reference']);
                        }
                    }
                    if ($refund) {
                        $refunds->sync($refund);
                    }
                    $event->update(['processed_at' => now()]);

                    return;
                }
                $providerId = $event->payload['payment_reference'];
                $attempt = (clone $query)->where('provider_order_id', $providerId)->first();
                if (! $attempt && is_string(data_get($event->payload, 'webhook_data.attempt_uuid'))) {
                    $attempt = (clone $query)->where('uuid', data_get($event->payload, 'webhook_data.attempt_uuid'))->first();
                    if ($attempt) {
                        $payments->attach($attempt, $providerId);
                    }
                }
            }
            if ($attempt) {
                $payments->sync($attempt, false);
                if ($event->provider === 'stripe' && $event->type === 'charge.dispute.created') {
                    DB::transaction(function () use ($attempt): void {
                        $order = Order::whereKey($attempt->order_id)->lockForUpdate()->firstOrFail();
                        if ($order->paid_at) {
                            $order->update(['payment_status' => 'reversed', 'fulfillment_status' => $order->shipped_at ? $order->fulfillment_status : 'on_hold']);
                            OrderEvent::create(['order_id' => $order->id, 'type' => 'payment_reversed', 'message' => 'Stripe reported a payment dispute.']);
                        }
                    });
                }
            }
            $event->update(['processed_at' => now()]);
        });
    }
}
