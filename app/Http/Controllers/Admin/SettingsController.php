<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Services\AuditService;
use App\Services\Payments\GatewayRegistry;
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
                count(app(GatewayRegistry::class)->methods()) > 0, 422, 'Configure shipping, tax mode and an enabled payment gateway first.');
        }
        DB::transaction(function () use ($d, $s) {
            foreach ($d as $key => $value) {
                $s->put($key, $value);
            }
        });
        app(AuditService::class)->record('settings_saved', 'settings', null, ['fields' => array_keys($d)]);

        return ['message' => 'Settings saved.'];
    }

    public function gateways(GatewayRegistry $gateways): array
    {
        return ['data' => collect(GatewayRegistry::NAMES)->map(function (string $name, string $slug) use ($gateways): array {
            $environment = $gateways->environment($slug);
            $prefix = match ($slug) {
                'paypal' => 'PAYPAL', 'stripe' => 'STRIPE', 'triple-a' => 'TRIPLEA'
            };
            $modes = [];
            foreach (['sandbox', 'live'] as $mode) {
                $modes[$mode] = ['ready' => $gateways->ready($slug, $mode), 'missing' => $gateways->missing($slug, $mode),
                    'env_keys' => array_map(fn (string $field): string => $prefix.'_'.strtoupper($mode).'_'.strtoupper($field), $gateways->fields($slug))];
            }

            return ['slug' => $slug, 'name' => $name, 'enabled' => $gateways->available($slug), 'environment' => $environment,
                'implemented' => true, 'configuration_source' => 'env', 'mode_key' => $prefix.'_MODE', 'environments' => $modes,
                'webhook_urls' => ['sandbox' => $gateways->webhookUrl($slug, 'sandbox'), 'live' => $gateways->webhookUrl($slug, 'live')]];
        })->values()->all()];
    }

    public function gateway(Request $request, string $slug): array
    {
        abort_unless(isset(GatewayRegistry::NAMES[$slug]), 422, 'This payment gateway is not implemented.');
        abort_if($request->has('credentials') || $request->has('environment') || $request->has('enabled'), 422, 'Payment settings are managed in Laravel .env. Reload configuration after changing them.');

        return ['message' => 'Payment settings are managed in Laravel .env.'];
    }

    public function media(Request $r)
    {
        $r->validate(['image' => 'required|image|mimes:jpg,jpeg,png,webp|max:8192']);
        $path = $r->file('image')->store('content', 'public');

        return ['data' => ['path' => $path, 'url' => Storage::disk('public')->url($path)]];
    }
}
