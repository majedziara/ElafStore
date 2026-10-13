<?php

namespace App\Services;

use App\Jobs\SendPasswordResetLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

final class TeamService
{
    public function save(array $data, ?User $user = null): User
    {
        return DB::transaction(function () use ($data, $user) {
            // Serialize all role edits on the admin role row to preserve at least one active admin.
            Role::where('name', 'admin')->where('guard_name', 'web')->lockForUpdate()->firstOrFail();
            $roles = $data['roles'];
            unset($data['roles']);
            $new = ! $user;
            if ($user) {
                $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                if ($user->hasRole('admin') && (! in_array('admin', $roles, true) || ! ($data['is_active'] ?? $user->is_active))) {
                    abort_if(User::role('admin')->where('is_active', true)->where('id', '!=', $user->id)->count() < 1, 422, 'At least one active administrator must remain.');
                }
                $user->name = $data['name'];
                $user->is_active = $data['is_active'];
                $user->save();
            } else {
                $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => Str::random(64)]);
            }
            $user->forceFill(['is_active' => $data['is_active']])->save();
            $user->syncRoles($roles);
            if (! $user->is_active) {
                app(AuthService::class)->revokeSessions($user);
            }
            if ($new) {
                app(AuthService::class)->sendVerification($user);
                SendPasswordResetLink::dispatch($user->email);
            }
            app(AuditService::class)->record('team_saved', 'user', $user->id, ['roles' => $roles, 'is_active' => $user->is_active]);

            return $user;
        }, 3);
    }
}
