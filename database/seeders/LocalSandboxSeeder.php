<?php

namespace Database\Seeders;

use App\Models\ShippingMethod;
use App\Services\Payments\GatewayRegistry;
use App\Services\SettingService;
use Illuminate\Database\Seeder;

class LocalSandboxSeeder extends Seeder
{
    public function run(): void
    {
        abort_unless(app()->environment(['local', 'testing']), 403, 'Sandbox setup is local only.');
        abort_unless(collect(app(GatewayRegistry::class)->methods())->contains(fn (array $gateway): bool => $gateway['environment'] === 'sandbox'), 503, 'Configure a sandbox payment gateway in .env first.');
        $this->call([StoreSeeder::class, DemoCatalogSeeder::class]);
        ShippingMethod::firstOrCreate(['name' => 'Sandbox Test Shipping'], ['price_cents' => 500, 'states' => ['NY'], 'is_active' => true]);
        app(SettingService::class)->put('tax_mode', 'none');
        app(SettingService::class)->put('checkout_enabled', true);
    }
}
