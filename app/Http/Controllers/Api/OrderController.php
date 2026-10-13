<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutRequest;
use App\Http\Requests\QuoteRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\CheckoutService;
use App\Services\OrderAccessService;
use App\Services\OrderService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function __construct(private OrderAccessService $access) {}

    public function quote(QuoteRequest $r, CheckoutService $s)
    {
        return ['data' => $s->quote($r, $r->validated())];
    }

    public function checkout(CheckoutRequest $r, CheckoutService $s)
    {
        $order = $s->create($r, $r->validated());

        return response()->json(['data' => (new OrderResource($order))->resolve(), 'order_token' => $order->user_id ? null : $this->access->grant($order)], 201);
    }

    public function index(Request $r)
    {
        $f = $r->validate(['q' => 'nullable|string|max:80', 'fulfillment_status' => 'nullable|in:unfulfilled,processing,on_hold,shipped,delivered,canceled', 'per_page' => 'nullable|integer|min:1|max:50']);
        $q = Order::with(['items', 'shipments', 'payments', 'events'])->where('user_id', $r->user()->id);
        if (! empty($f['q'])) {
            $q->where('reference', 'like', '%'.$f['q'].'%');
        }
        if (! empty($f['fulfillment_status'])) {
            $q->where('fulfillment_status', $f['fulfillment_status']);
        }

        return OrderResource::collection($q->latest('id')->paginate($f['per_page'] ?? 15));
    }

    public function show(Request $r, Order $order)
    {
        $this->access->authorize($r, $order);

        return new OrderResource($order->load(['items', 'shipments', 'payments', 'events']));
    }

    public function claim(Request $r, Order $order)
    {
        $this->access->authorize($r, $order);
        abort_unless($r->user()->email_verified_at && $r->user()->email === $order->email, 422, 'Verify the purchasing email before claiming this order.');
        DB::transaction(function () use ($r, $order) {
            $o = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_if($o->user_id && $o->user_id !== $r->user()->id, 409);
            $o->update(['user_id' => $r->user()->id]);
        });

        return ['message' => 'Order linked to your account.'];
    }

    public function cancel(Request $r, Order $order, OrderService $s)
    {
        $this->access->authorize($r, $order);
        $s->cancel($order);

        return response()->noContent();
    }

    public function receipt(Request $r, Order $order)
    {
        $this->access->authorize($r, $order);
        abort_unless($order->paid_at, 409, 'No paid receipt is available.');

        return Pdf::loadView('pdf.receipt', ['order' => $order->load('items')])->setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false])->download($order->reference.'.pdf');
    }

    public function lookup(Request $r)
    {
        $d = $r->validate(['reference' => 'required|string|max:80', 'email' => 'required|email:rfc|max:254']);
        $this->access->send($d['reference'], $d['email']);

        return ['message' => 'If these details match an order, an access link will be sent.'];
    }

    public function consume(Request $r)
    {
        $d = $r->validate(['token' => 'required|string|size:64']);

        return ['data' => $this->access->consume($d['token'])];
    }

    public function accessLink(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => 'required|string|size:64']);
        $access = $this->access->consume($data['token']);
        $order = Order::where('uuid', $access['order_uuid'])->firstOrFail();

        return response()->json(['data' => (new OrderResource($order))->resolve(),
            'order_token' => $access['order_token'], 'expires_in_minutes' => $access['expires_in_minutes']])
            ->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }
}
