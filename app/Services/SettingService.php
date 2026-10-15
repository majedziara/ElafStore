<?php

namespace App\Services;

use App\Models\Setting;

final class SettingService
{
    public function get(string $key, mixed $default = null): mixed
    {
        $row = Setting::where('key', $key)->first();

        return $row ? $row->value : $default;
    }

    public function put(string $key, mixed $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public function publicSettings(): array
    {
        $keys = ['brand_name', 'support_email', 'logo_path', 'shipping_policy', 'return_policy', 'checkout_enabled', 'returns_enabled', 'exchanges_enabled', 'return_days'];

        return collect($keys)->mapWithKeys(fn ($k) => [$k => $this->get($k)])->all() + ['currency' => 'USD', 'language' => 'en'];
    }
}
