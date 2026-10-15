<?php

namespace App\Services\Payments;

use App\Models\PaymentAttempt;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use JsonException;

final class PaymentCallbackLinkService
{
    public function url(string $gateway, string $action, string $attemptUuid): string
    {
        abort_unless(in_array($gateway, ['stripe', 'triple-a'], true) && in_array($action, ['return', 'cancel'], true), 404);
        $state = Crypt::encryptString(json_encode([
            'gateway' => $gateway, 'attempt' => $attemptUuid, 'action' => $action,
            'expires_at' => now()->addMinutes(120)->timestamp,
        ], JSON_THROW_ON_ERROR));

        return config('store.url').'/api/payments/'.$gateway.'/'.$action.'?'.http_build_query(['state' => $state], '', '&', PHP_QUERY_RFC3986);
    }

    public function resolve(Request $request, string $gateway, string $action): PaymentAttempt
    {
        $data = $request->validate(['state' => 'required|string|max:4096']);
        try {
            $state = json_decode(Crypt::decryptString($data['state']), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            abort(404, 'Invalid payment callback.');
        }
        abort_unless(is_array($state) && ($state['gateway'] ?? null) === $gateway && ($state['action'] ?? null) === $action && is_string($state['attempt'] ?? null) && is_int($state['expires_at'] ?? null), 404, 'Invalid payment callback.');
        abort_if($state['expires_at'] <= now()->timestamp, 410, 'Payment callback expired.');
        $attempt = PaymentAttempt::where('uuid', $state['attempt'])->where('gateway', $gateway)->firstOrFail();
        abort_unless(is_string($attempt->provider_order_id) && $attempt->provider_order_id !== '', 404, 'Payment callback is not ready.');

        return $attempt;
    }
}
