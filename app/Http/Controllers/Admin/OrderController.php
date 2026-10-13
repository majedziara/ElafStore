<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\ReturnRequest;
use App\Models\Shipment;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function index(Request $r)
    {
        $d = $r->validate(['q' => 'nullable|string|max:100', 'payment_status' => 'nullable|in:pending,paid,partially_refunded,refunded,failed,canceled,expired,reversed',
            'fulfillment_status' => 'nullable|in:unfulfilled,processing,on_hold,shipped,delivered,canceled', 'per_page' => 'nullable|integer|min:1|max:100']);
        $q = Order::with('items');
        if (! empty($d['q'])) {
            $q->where(fn ($q) => $q->where('reference', 'like', '%'.$d['q'].'%')->orWhere('email', 'like', '%'.$d['q'].'%'));
        }
        foreach (['payment_status', 'fulfillment_status'] as $key) {
            if (! empty($d[$key])) {
                $q->where($key, $d[$key]);
            }
        }

        return $q->latest('id')->paginate($d['per_page'] ?? 25);
    }

    public function show(Order $order)
    {
        return ['data' => $order->load(['items', 'payments', 'refunds', 'returns.items', 'shipments', 'events'])];
    }

    public function note(Request $r, Order $order)
    {
        $d = $r->validate(['message' => 'required|string|max:3000']);

        return ['data' => OrderEvent::create($d + ['order_id' => $order->id, 'actor_id' => $r->user()->id, 'type' => 'internal_note'])];
    }

    public function process(Order $order)
    {
        DB::transaction(function () use ($order) {
            $o = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_unless($o->paid_at && $o->stock_status === 'deducted' && $o->fulfillment_status === 'unfulfilled' && in_array($o->payment_status, ['paid', 'partially_refunded'], true), 422);
            $o->update(['fulfillment_status' => 'processing']);
        });

        return response()->noContent();
    }

    public function cancel(Request $r, Order $order, OrderService $s)
    {
        $s->cancel($order, $r->user()->id);

        return response()->noContent();
    }

    public function allocate(Order $order, OrderService $s)
    {
        return ['data' => $s->allocate($order)];
    }

    public function ship(Request $r, Order $order, OrderService $s)
    {
        $d = $r->validate(['carrier' => 'required|string|max:100', 'tracking_number' => 'required|string|max:120', 'tracking_url' => 'nullable|url:https|max:500',
            'return_request_id' => 'nullable|integer|exists:return_requests,id']);
        $rr = isset($d['return_request_id']) ? ReturnRequest::findOrFail($d['return_request_id']) : null;
        unset($d['return_request_id']);

        return ['data' => $s->ship($order, $d, $r->user()->id, $rr)];
    }

    public function delivered(Request $r, Shipment $shipment, OrderService $s)
    {
        $s->delivered($shipment, $r->user()->id);

        return response()->noContent();
    }
}
