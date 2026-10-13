<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $r)
    {
        $d = $r->validate(['q' => 'nullable|string|max:100', 'type' => 'nullable|in:registered,guest']);
        if (($d['type'] ?? null) === 'guest') {
            return Order::whereNull('user_id')->select('email')->selectRaw('COUNT(*) as orders_count, SUM(total_cents) as ordered_cents')
                ->when(! empty($d['q']), fn ($q) => $q->where('email', 'like', '%'.$d['q'].'%'))->groupBy('email')->orderBy('email')->paginate(25);
        }

        return User::role('customer')->select(['id', 'name', 'email', 'email_verified_at', 'is_active', 'created_at'])->withCount('orders')
            ->when(! empty($d['q']), fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$d['q'].'%')->orWhere('email', 'like', '%'.$d['q'].'%')))->latest('id')->paginate(25);
    }

    public function show(User $customer)
    {
        abort_unless($customer->hasRole('customer'), 404);

        return ['data' => $customer->only(['id', 'name', 'email', 'email_verified_at', 'is_active', 'created_at']),
            'addresses' => $customer->addresses()->get(), 'orders' => $customer->orders()->with('items')->latest('id')->paginate(15)];
    }
}
