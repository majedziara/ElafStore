<?php

namespace App\Console\Commands;

use App\Jobs\ProcessGatewayWebhook;
use App\Jobs\ProcessPayPalWebhook;
use App\Models\LinkToken;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use App\Models\WebhookEvent;
use App\Services\OrderService;
use App\Services\Payments\PaymentService;
use App\Services\Payments\RefundService;
use Illuminate\Console\Command;

class MaintainStore extends Command
{
    protected $signature = 'store:maintain';

    protected $description = 'Reconcile payments/refunds and release only safe expired reservations';

    public function handle(PaymentService $payments, RefundService $refunds, OrderService $orders): int
    {
        PaymentAttempt::whereNotNull('provider_order_id')->whereIn('status', ['creating', 'awaiting_approval', 'approved', 'capture_pending', 'unknown', 'expired'])
            ->where(function ($q) {
                $q->whereNull('last_checked_at')->orWhere('last_checked_at', '<', now()->subMinutes(5));
            })
            ->where(function ($q) {
                $q->where('status', '!=', 'expired')->orWhere('created_at', '>', now()->subDays(3));
            })
            ->orderByRaw('COALESCE(last_checked_at, created_at) ASC')->limit(30)->get()->each(function ($a) use ($payments) {
                try {
                    $payments->sync($a, true);
                } catch (\Throwable $e) {
                    report($e);
                }
            });
        Refund::whereNotNull('provider_refund_id')->whereIn('status', ['pending', 'unknown'])->orderBy('id')->limit(30)->get()
            ->each(function ($r) use ($refunds) {
                try {
                    $refunds->sync($r);
                } catch (\Throwable $e) {
                    report($e);
                }
            });
        Order::whereNull('paid_at')->whereNull('canceled_at')->where('reservation_expires_at', '<', now())->whereDoesntHave('payments')
            ->orderBy('id')->limit(100)->get()->each(function ($o) use ($orders) {
                try {
                    $orders->cancel($o);
                } catch (\Throwable $e) {
                    report($e);
                }
            });
        LinkToken::where('expires_at', '<', now()->subDay())->delete();
        WebhookEvent::whereNull('processed_at')->where('created_at', '<', now()->subMinutes(5))->orderBy('id')->limit(30)->get()
            ->each(fn ($e) => $e->provider === 'paypal' ? ProcessPayPalWebhook::dispatch($e->id) : ProcessGatewayWebhook::dispatch($e->id));

        return self::SUCCESS;
    }
}
