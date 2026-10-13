<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentAttempt;
use App\Services\OrderAccessService;
use App\Services\Payments\PaymentService;
use App\Services\Payments\PayPalCallbackLinkService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayPalCallbackController extends Controller
{
    public function __construct(private PayPalCallbackLinkService $links, private PaymentService $payments, private OrderAccessService $access) {}

    public function returned(Request $request): JsonResponse
    {
        return $this->handle($request, 'return', true);
    }

    public function canceled(Request $request): JsonResponse
    {
        return $this->handle($request, 'cancel', false);
    }

    private function handle(Request $request, string $action, bool $captureApproved): JsonResponse
    {
        $attempt = $this->links->resolve($request, $action);
        $unavailable = false;
        try {
            $attempt = $this->payments->sync($attempt, $captureApproved);
        } catch (ConnectionException|RequestException|LockTimeoutException $e) {
            report($e);
            $unavailable = true;
        }

        return $this->result($attempt, $action, $unavailable);
    }

    private function result(PaymentAttempt $attempt, string $action, bool $unavailable): JsonResponse
    {
        $order = $attempt->order()->firstOrFail();
        $pending = $unavailable || ! in_array($order->payment_status, ['paid', 'partially_refunded', 'refunded', 'failed', 'canceled', 'expired', 'reversed'], true);
        $message = $unavailable ? 'Payment verification is temporarily unavailable. Please check the order again.' : match ($order->payment_status) {
            'paid','partially_refunded' => 'Payment confirmed.', 'refunded' => 'Payment has been refunded.',
            'canceled' => 'The payment was canceled.', 'expired' => 'The payment window has expired.',
            'failed','reversed' => 'Payment was not completed.',
            default => $action === 'cancel' ? 'You returned from checkout. No completed payment has been confirmed.' : 'Payment has not been confirmed yet.',
        };

        return response()->json(['message' => $message, 'data' => [
            'order_uuid' => $order->uuid, 'reference' => $order->reference, 'payment_status' => $order->payment_status,
            'attempt_status' => $attempt->fresh()->status, 'verification_pending' => $pending, 'checkout_canceled' => $action === 'cancel',
            'currency' => $order->currency, 'total_cents' => $order->total_cents, 'refunded_cents' => $order->refunded_cents,
            'receipt_available' => (bool) $order->paid_at, 'order_url' => config('store.url').'/api/v1/orders/'.$order->uuid,
            'order_token' => $this->access->grant($order),
        ]], $pending ? 202 : 200)->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }
}
