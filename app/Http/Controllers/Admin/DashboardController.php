<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\ProductVariant;
use App\Models\ReturnRequest;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(Request $r)
    {
        $d = $r->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);
        $from = Carbon::parse($d['from'] ?? now()->subDays(29)->toDateString())->startOfDay();
        $to = Carbon::parse($d['to'] ?? now()->toDateString())->endOfDay();
        abort_if($from->diffInDays($to) > 366, 422, 'Use a date range up to one year.');
        $paid = Order::whereBetween('paid_at', [$from, $to])->whereIn('payment_status', ['paid', 'partially_refunded', 'refunded']);
        $count = (clone $paid)->count();
        $net = (int) (clone $paid)->sum(DB::raw('total_cents - refunded_cents'));

        return ['data' => ['paid_sales_net_cents' => $net, 'paid_orders' => $count, 'average_order_value_cents' => $count ? intdiv($net, $count) : 0,
            'pending_payment_cents' => (int) Order::whereNull('paid_at')->whereNull('canceled_at')->sum('total_cents'),
            'awaiting_fulfillment' => Order::whereNotNull('paid_at')->whereIn('payment_status', ['paid', 'partially_refunded'])->whereIn('fulfillment_status', ['unfulfilled', 'processing', 'on_hold'])->count(),
            'low_stock' => ProductVariant::where('is_active', true)->whereRaw('stock - reserved BETWEEN 0 AND 3')->with('product')->limit(20)->get(),
            'returns_awaiting_review' => ReturnRequest::whereIn('status', ['submitted', 'under_review'])->count(),
            'unresolved_payments' => PaymentAttempt::whereIn('status', ['unknown', 'creating', 'capture_pending'])->count(),
            'recent_orders' => Order::with('items')->latest('id')->limit(10)->get(),
            'daily_sales' => (clone $paid)->selectRaw('DATE(paid_at) as date, SUM(total_cents-refunded_cents) as net_cents, COUNT(*) as orders')->groupByRaw('DATE(paid_at)')->orderBy('date')->get(),
            'currency' => 'USD', 'timezone' => 'UTC']];
    }
}
