<?php

namespace App\Services;

use App\Models\Category;
use App\Models\TaxRate;
use App\Support\AdminResources;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class AdminResourceService
{
    public function save(string $resource, array $data, ?int $id = null)
    {
        [$model] = AdminResources::definition($resource, $id);

        return DB::transaction(function () use ($resource, $data, $id, $model) {
            $row = $id ? $model::whereKey($id)->lockForUpdate()->firstOrFail() : new $model;
            if ($resource === 'tax-rates') {
                $duplicate = TaxRate::where('state', $data['state'])->where('postal_code', $data['postal_code'] ?? null)->when($id, fn ($q) => $q->where('id', '!=', $id))->exists();
                abort_if($duplicate, 422, 'A tax rate already exists for this state/postal scope.');
            }
            if ($resource === 'coupons') {
                if (! empty($data['starts_at']) && ! empty($data['ends_at'])) {
                    abort_if(Carbon::parse($data['ends_at']) <= Carbon::parse($data['starts_at']), 422, 'End date must follow start date.');
                }
                abort_if($data['type'] === 'percentage' && $data['value'] > 10000, 422, 'Percentage value uses basis points: 10000 = 100%.');
                abort_if($row->exists && $row->reserved > 0, 409, 'Coupon has active order reservations.');
                abort_if(isset($data['usage_limit']) && $data['usage_limit'] < ($row->used ?? 0), 422, 'Limit is below committed usage.');
            }
            if ($resource === 'categories' && ! empty($data['parent_id'])) {
                $ancestor = Category::findOrFail($data['parent_id']);
                $seen = [];
                while ($ancestor) {
                    abort_if($ancestor->id === $id || isset($seen[$ancestor->id]), 422, 'Category hierarchy cycle.');
                    $seen[$ancestor->id] = true;
                    $ancestor = $ancestor->parent_id ? Category::find($ancestor->parent_id) : null;
                }
            }
            $row->fill($data);
            $row->save();
            app(AuditService::class)->record('resource_saved', $resource, $row->id, ['fields' => array_keys($data)]);

            return $row->fresh();
        }, 3);
    }
}
