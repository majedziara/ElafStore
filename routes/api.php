<?php

use App\Http\Controllers\Admin\ContactController as AdminContactController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ExportController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ResourceController;
use App\Http\Controllers\Admin\ReturnController as AdminReturnController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\TeamController;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\GatewayCallbackController;
use App\Http\Controllers\Api\GatewayWebhookController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PayPalCallbackController;
use App\Http\Controllers\Api\PayPalWebhookController;
use App\Http\Controllers\Api\ReturnController;
use Illuminate\Support\Facades\Route;

Route::get('payments/{gateway}/{action}', GatewayCallbackController::class)->whereIn('gateway', ['stripe', 'triple-a'])->whereIn('action', ['return', 'cancel'])->middleware('throttle:payments');

Route::get('paypal/return', [PayPalCallbackController::class, 'returned'])->name('paypal.return')->middleware('throttle:payments');
Route::get('paypal/cancel', [PayPalCallbackController::class, 'canceled'])->name('paypal.cancel')->middleware('throttle:payments');

Route::prefix('v1')->group(function () {
    Route::get('products', [CatalogController::class, 'index']);
    Route::get('products/{slug}', [CatalogController::class, 'show']);
    Route::get('categories', [CatalogController::class, 'categories']);
    Route::get('collections', [CatalogController::class, 'collections']);
    Route::get('pages/{slug}', [CatalogController::class, 'page']);
    Route::get('faqs', [CatalogController::class, 'faqs']);
    Route::get('settings', [CatalogController::class, 'settings']);
    Route::get('shipping-methods', [CatalogController::class, 'shipping']);
    Route::post('webhooks/{gateway}/{environment}', GatewayWebhookController::class)->whereIn('gateway', ['stripe', 'triple-a'])->whereIn('environment', ['sandbox', 'live']);
    Route::get('payment-methods', [PaymentController::class, 'methods']);
    Route::post('contact', [ContactController::class, 'store'])->middleware('throttle:links');
    Route::middleware('throttle:public-write')->group(function () {
        Route::post('cart', [CartController::class, 'create']);
        Route::get('cart', [CartController::class, 'show']);
        Route::post('checkout/quote', [OrderController::class, 'quote']);
        Route::put('cart/items', [CartController::class, 'set']);
        Route::post('checkout', [OrderController::class, 'checkout']);
    });
    Route::post('guest-orders/access-link', [OrderController::class, 'lookup'])->middleware('throttle:links');
    Route::post('guest-orders/consume', [OrderController::class, 'consume'])->middleware('throttle:links');
    Route::get('guest-orders/consume', [OrderController::class, 'accessLink'])->name('guest-orders.open')->middleware('throttle:links');
    Route::get('orders/{order:uuid}', [OrderController::class, 'show']);
    Route::get('orders/{order:uuid}/receipt', [OrderController::class, 'receipt']);
    Route::post('orders/{order:uuid}/cancel', [OrderController::class, 'cancel'])->middleware('throttle:public-write');
    Route::post('orders/{order:uuid}/payments/{gateway}', [PaymentController::class, 'start'])->whereIn('gateway', ['stripe', 'triple-a'])->middleware('throttle:payments');
    Route::post('orders/{order:uuid}/payments/paypal', [PaymentController::class, 'start'])->middleware('throttle:payments');
    Route::post('orders/{order:uuid}/payments/capture', [PaymentController::class, 'capture'])->middleware('throttle:payments');
    Route::get('orders/{order:uuid}/payments/status', [PaymentController::class, 'status'])->middleware('throttle:payments');
    Route::get('orders/{order:uuid}/returns', [ReturnController::class, 'index']);
    Route::post('orders/{order:uuid}/returns', [ReturnController::class, 'create'])->middleware('throttle:public-write');
    Route::get('returns/{returnRequest:uuid}', [ReturnController::class, 'show']);
    Route::post('returns/{returnRequest:uuid}/photos', [ReturnController::class, 'photos'])->middleware('throttle:public-write');
    Route::get('returns/{returnRequest:uuid}/photos/{index}', [ReturnController::class, 'photo'])->whereNumber('index');
    Route::post('webhooks/paypal/{environment}', PayPalWebhookController::class)->whereIn('environment', ['sandbox', 'live']);
    Route::middleware(['auth:sanctum', 'active'])->prefix('account')->group(function () {
        Route::post('orders/{order:uuid}/claim', [OrderController::class, 'claim']);
        Route::get('overview', [AccountController::class, 'overview']);
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('addresses', [AccountController::class, 'addresses']);
        Route::post('addresses', [AccountController::class, 'saveAddress']);
        Route::put('addresses/{address}', [AccountController::class, 'saveAddress']);
        Route::delete('addresses/{address}', [AccountController::class, 'removeAddress']);
        Route::get('wishlist', [AccountController::class, 'wishlist']);
        Route::put('wishlist/{product}', [AccountController::class, 'addWishlist']);
        Route::delete('wishlist/{product}', [AccountController::class, 'removeWishlist']);
    });
    Route::middleware(['auth:sanctum', 'active', 'admin.verified'])->prefix('admin')->group(function () {
        Route::get('dashboard', DashboardController::class)->middleware('can:dashboard.view');
        Route::get('exports/products', [ExportController::class, 'products'])->middleware('can:catalog.manage');
        Route::get('exports/orders', [ExportController::class, 'orders'])->middleware('can:orders.view');
        Route::middleware('can:catalog.manage')->group(function () {
            Route::get('products', [ProductController::class, 'index']);
            Route::post('products', [ProductController::class, 'save']);
            Route::get('products/{product}', [ProductController::class, 'show']);
            Route::put('products/{product}', [ProductController::class, 'save']);
            Route::delete('products/{product}', [ProductController::class, 'archive']);
            Route::post('variants/{variant}/stock', [ProductController::class, 'adjust']);
            Route::post('products/{product}/images', [ProductController::class, 'image']);
            Route::put('products/{product}/images/{image}', [ProductController::class, 'updateImage']);
            Route::delete('products/{product}/images/{image}', [ProductController::class, 'deleteImage']);
        });
        Route::get('resources/{resource}', [ResourceController::class, 'index']);
        Route::post('resources/{resource}', [ResourceController::class, 'save']);
        Route::get('resources/{resource}/{id}', [ResourceController::class, 'show'])->whereNumber('id');
        Route::put('resources/{resource}/{id}', [ResourceController::class, 'save'])->whereNumber('id');
        Route::delete('resources/{resource}/{id}', [ResourceController::class, 'delete'])->whereNumber('id');
        Route::get('orders', [AdminOrderController::class, 'index'])->middleware('can:orders.view');
        Route::get('orders/{order:uuid}', [AdminOrderController::class, 'show'])->middleware('can:orders.view');
        Route::middleware('can:orders.manage')->group(function () {
            Route::post('orders/{order:uuid}/notes', [AdminOrderController::class, 'note']);
            Route::post('orders/{order:uuid}/process', [AdminOrderController::class, 'process']);
            Route::post('orders/{order:uuid}/cancel', [AdminOrderController::class, 'cancel']);
            Route::post('orders/{order:uuid}/allocate-stock', [AdminOrderController::class, 'allocate']);
            Route::post('orders/{order:uuid}/shipments', [AdminOrderController::class, 'ship']);
            Route::post('shipments/{shipment}/delivered', [AdminOrderController::class, 'delivered']);
        });
        Route::middleware('can:returns.manage')->group(function () {
            Route::get('returns', [AdminReturnController::class, 'index']);
            Route::get('returns/{returnRequest:uuid}', [AdminReturnController::class, 'show']);
            Route::put('returns/{returnRequest:uuid}', [AdminReturnController::class, 'review']);
            Route::post('returns/{returnRequest:uuid}/restock', [AdminReturnController::class, 'restock']);
            Route::get('returns/{returnRequest:uuid}/photos/{index}', [AdminReturnController::class, 'photo'])->whereNumber('index');
        });
        Route::post('orders/{order:uuid}/refunds', [AdminPaymentController::class, 'refund'])->middleware('can:refunds.create');
        Route::post('refunds/{refund}/sync', [AdminPaymentController::class, 'syncRefund'])->middleware('can:refunds.create');
        Route::middleware('can:payments.manage')->group(function () {
            Route::get('payments', [AdminPaymentController::class, 'index']);
            Route::post('payments/{attempt}/sync', [AdminPaymentController::class, 'sync']);
            Route::get('webhooks', [AdminPaymentController::class, 'webhooks']);
            Route::post('webhooks/{event}/retry', [AdminPaymentController::class, 'retry']);
            Route::get('gateways', [SettingsController::class, 'gateways']);
            Route::put('gateways/{slug}', [SettingsController::class, 'gateway']);
        });
        Route::get('settings', [SettingsController::class, 'index'])->middleware('can:settings.manage');
        Route::put('settings', [SettingsController::class, 'save'])->middleware('can:settings.manage');
        Route::post('media', [SettingsController::class, 'media'])->middleware('can:content.manage');
        Route::get('customers', [CustomerController::class, 'index'])->middleware('can:customers.view');
        Route::get('customers/{customer}', [CustomerController::class, 'show'])->middleware('can:customers.view');
        Route::middleware('can:messages.manage')->group(function () {
            Route::get('contact-messages', [AdminContactController::class, 'index']);
            Route::get('contact-messages/{message}', [AdminContactController::class, 'show']);
            Route::put('contact-messages/{message}', [AdminContactController::class, 'update']);
        });
        Route::middleware('can:team.manage')->group(function () {
            Route::get('audit-log', [TeamController::class, 'audit']);
            Route::put('roles/{role}/permissions', [TeamController::class, 'rolePermissions']);
            Route::get('team', [TeamController::class, 'index']);
            Route::get('roles', [TeamController::class, 'roles']);
            Route::post('team', [TeamController::class, 'save']);
            Route::put('team/{member}', [TeamController::class, 'save']);
        });
    });
});
