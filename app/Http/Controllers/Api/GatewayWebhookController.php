<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessGatewayWebhook;
use App\Models\WebhookEvent;
use App\Services\Payments\GatewayRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GatewayWebhookController extends Controller
{
    public function __invoke(Request $request, string $gateway, string $environment, GatewayRegistry $gateways): JsonResponse
    {
        abort_if(strlen($request->getContent()) > 262144, 413);
        $config = $gateways->configuration($gateway, $environment);
        $header = $request->header($gateway === 'stripe' ? 'Stripe-Signature' : 'Triplea-Signature', '');
        $secret = $config['credentials'][$gateway === 'stripe' ? 'webhook_secret' : 'notify_secret'];
        $this->verify($request->getContent(), $header, $secret);
        $payload = $request->json()->all();
        if ($gateway === 'stripe') {
            $data = $request->validate(['id' => 'required|string|max:120', 'type' => 'required|string|max:120', 'data.object' => 'required|array', 'livemode' => 'required|boolean']);
            abort_unless($data['livemode'] === ($environment === 'live'), 400, 'Webhook environment mismatch.');
            $id = $data['id'];
            $type = $data['type'];
        } else {
            $data = $request->validate(['event' => 'required|in:payment,payout', 'payment_reference' => 'required_without:payout_reference|string|max:120',
                'payout_reference' => 'required_without:payment_reference|string|max:120', 'status' => 'required|string|max:80']);
            if (isset($payload['merchant_key'])) {
                abort_unless($payload['merchant_key'] === $config['credentials']['merchant_key'], 400, 'Webhook merchant mismatch.');
            }
            $id = hash('sha256', $request->getContent());
            $type = $data['event'];
        }
        $event = WebhookEvent::firstOrCreate(['provider' => $gateway, 'environment' => $environment, 'provider_event_id' => $id], ['type' => $type, 'payload' => $payload]);
        if (! $event->processed_at) {
            ProcessGatewayWebhook::dispatch($event->id);
        }

        return response()->json(['received' => true]);
    }

    private function verify(string $body, string $header, string $secret): void
    {
        abort_if(strlen($header) > 8192, 400, 'Invalid webhook signature.');
        $timestamps = [];
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't') {
                $timestamps[] = $value;
            } elseif ($key === 'v1' && preg_match('/^[a-f0-9]{64}$/D', $value)) {
                $signatures[] = $value;
            }
        }
        abort_unless(count($timestamps) === 1 && ctype_digit($timestamps[0]) && abs(now()->timestamp - (int) $timestamps[0]) <= 300, 400, 'Webhook timestamp is invalid.');
        $expected = hash_hmac('sha256', $timestamps[0].'.'.$body, $secret);
        abort_unless(collect($signatures)->contains(fn (string $signature): bool => hash_equals($expected, $signature)), 400, 'Invalid webhook signature.');
    }
}
