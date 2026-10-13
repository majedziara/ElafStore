<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ProductVariant;

class ExportController extends Controller
{
    private function safe(array $row): array
    {
        return array_map(fn ($value) => is_string($value) && preg_match('/^[=+@\-\t\r]/', $value) ? "'".$value : $value, $row);
    }

    public function products()
    {
        return response()->streamDownload(function () {
            $h = fopen('php://output', 'w');
            fputcsv($h, ['SKU', 'Product', 'Color', 'Size', 'Price cents', 'Stock', 'Reserved', 'Publication'], ',', '"', '');
            foreach (ProductVariant::with('product')->lazyById(200) as $v) {
                fputcsv($h, $this->safe([$v->sku, $v->product->name, $v->color, $v->size, $v->price_cents, $v->stock, $v->reserved, $v->product->status]), ',', '"', '');
            }
            fclose($h);
        }, 'products.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function orders()
    {
        return response()->streamDownload(function () {
            $h = fopen('php://output', 'w');
            fputcsv($h, ['Reference', 'Email', 'Created UTC', 'Payment', 'Fulfillment', 'Total cents', 'Refunded cents', 'Currency'], ',', '"', '');
            foreach (Order::lazyById(200) as $o) {
                fputcsv($h, $this->safe([$o->reference, $o->email, $o->created_at->toIso8601String(), $o->payment_status, $o->fulfillment_status, $o->total_cents, $o->refunded_cents, 'USD']), ',', '"', '');
            }
            fclose($h);
        }, 'orders.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
