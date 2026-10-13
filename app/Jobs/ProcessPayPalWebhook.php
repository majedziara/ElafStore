<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\PaymentAttempt;
use App\Models\WebhookEvent;
use App\Services\Payments\PaymentService;
use App\Services\Payments\RefundService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ProcessPayPalWebhook implements ShouldQueue
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
        Cache::lock('webhook:'.$this->eventId, 180)->block(5, function () use ($payments, $refunds) {
            $event = WebhookEvent::findOrFail($this->eventId);
            if ($event->processed_at) {
                return;
            }
            $resource = $event->payload['resource'];
            if ($event->type === 'PAYMENT.CAPTURE.REFUNDED') {
                $refunds->import($event->environment, $resource['id']);
            } else {
                $providerId = $event->type === 'CHECKOUT.ORDER.APPROVED' ? ($resource['id'] ?? null) : data_get($resource, 'supplementary_data.related_ids.order_id');
                $a = $providerId ? PaymentAttempt::where('environment', $event->environment)->where('provider_order_id', $providerId)->first() : null;
                if (! $a && isset($resource['id'])) {
                    $a = PaymentAttempt::where('environment', $event->environment)->where('capture_id', $resource['id'])->first();
                }
                if (! $a && $providerId && $event->type === 'CHECKOUT.ORDER.APPROVED') {
                    $uuid = data_get($resource, 'purchase_units.0.custom_id');
                    if ($uuid) {
                        $a = PaymentAttempt::where('environment', $event->environment)->whereNull('provider_order_id')
                            ->whereHas('order', fn ($q) => $q->where('uuid', $uuid))->latest('id')->first();
                        if ($a) {
                            $payments->attach($a, $providerId);
                        }
                    }
                }
                if ($a) {
                    $payments->sync($a, true);
                    if ($event->type === 'PAYMENT.CAPTURE.REVERSED') {
                        DB::transaction(function () use ($a) {
                            $o = Order::whereKey($a->order_id)->lockForUpdate()->firstOrFail();
                            $o->payment_status = 'reversed';
                            if (! $o->shipped_at) {
                                $o->fulfillment_status = 'on_hold';
                            } $o->save();
                            OrderEvent::create(['order_id' => $o->id, 'type' => 'payment_reversed', 'message' => 'Provider reported a payment reversal.']);
                        });
                    }
                }
            }
            $event->update(['processed_at' => now()]);
        });
    }
}
