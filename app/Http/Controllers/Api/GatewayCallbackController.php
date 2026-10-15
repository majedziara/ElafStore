<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OrderAccessService;
use App\Services\Payments\PaymentCallbackLinkService;
use App\Services\Payments\PaymentService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GatewayCallbackController extends Controller
{
    public function __invoke(Request $request, string $gateway, string $action, PaymentCallbackLinkService $links, PaymentService $payments, OrderAccessService $access): JsonResponse
    {
        $attempt = $links->resolve($request, $gateway, $action);
        $unavailable = false;
        try {
            $attempt = $payments->sync($attempt, false);
        } catch (ConnectionException|RequestException|LockTimeoutException $exception) {
            report($exception);
            $unavailable = true;
        }
        $order = $attempt->order()->firstOrFail();
        $pending = $unavailable || ! in_array($order->payment_status, ['paid', 'partially_refunded', 'refunded', 'failed', 'canceled', 'expired', 'reversed'], true);

        return response()->json(['data' => [
            'order_uuid' => $order->uuid, 'reference' => $order->reference, 'payment_status' => $order->payment_status,
            'attempt_status' => $attempt->fresh()->status, 'verification_pending' => $pending, 'checkout_canceled' => $action === 'cancel',
            'order_token' => $access->grant($order),
        ]], $pending ? 202 : 200)->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }
}
