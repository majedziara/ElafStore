<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentGateway;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Services\AuditService;
use App\Services\Payments\PayPalClient;
use App\Services\SettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SettingsController extends Controller
{
    public function index(SettingService $s)
    {
        return ['data' => Setting::orderBy('key')->get(['key', 'value'])];
    }

    public function save(Request $r, SettingService $s)
    {
        $d = $r->validate(['brand_name' => 'sometimes|required|string|max:120', 'support_email' => 'sometimes|nullable|email:rfc|max:254',
            'logo_path' => 'sometimes|nullable|string|max:255', 'shipping_policy' => 'sometimes|nullable|string|max:30000', 'return_policy' => 'sometimes|nullable|string|max:30000',
            'checkout_enabled' => 'sometimes|boolean', 'tax_mode' => 'sometimes|in:none,manual', 'returns_enabled' => 'sometimes|boolean',
            'exchanges_enabled' => 'sometimes|boolean', 'return_days' => 'sometimes|integer|min:0|max:365']);
        $get = fn ($key, $default = null) => array_key_exists($key, $d) ? $d[$key] : $s->get($key, $default);
        if ($get('returns_enabled', false)) {
            abort_if($get('return_days', 0) < 1, 422, 'Configure a return window before enabling returns.');
        }
        if ($get('exchanges_enabled', false)) {
            abort_unless($get('returns_enabled', false), 422, 'Enable the return request workflow first.');
        }
        if ($get('checkout_enabled', false)) {
            abort_unless(in_array($get('tax_mode'), ['none', 'manual'], true) && ShippingMethod::where('is_active', true)->exists() &&
                PaymentGateway::where('slug', 'paypal')->where('enabled', true)->exists(), 422, 'Configure shipping, tax mode and an enabled payment gateway first.');
        }
        DB::transaction(function () use ($d, $s) {
            foreach ($d as $key => $value) {
                $s->put($key, $value);
            }
        });
        app(AuditService::class)->record('settings_saved', 'settings', null, ['fields' => array_keys($d)]);

        return ['message' => 'Settings saved.'];
    }

    public function gateways()
    {
        return ['data' => PaymentGateway::all()->map(fn ($g) => ['slug' => $g->slug, 'name' => $g->name, 'enabled' => $g->enabled, 'environment' => $g->environment,
            'implemented' => $g->slug === 'paypal', 'configured_fields' => collect($g->credentials ?? [])->map(fn ($c) => array_keys(array_filter($c)))])];
    }

    public function gateway(Request $r, string $slug, PayPalClient $client)
    {
        $g = PaymentGateway::where('slug', $slug)->firstOrFail();
        $d = $r->validate(['enabled' => 'sometimes|boolean', 'environment' => 'sometimes|in:sandbox,live',
            'credentials' => 'sometimes|array:sandbox,live', 'credentials.sandbox' => 'sometimes|array:client_id,client_secret,merchant_id,webhook_id',
            'credentials.live' => 'sometimes|array:client_id,client_secret,merchant_id,webhook_id',
            'credentials.*.client_id' => 'sometimes|nullable|string|max:255', 'credentials.*.client_secret' => 'sometimes|nullable|string|max:255',
            'credentials.*.merchant_id' => 'sometimes|nullable|string|max:80', 'credentials.*.webhook_id' => 'sometimes|nullable|string|max:120']);
        abort_if(($d['enabled'] ?? false) && $slug !== 'paypal', 422, 'This gateway adapter has not been implemented.');
        abort_if(isset($d['credentials']) && $slug !== 'paypal', 422, 'Credential format is available for PayPal only.');

        return DB::transaction(function () use ($g, $d, $slug, $client) {
            $g = PaymentGateway::whereKey($g->id)->lockForUpdate()->firstOrFail();
            if (isset($d['credentials'])) {
                $credentials = $g->credentials ?? [];
                foreach ($d['credentials'] as $env => $fields) {
                    $credentials[$env] = array_replace($credentials[$env] ?? [], array_filter($fields, fn ($v) => $v !== null));
                }
                $g->credentials = $credentials;
            }
            if (isset($d['environment'])) {
                $g->environment = $d['environment'];
            }
            if (isset($d['enabled'])) {
                $g->enabled = $d['enabled'];
            } $g->save();
            if ($g->enabled && $slug === 'paypal') {
                $client->configuration($g->environment);
            }
            app(AuditService::class)->record('gateway_saved', 'gateway', $g->id, ['slug' => $g->slug, 'enabled' => $g->enabled, 'environment' => $g->environment, 'credential_fields_changed' => isset($d['credentials'])]);

            return ['data' => ['slug' => $g->slug, 'enabled' => $g->enabled, 'environment' => $g->environment]];
        });
    }

    public function media(Request $r)
    {
        $r->validate(['image' => 'required|image|mimes:jpg,jpeg,png,webp|max:8192']);
        $path = $r->file('image')->store('content','public');

        return ['data' => ['path' => $path, 'url' => Storage::disk('public')->url($path)]];
    }
}
