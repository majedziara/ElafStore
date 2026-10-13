<?php

namespace Database\Seeders;

use App\Models\PaymentGateway;
use App\Models\Setting;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class StoreSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permissions = ['dashboard.view', 'catalog.manage', 'orders.view', 'orders.manage', 'returns.manage', 'refunds.create',
            'payments.manage', 'messages.manage', 'customers.view', 'discounts.manage', 'content.manage', 'settings.manage', 'team.manage'];
        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $roles = ['admin' => $permissions, 'customer' => [], 'catalog_manager' => ['dashboard.view', 'catalog.manage'],
            'order_manager' => ['dashboard.view', 'orders.view', 'orders.manage', 'returns.manage', 'customers.view'],
            'content_editor' => ['dashboard.view', 'content.manage'], 'support' => ['dashboard.view', 'orders.view', 'customers.view', 'messages.manage']];
        foreach ($roles as $name => $rights) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            if ($role->wasRecentlyCreated || $name === 'admin') {
                $role->syncPermissions($rights);
            }
        }
        foreach (['brand_name' => 'Nella & Vale', 'support_email' => null, 'logo_path' => null, 'checkout_enabled' => false, 'tax_mode' => 'unconfigured',
            'returns_enabled' => false, 'exchanges_enabled' => false, 'return_days' => 0, 'shipping_policy' => null, 'return_policy' => null] as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['value' => $value]);
        }
        foreach (['paypal' => 'PayPal', 'triple-a' => 'Triple-A', 'btcpay' => 'BTCPay Server', 'stripe' => 'Stripe', '2checkout' => '2Checkout / Verifone', 'bank-of-palestine' => 'Bank of Palestine'] as $slug => $name) {
            PaymentGateway::firstOrCreate(['slug' => $slug], ['name' => $name, 'enabled' => false, 'environment' => config('store.paypal.mode', 'sandbox')]);
        }
    }
}
