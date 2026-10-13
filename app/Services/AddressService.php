<?php

namespace App\Services;

use App\Models\Address;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AddressService
{
    public function save(User $user, array $data, ?Address $address = null): Address
    {
        return DB::transaction(function () use ($user, $data, $address) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($address) {
                abort_unless($address->user_id === $user->id, 404);
            }
            foreach (['default_shipping', 'default_billing'] as $key) {
                if ($data[$key] ?? false) {
                    Address::where('user_id', $user->id)->update([$key => false]);
                }
            }
            if ($address) {
                $address->update($data);

                return $address->fresh();
            }

            return Address::create($data + ['user_id' => $user->id]);
        }, 3);
    }

    public function remove(User $user, Address $address): void
    {
        DB::transaction(function () use ($user, $address) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($address->user_id === $user->id, 404);
            $address->delete();
        });
    }
}
