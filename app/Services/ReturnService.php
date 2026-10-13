<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\ProductVariant;
use App\Models\ReturnItem;
use App\Models\ReturnRequest;
use App\Models\Shipment;
use App\Notifications\StoreMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

final class ReturnService
{
    public function __construct(private SettingService $settings, private InventoryService $inventory) {}

    public function create(Order $order, array $data): ReturnRequest
    {
        return DB::transaction(function () use ($order, $data) {
            $o = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $days = (int) $this->settings->get('return_days', 0);
            abort_unless($this->settings->get('returns_enabled', false) && $days > 0 && $o->delivered_at &&
                $o->delivered_at->copy()->addDays($days) >= now() && in_array($o->payment_status, ['paid', 'partially_refunded'], true), 422, 'This order is not eligible for a return request.');
            if ($data['type'] === 'exchange') {
                abort_unless($this->settings->get('exchanges_enabled', false), 422, 'Exchanges are unavailable.');
            }
            $rows = [];
            foreach ($data['items'] as $line) {
                $item = $o->items()->whereKey($line['order_item_id'])->firstOrFail();
                abort_unless($item->returnable, 422, 'This item is not eligible under the store policy.');
                $requested = ReturnItem::where('order_item_id', $item->id)->whereHas('returnRequest', fn ($q) => $q->whereNotIn('status', ['rejected', 'canceled']))->sum('quantity');
                abort_if($requested + (int) $line['quantity'] > $item->quantity, 422, 'Return quantity exceeds eligible purchased quantity.');
                $replacement = null;
                if ($data['type'] === 'exchange') {
                    abort_if(empty($line['replacement_variant_id']), 422, 'Choose a replacement size.');
                    $original = ProductVariant::findOrFail($item->product_variant_id);
                    $replacement = ProductVariant::with('product')->findOrFail($line['replacement_variant_id']);
                    abort_unless($replacement->product_id === $original->product_id && $replacement->color === $item->color &&
                        $replacement->is_active && $replacement->product->status === 'published' && $replacement->available >= (int) $line['quantity'] &&
                        $replacement->price_cents === $item->unit_price_cents, 422, 'Replacement is unavailable or needs a price-adjustment workflow.');
                    abort_if($replacement->id === $original->id, 422, 'Choose a different size.');
                }
                $rows[] = ['order_item_id' => $item->id, 'quantity' => (int) $line['quantity'], 'reason' => $line['reason'], 'replacement_variant_id' => $replacement?->id];
            }
            $return = ReturnRequest::create(['uuid' => (string) Str::uuid(), 'reference' => 'RET-'.Str::upper((string) Str::ulid()),
                'order_id' => $o->id, 'type' => $data['type'], 'notes' => $data['notes'] ?? null]);
            $return->items()->createMany($rows);
            OrderEvent::create(['order_id' => $o->id, 'type' => 'return_submitted', 'message' => 'Return request '.$return->reference.' submitted.', 'customer_visible' => true]);

            return $return->fresh()->load('items');
        }, 3);
    }

    public function review(ReturnRequest $return, string $status, ?string $note, int $actor): ReturnRequest
    {
        return DB::transaction(function () use ($return, $status, $note, $actor) {
            Order::whereKey($return->order_id)->lockForUpdate()->firstOrFail();
            $rr = ReturnRequest::whereKey($return->id)->lockForUpdate()->firstOrFail();
            $allowed = ['submitted' => ['under_review', 'approved', 'rejected'], 'under_review' => ['approved', 'rejected'], 'approved' => ['received', 'rejected'], 'received' => ['completed']];
            abort_unless(in_array($status, $allowed[$rr->status] ?? [], true), 422, 'Invalid return status transition.');
            if ($status === 'approved' && $rr->type === 'exchange') {
                $lines = $rr->items()->get();
                $quantities = $lines->groupBy('replacement_variant_id')->map(fn ($g) => (int) $g->sum('quantity'));
                $variants = $this->inventory->lockVariants($quantities->keys()->all());
                foreach ($quantities as $id => $qty) {
                    $v = $variants[$id];
                    abort_unless($v->is_active && $v->available >= $qty, 422, 'Replacement stock unavailable.');
                    $v->increment('reserved', $qty);
                }
                $rr->exchange_reserved = true;
            }
            if ($status === 'rejected' && $rr->exchange_reserved && ! $rr->exchange_deducted) {
                $quantities = $rr->items()->get()->groupBy('replacement_variant_id')->map(fn ($g) => (int) $g->sum('quantity'));
                $variants = $this->inventory->lockVariants($quantities->keys()->all());
                foreach ($quantities as $id => $qty) {
                    abort_if($variants[$id]->reserved < $qty, 409);
                    $variants[$id]->decrement('reserved', $qty);
                }
                $rr->exchange_reserved = false;
            }
            if ($status === 'completed') {
                if ($rr->type === 'exchange') {
                    abort_unless($rr->exchange_deducted && Shipment::where('return_request_id', $rr->id)->exists(), 422, 'Ship the replacement before completion.');
                } else {
                    abort_unless($rr->order->refunds()->where('return_request_id', $rr->id)->where('status', 'completed')->exists(), 422, 'Confirm the related refund before completion.');
                }
            }
            $rr->status = $status;
            $rr->admin_note = $note;
            $rr->save();
            OrderEvent::create(['order_id' => $rr->order_id, 'actor_id' => $actor, 'type' => 'return_updated', 'message' => 'Return '.$rr->reference.': '.$status.'.', 'customer_visible' => true]);
            Notification::route('mail', $rr->order->email)->notify(new StoreMail('Return request updated — '.$rr->reference,
                ['Your request status is now: '.str_replace('_', ' ', $status).'.'], app(OrderAccessService::class)->link($rr->order), 'View order'));

            return $rr->fresh()->load('items');
        }, 3);
    }

    public function restock(ReturnRequest $return, int $actor): void
    {
        DB::transaction(function () use ($return, $actor) {
            $rr = ReturnRequest::whereKey($return->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($rr->status, ['received', 'completed'], true) && ! $rr->restocked, 422, 'Restock is unavailable or already processed.');
            foreach ($rr->items()->with('orderItem')->get()->sortBy(fn ($i) => $i->orderItem->product_variant_id) as $i) {
                $v = ProductVariant::findOrFail($i->orderItem->product_variant_id);
                $this->inventory->adjust($v, $i->quantity, 'inspected_return:'.$rr->reference, $actor);
            }
            $rr->update(['restocked' => true]);
        }, 3);
    }
}
