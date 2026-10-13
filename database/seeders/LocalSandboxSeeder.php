<?php

namespace Database\Seeders;

use App\Models\PaymentGateway;
use App\Models\ShippingMethod;
use App\Services\Payments\PayPalClient;
use App\Services\SettingService;
use Illuminate\Database\Seeder;

class LocalSandboxSeeder extends Seeder
{
    public function run(): void
    {
        abort_unless(app()->environment(['local', 'testing']), 403, 'Sandbox setup is local only.');
        $this->call([StoreSeeder::class, DemoCatalogSeeder::class]);
        app(PayPalClient::class)->configuration('sandbox');
        PaymentGateway::where('slug', 'paypal')->update(['enabled' => true, 'environment' => 'sandbox']);
        ShippingMethod::firstOrCreate(['name' => 'Sandbox Test Shipping'], ['price_cents' => 500, 'states' => ['NY'], 'is_active' => true]);
        app(SettingService::class)->put('tax_mode', 'none');
        app(SettingService::class)->put('checkout_enabled', true);
    }
}
