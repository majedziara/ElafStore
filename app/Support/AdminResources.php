<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Collection;
use App\Models\ContentPage;
use App\Models\Coupon;
use App\Models\Faq;
use App\Models\ShippingMethod;
use App\Models\SizeGuide;
use App\Models\TaxRate;
use Illuminate\Validation\Rule;

final class AdminResources
{
    public static function definition(string $resource, ?int $id = null): array
    {
        $slug = fn ($table) => ['required', 'alpha_dash', 'max:160', Rule::unique($table, 'slug')->ignore($id)];
        $basic = ['name' => 'required|string|max:160', 'description' => 'nullable|string|max:10000', 'is_active' => 'required|boolean', 'sort_order' => 'integer|min:0|max:10000'];
        $definition = match ($resource) {
            'categories' => [Category::class, 'catalog.manage', $basic + ['slug' => $slug('categories'), 'parent_id' => 'nullable|integer|exists:categories,id']],
            'collections' => [Collection::class, 'catalog.manage', $basic + ['slug' => $slug('collections')]],
            'size-guides' => [SizeGuide::class, 'catalog.manage', ['name' => 'required|string|max:120', 'content' => 'required|array']],
            'shipping-methods' => [ShippingMethod::class, 'settings.manage', ['name' => 'required|string|max:120', 'states' => 'nullable|array', 'states.*' => [Rule::in(config('store.states'))],
                'price_cents' => 'required|integer|min:0|max:1000000', 'free_above_cents' => 'nullable|integer|min:0|max:100000000', 'is_active' => 'required|boolean']],
            'tax-rates' => [TaxRate::class, 'settings.manage', ['state' => ['required', Rule::in(config('store.states'))],
                'postal_code' => ['nullable', 'regex:/^\d{5}(-\d{4})?$/D'], 'basis_points' => 'required|integer|min:0|max:10000', 'tax_shipping' => 'required|boolean']],
            'coupons' => [Coupon::class, 'discounts.manage', ['code' => ['required', 'regex:/^[A-Z0-9_-]{3,60}$/D', Rule::unique('coupons')->ignore($id)],
                'type' => 'required|in:percentage,fixed', 'value' => 'required|integer|min:1|max:100000000', 'minimum_cents' => 'integer|min:0|max:100000000',
                'maximum_discount_cents' => 'nullable|integer|min:1|max:100000000', 'usage_limit' => 'nullable|integer|min:1|max:1000000',
                'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date', 'product_ids' => 'nullable|array|max:1000',
                'product_ids.*' => 'integer|distinct|exists:products,id', 'excluded_product_ids' => 'nullable|array|max:1000',
                'excluded_product_ids.*' => 'integer|distinct|exists:products,id', 'is_active' => 'required|boolean']],
            'pages' => [ContentPage::class, 'content.manage', ['slug' => $slug('content_pages'), 'title' => 'required|string|max:180', 'blocks' => 'required|array|max:100',
                'blocks.*' => 'required|array:type,text,image_path,alt,url,product_ids', 'blocks.*.type' => 'required|in:heading,paragraph,image,link,products', 'blocks.*.text' => 'nullable|string|max:20000',
                'blocks.*.image_path' => 'nullable|string|max:255', 'blocks.*.alt' => 'nullable|string|max:180',
                'blocks.*.url' => 'nullable|url:https|max:500', 'blocks.*.product_ids' => 'nullable|array|max:30',
                'blocks.*.product_ids.*' => 'integer|exists:products,id', 'status' => 'required|in:draft,published',
                'seo_title' => 'nullable|string|max:160', 'seo_description' => 'nullable|string|max:300']],
            'faqs' => [Faq::class, 'content.manage', ['category' => 'required|string|max:100', 'question' => 'required|string|max:300', 'answer' => 'required|string|max:10000',
                'is_active' => 'required|boolean', 'sort_order' => 'integer|min:0|max:10000']],
            default => null,
        };
        abort_unless($definition, 404);

        return $definition;
    }
}
