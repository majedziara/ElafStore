<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessGatewayWebhook;
use App\Jobs\ProcessPayPalWebhook;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use App\Models\WebhookEvent;
use App\Services\Payments\PaymentService;
use App\Services\Payments\RefundService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function index()
    {
        return PaymentAttempt::with('order')->latest('id')->paginate(25);
    }

    public function sync(PaymentAttempt $attempt, PaymentService $s)
    {
        return ['data' => $s->sync($attempt, true)];
    }

    public function refund(Request $r, Order $order, RefundService $s)
    {
        $d = $r->validate(['request_key' => 'required|uuid', 'amount_cents' => 'required|integer|min:1|max:100000000', 'reason' => 'required|string|max:180',
            'return_request_id' => 'nullable|integer|exists:return_requests,id']);

        return ['data' => $s->create($order, $d, $r->user()->id)];
    }

    public function syncRefund(Refund $refund, RefundService $s)
    {
        return ['data' => $s->sync($refund)];
    }

    public function webhooks()
    {
        return WebhookEvent::select(['id', 'provider', 'environment', 'provider_event_id', 'type', 'processed_at', 'created_at'])->latest('id')->paginate(25);
    }

    public function retry(WebhookEvent $event)
    {
        if (! $event->processed_at) {
            $event->provider === 'paypal' ? ProcessPayPalWebhook::dispatch($event->id) : ProcessGatewayWebhook::dispatch($event->id);
        }

        return ['message' => 'Processing requested.'];
    }
}
