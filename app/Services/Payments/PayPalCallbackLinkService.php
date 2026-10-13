<?php

namespace App\Services\Payments;

use App\Models\PaymentAttempt;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use JsonException;

final class PayPalCallbackLinkService
{
    public function url(string $action, string $attemptUuid): string
    {
        abort_unless(in_array($action, ['return', 'cancel'], true), 404);
        $url = config('store.paypal.'.$action.'_url');
        $site = parse_url(config('store.url'));
        $target = is_string($url) ? parse_url($url) : false;
        abort_unless(is_array($site) && is_array($target) && in_array($target['scheme'] ?? '', ['http', 'https'], true) &&
         strtolower($target['host'] ?? '') === strtolower($site['host'] ?? '') && ($target['scheme'] ?? null) === ($site['scheme'] ?? null) &&
         ($target['port'] ?? ($target['scheme'] === 'https' ? 443 : 80)) === ($site['port'] ?? ($site['scheme'] === 'https' ? 443 : 80)) &&
         ($target['path'] ?? '') === rtrim($site['path'] ?? '', '/').'/api/paypal/'.$action &&
         ! isset($target['query']) && ! isset($target['fragment']) && ! isset($target['user']) && ! isset($target['pass']), 503, 'PayPal callback URLs must point to this application.');
        $state = Crypt::encryptString(json_encode(['attempt' => $attemptUuid, 'action' => $action, 'expires_at' => now()->addMinutes(config('store.paypal.callback_minutes'))->timestamp], JSON_THROW_ON_ERROR));

        return $url.'?'.http_build_query(['state' => $state], '', '&', PHP_QUERY_RFC3986);
    }

    public function resolve(Request $request, string $action): PaymentAttempt
    {
        $data = $request->validate(['state' => 'required|string|max:4096', 'token' => ($action === 'return' ? 'required' : 'sometimes').'|string|max:120']);
        try {
            $state = json_decode(Crypt::decryptString($data['state']), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            abort(404, 'Invalid payment callback.');
        }
        abort_unless(is_array($state) && ($state['action'] ?? null) === $action && is_string($state['attempt'] ?? null) && is_int($state['expires_at'] ?? null), 404, 'Invalid payment callback.');
        abort_if($state['expires_at'] <= now()->timestamp, 410, 'Payment callback expired.');
        $attempt = PaymentAttempt::where('uuid', $state['attempt'])->where('gateway', 'paypal')->firstOrFail();
        abort_unless(is_string($attempt->provider_order_id) && $attempt->provider_order_id !== '', 404, 'Payment callback is not ready.');
        if (isset($data['token'])) {
            abort_unless(hash_equals($attempt->provider_order_id, $data['token']), 404, 'PayPal order does not match this callback.');
        }

        return $attempt;
    }
}
