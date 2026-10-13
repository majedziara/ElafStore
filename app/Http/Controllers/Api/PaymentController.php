<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Services\OrderAccessService;
use App\Services\Payments\PaymentService;
use App\Services\Payments\PayPalCallbackLinkService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(private OrderAccessService $access, private PaymentService $service, private PayPalCallbackLinkService $callbacks) {}

    public function methods()
    {
        return ['data' => PaymentGateway::where('enabled', true)->where('slug', 'paypal')->get(['slug', 'name'])];
    }

    private function view($a): array
    {
        return ['uuid' => $a->uuid, 'gateway' => $a->gateway, 'status' => $a->status, 'approval_url' => $a->approval_url,
            'provider_order_id' => $a->provider_order_id, 'return_url' => $this->callbacks->url('return', $a->uuid),
            'cancel_url' => $this->callbacks->url('cancel', $a->uuid)];
    }

    public function start(Request $r, Order $order)
    {
        $this->access->authorize($r, $order);

        return ['data' => $this->view($this->service->start($order))];
    }

    public function capture(Request $r, Order $order)
    {
        $this->access->authorize($r, $order);
        $a = $order->payments()->latest('id')->firstOrFail();

        return ['data' => $this->view($this->service->sync($a, true))];
    }

    public function status(Request $r, Order $order)
    {
        $this->access->authorize($r, $order);
        $a = $order->payments()->latest('id')->first();

        return ['data' => ['payment_status' => $order->payment_status, 'attempt' => $a ? $this->view($a) : null]];
    }
}
