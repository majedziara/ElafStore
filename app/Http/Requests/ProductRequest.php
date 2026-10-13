<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $product = $this->route('product');
        $id = $product instanceof Product ? $product->id : null;

        return [
            'name' => 'required|string|max:160', 'slug' => ['required', 'alpha_dash', 'max:160', Rule::unique('products')->ignore($id)],
            'description' => 'nullable|string|max:30000', 'materials' => 'nullable|string|max:5000', 'care' => 'nullable|string|max:5000',
            'category_id' => 'nullable|integer|exists:categories,id', 'size_guide_id' => 'nullable|integer|exists:size_guides,id',
            'status' => 'required|in:draft,published,archived', 'returnable' => 'required|boolean', 'weight_grams' => 'integer|min:0|max:100000',
            'seo_title' => 'nullable|string|max:160', 'seo_description' => 'nullable|string|max:300',
            'collection_ids' => 'present|array|max:30', 'collection_ids.*' => 'integer|distinct|exists:collections,id',
            'variants.*' => 'required|array:id,sku,color,size,price_cents,compare_at_cents,stock,is_active', 'variants' => 'required|array|min:1|max:100', 'variants.*.id' => 'nullable|integer|distinct',
            'variants.*.sku' => 'required|string|max:80|distinct', 'variants.*.color' => 'required|string|max:80',
            'variants.*.size' => 'required|string|max:30', 'variants.*.price_cents' => 'required|integer|min:1|max:100000000',
            'variants.*.compare_at_cents' => 'nullable|integer|min:1|max:100000000',
            'variants.*.stock' => 'required|integer|min:0|max:1000000', 'variants.*.is_active' => 'required|boolean',
        ];
    }
}
