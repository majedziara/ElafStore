<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Collection;
use App\Models\ContentPage;
use App\Models\Faq;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Services\CatalogService;
use App\Services\SettingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CatalogController extends Controller
{
    public function index(Request $r, CatalogService $service)
    {
        $f = $r->validate(['q' => 'nullable|string|max:100', 'category' => 'nullable|string|max:160', 'collection' => 'nullable|string|max:160',
            'color' => 'nullable|string|max:80', 'size' => 'nullable|string|max:30', 'min_cents' => 'nullable|integer|min:0|max:100000000',
            'max_cents' => 'nullable|integer|min:0|max:100000000', 'in_stock' => 'nullable|boolean', 'sort' => 'nullable|in:newest,price_asc,price_desc', 'per_page' => 'nullable|integer|min:1|max:48']);

        return ProductResource::collection($service->query($f)->paginate($f['per_page'] ?? 24)->withQueryString());
    }

    public function show(string $slug)
    {
        return new ProductResource(Product::with(['category', 'collections', 'variants', 'images', 'sizeGuide'])->where('status', 'published')->where('slug', $slug)->firstOrFail());
    }

    public function categories()
    {
        return ['data' => Category::where('is_active', true)->orderBy('sort_order')->get()];
    }

    public function collections()
    {
        return ['data' => Collection::where('is_active', true)->orderBy('sort_order')->get()];
    }

    public function page(string $slug)
    {
        return ['data' => ContentPage::where('slug', $slug)->where('status', 'published')->firstOrFail()];
    }

    public function faqs()
    {
        return ['data' => Faq::where('is_active', true)->orderBy('sort_order')->get()];
    }

    public function settings(SettingService $s)
    {
        return ['data' => $s->publicSettings()];
    }

    public function shipping(Request $r)
    {
        $d = $r->validate(['state' => ['required', Rule::in(config('store.states'))]]);

        return ['data' => ShippingMethod::where('is_active', true)->get()->filter(fn ($m) => ! $m->states || in_array($d['state'], $m->states, true))->values()];
    }
}
