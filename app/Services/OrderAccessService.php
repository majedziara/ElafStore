<?php

namespace App\Services;

use App\Models\LinkToken;
use App\Models\Order;
use App\Notifications\StoreMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

final class OrderAccessService
{
    public function __construct(private LinkService $links) {}

    public function authorize(Request $r, Order $order): void
    {
        $user = $r->user('sanctum');
        if ($user && $user->is_active && $order->user_id === $user->id) {
            return;
        }
        $raw = (string) $r->header('X-Order-Token');
        $valid = strlen($raw) === 64 && LinkToken::where('token_hash', hash('sha256', $raw))->where('purpose', 'order_access')
            ->where('target_id', $order->id)->where('expires_at', '>', now())->exists();
        abort_unless($valid, 404);
    }

    public function grant(Order $order): string
    {
        return $this->links->issue('order_access', $order->id, [], config('store.guest_access_minutes'));
    }

    public function send(string $reference, string $email): void
    {
        $order = Order::where('reference', $reference)->where('email', strtolower($email))->first();
        if (! $order) {
            return;
        }
        Notification::route('mail', $order->email)->notify(new StoreMail('Access your Nella & Vale order',
            ['Use this link to view your order securely.'], $this->link($order), 'View order'));
    }

    public function link(Order $order): string
    {
        $token = $this->links->issue('guest_order', $order->id, [], config('store.link_minutes'));

        return config('store.url').'/api/v1/guest-orders/consume?'.http_build_query(['token' => $token]);
    }

    public function consume(string $token): array
    {
        return $this->links->consume($token, 'guest_order', function ($link) {
            $order = Order::findOrFail($link->target_id);

            return ['order_uuid' => $order->uuid, 'order_token' => $this->grant($order), 'expires_in_minutes' => config('store.guest_access_minutes')];
        });
    }
}
