<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditService;
use App\Services\TeamService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class TeamController extends Controller
{
    public function index()
    {
        return User::with('roles')->whereHas('roles', fn ($q) => $q->where('name', '!=', 'customer'))->paginate(25);
    }

    public function audit()
    {
        return AuditLog::latest('id')->paginate(50);
    }

    public function rolePermissions(Request $r, Role $role)
    {
        abort_if(in_array($role->name, ['admin', 'customer'], true), 422, 'This role has fixed permissions.');
        $d = $r->validate(['permissions' => 'present|array', 'permissions.*' => ['string', 'distinct', Rule::in(Permission::where('guard_name', 'web')->pluck('name')->all())]]);
        $role->syncPermissions($d['permissions']);
        app(AuditService::class)->record('role_permissions_changed', 'role', $role->id, ['permissions' => $d['permissions']]);

        return ['data' => $role->load('permissions')];
    }

    public function roles()
    {
        return ['data' => Role::with('permissions')->get()];
    }

    public function save(Request $r, TeamService $s, ?User $member = null)
    {
        $d = $r->validate(['name' => 'required|string|max:120', 'email' => $member ? 'prohibited' : ['required', 'email:rfc', 'max:254', Rule::unique('users')],
            'is_active' => 'required|boolean', 'roles' => 'required|array|min:1|max:4', 'roles.*' => 'required|distinct|in:admin,catalog_manager,order_manager,content_editor,support']);

        return ['data' => $s->save($d, $member)->load('roles')];
    }
}
