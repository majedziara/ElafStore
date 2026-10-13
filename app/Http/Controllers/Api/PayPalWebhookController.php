<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPayPalWebhook;
use App\Models\WebhookEvent;
use App\Services\Payments\PayPalClient;
use Illuminate\Http\Request;

class PayPalWebhookController extends Controller
{
    public function __invoke(Request $r, string $environment, PayPalClient $client)
    {
        abort_if(strlen($r->getContent()) > 262144, 413);
        $event = $r->validate(['id' => 'required|string|max:120', 'event_type' => 'required|string|max:120', 'resource' => 'required|array']);
        $full = $r->json()->all();
        $headers = ['transmission_id' => $r->header('PAYPAL-TRANSMISSION-ID'), 'transmission_time' => $r->header('PAYPAL-TRANSMISSION-TIME'),
            'cert_url' => $r->header('PAYPAL-CERT-URL'), 'auth_algo' => $r->header('PAYPAL-AUTH-ALGO'), 'transmission_sig' => $r->header('PAYPAL-TRANSMISSION-SIG')];
        foreach ($headers as $v) {
            abort_unless(is_string($v) && strlen($v) > 0 && strlen($v) < 8192, 400, 'Missing PayPal header.');
        }
        abort_unless($client->verifyWebhook($client->configuration($environment), json_decode($r->getContent(), false, 512, JSON_THROW_ON_ERROR), $headers), 400, 'Invalid PayPal signature.');
        $row = WebhookEvent::firstOrCreate(['provider' => 'paypal', 'environment' => $environment, 'provider_event_id' => $event['id']],
            ['type' => $event['event_type'], 'payload' => $full]);
        if (! $row->processed_at) {
            ProcessPayPalWebhook::dispatch($row->id);
        }

        return response()->json(['received' => true]);
    }
}
