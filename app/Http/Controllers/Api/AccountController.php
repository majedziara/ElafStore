<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddressRequest;
use App\Http\Resources\ProductResource;
use App\Models\Address;
use App\Models\Order;
use App\Models\Product;
use App\Models\Wishlist;
use App\Services\AddressService;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function overview(Request $r)
    {
        return ['data' => ['orders_count' => Order::where('user_id', $r->user()->id)->count(),
            'wishlist_count' => Wishlist::where('user_id', $r->user()->id)->count(),
            'recent_order' => Order::with('items')->where('user_id', $r->user()->id)->latest('id')->first(),
            'addresses' => Address::where('user_id', $r->user()->id)->get()]];
    }

    public function addresses(Request $r)
    {
        return ['data' => $r->user()->addresses()->get()];
    }

    public function saveAddress(AddressRequest $r, AddressService $s, ?Address $address = null)
    {
        return ['data' => $s->save($r->user(), $r->validated(), $address)];
    }

    public function removeAddress(Request $r, Address $address, AddressService $s)
    {
        $s->remove($r->user(), $address);

        return response()->noContent();
    }

    public function wishlist(Request $r)
    {
        return ProductResource::collection(Product::with(['category', 'collections', 'variants', 'images', 'sizeGuide'])->where('status', 'published')
            ->whereIn('id', Wishlist::where('user_id', $r->user()->id)->select('product_id'))->paginate(24));
    }

    public function addWishlist(Request $r, Product $product)
    {
        abort_unless($product->status === 'published', 404);
        Wishlist::firstOrCreate(['user_id' => $r->user()->id, 'product_id' => $product->id]);

        return response()->noContent();
    }

    public function removeWishlist(Request $r, Product $product)
    {
        Wishlist::where('user_id', $r->user()->id)->where('product_id', $product->id)->delete();

        return response()->noContent();
    }
}
