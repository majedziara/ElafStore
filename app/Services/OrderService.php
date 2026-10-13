<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\ReturnRequest;
use App\Models\Shipment;
use App\Notifications\StoreMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

final class OrderService
{
    public function __construct(private InventoryService $inventory) {}

    public function cancel(Order $order, ?int $actor = null): void
    {
        DB::transaction(function () use ($order, $actor) {
            $o = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_if($o->paid_at || $o->payments()->exists(), 409, 'Payment exists. Reconcile it before cancellation; a browser cancel does not prove payment failure.');
            if ($o->canceled_at) {
                return;
            }
            $this->inventory->release($o);
            $o->update(['payment_status' => 'canceled', 'fulfillment_status' => 'canceled', 'canceled_at' => now()]);
            OrderEvent::create(['order_id' => $o->id, 'actor_id' => $actor, 'type' => 'canceled', 'message' => 'Order canceled.', 'customer_visible' => true]);
        }, 3);
    }

    public function allocate(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $o = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_unless($o->paid_at && ($o->stock_status !== 'deducted' || $o->fulfillment_status === 'on_hold') && in_array($o->payment_status, ['paid', 'partially_refunded'], true), 422);
            $this->inventory->capture($o);
            if ($o->stock_status === 'deducted') {
                $o->update(['fulfillment_status' => 'unfulfilled', 'canceled_at' => null]);
            }

            return $o;
        }, 3);
    }

    public function ship(Order $order, array $data, int $actor, ?ReturnRequest $return = null): Shipment
    {
        return DB::transaction(function () use ($order, $data, $actor, $return) {
            $o = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_unless($o->paid_at && in_array($o->payment_status, ['paid', 'partially_refunded'], true), 422, 'Order payment is not eligible for fulfillment.');
            if ($return) {
                $rr = ReturnRequest::whereKey($return->id)->lockForUpdate()->firstOrFail();
                abort_unless($rr->order_id === $o->id && $rr->type === 'exchange' && $rr->status === 'received' && $rr->exchange_reserved && ! $rr->exchange_deducted, 422, 'Exchange is not ready for shipment.');
                $quantities = $rr->items()->get()->groupBy('replacement_variant_id')->map(fn ($g) => (int) $g->sum('quantity'));
                $variants = $this->inventory->lockVariants($quantities->keys()->all());
                foreach ($quantities as $id => $qty) {
                    $v = $variants[$id];
                    abort_if($v->reserved < $qty || $v->stock < $qty, 409, 'Exchange inventory mismatch.');
                    $v->reserved -= $qty;
                    $v->stock -= $qty;
                    $v->save();
                    InventoryMovement::create(['product_variant_id' => $id, 'delta' => -$qty, 'actor_id' => $actor, 'reason' => 'exchange_shipment', 'reference' => $rr->reference]);
                }
                $rr->update(['exchange_deducted' => true]);
            } else {
                abort_unless($o->stock_status === 'deducted' && in_array($o->fulfillment_status, ['unfulfilled', 'processing'], true), 422, 'Order is not ready for shipment.');
                abort_if($o->shipments()->whereNull('return_request_id')->exists(), 409, 'Initial shipment already exists.');
                $o->update(['fulfillment_status' => 'shipped', 'shipped_at' => now()]);
            }
            $shipment = Shipment::create($data + ['order_id' => $o->id, 'return_request_id' => $return?->id, 'shipped_at' => now()]);
            OrderEvent::create(['order_id' => $o->id, 'actor_id' => $actor, 'type' => 'shipped', 'message' => $return ? 'Replacement shipment recorded.' : 'Order shipped.', 'customer_visible' => true]);
            Notification::route('mail', $o->email)->notify(new StoreMail('Shipment update — '.$o->reference,
                ['Carrier: '.$shipment->carrier, 'Tracking reference: '.$shipment->tracking_number], app(OrderAccessService::class)->link($o), 'View shipment'));

            return $shipment;
        }, 3);
    }

    public function delivered(Shipment $shipment, int $actor): void
    {
        DB::transaction(function () use ($shipment, $actor) {
            $o = Order::whereKey($shipment->order_id)->lockForUpdate()->firstOrFail();
            $s = Shipment::whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            if ($s->delivered_at) {
                return;
            }
            $s->update(['delivered_at' => now()]);
            if (! $s->return_request_id) {
                $o->update(['fulfillment_status' => 'delivered', 'delivered_at' => now()]);
            }
            OrderEvent::create(['order_id' => $o->id, 'actor_id' => $actor, 'type' => 'delivered', 'message' => $s->return_request_id ? 'Replacement delivered.' : 'Order delivered.', 'customer_visible' => true]);
        }, 3);
    }
}
