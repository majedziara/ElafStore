<?php

namespace App\Console\Commands;

use App\Services\Payments\GatewayRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class CheckPaymentGateways extends Command
{
    protected $signature = 'store:payments-check {--gateway= : paypal, stripe, or triple-a} {--environment= : sandbox or live} {--remote : Verify API authentication without creating a payment}';

    protected $description = 'Check environment-only payment configuration and optionally authenticate with providers';

    public function handle(GatewayRegistry $gateways): int
    {
        $gateway = $this->option('gateway');
        $environment = $this->option('environment');
        if (($gateway && ! isset(GatewayRegistry::NAMES[$gateway])) || ($environment && ! in_array($environment, ['sandbox', 'live'], true))) {
            $this->error('Choose an implemented gateway and sandbox or live.');

            return self::FAILURE;
        }
        $success = true;
        foreach ($gateway ? [$gateway => GatewayRegistry::NAMES[$gateway]] : GatewayRegistry::NAMES as $slug => $name) {
            $mode = $environment ?: $gateways->environment($slug);
            $ready = $gateways->ready($slug, $mode);
            $this->line($name.' / '.$mode.': '.($ready ? 'Configured' : 'Unavailable'));
            $this->line('Webhook: '.$gateways->webhookUrl($slug, $mode));
            if (! $ready) {
                $this->warn('Complete the environment keys; verify the selected mode and credential format. Missing: '.implode(', ', $gateways->missing($slug, $mode)));
                $success = false;

                continue;
            }
            if (! $this->option('remote')) {
                continue;
            }
            $credentials = $gateways->configuration($slug, $mode)['credentials'];
            try {
                if ($slug === 'stripe') {
                    $payload = Http::withBasicAuth($credentials['secret_key'], '')->acceptJson()->connectTimeout(5)->timeout(20)->get('https://api.stripe.com/v1/balance')->throw()->json();
                    abort_unless(($payload['livemode'] ?? null) === ($mode === 'live'), 409);
                } else {
                    $url = $slug === 'paypal' ? ($mode === 'live' ? 'https://api-m.paypal.com/v1/oauth2/token' : 'https://api-m.sandbox.paypal.com/v1/oauth2/token') : 'https://api.triple-a.io/api/v2/oauth/token';
                    $request = Http::asForm()->acceptJson()->connectTimeout(5)->timeout(20);
                    $data = ['grant_type' => 'client_credentials'];
                    if ($slug === 'paypal') {
                        $request = $request->withBasicAuth($credentials['client_id'], $credentials['client_secret']);
                    } else {
                        $data += ['client_id' => $credentials['client_id'], 'client_secret' => $credentials['client_secret']];
                    }
                    $payload = $request->post($url, $data)->throw()->json();
                    abort_if(empty($payload['access_token']), 502);
                }
                $this->info('Provider authentication: OK');
            } catch (\Throwable) {
                $this->error('Provider authentication failed. Check credentials, permissions and network access.');
                $success = false;
            }
        }

        return $success ? self::SUCCESS : self::FAILURE;
    }
}
