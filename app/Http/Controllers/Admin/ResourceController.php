<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminResourceRequest;
use App\Services\AdminResourceService;
use App\Services\AuditService;
use App\Support\AdminResources;
use Illuminate\Http\Request;

class ResourceController extends Controller
{
    private function model(Request $r, string $resource): string
    {
        [$m,$permission] = AdminResources::definition($resource);
        abort_unless($r->user()->can($permission), 403);

        return $m;
    }

    public function index(Request $r, string $resource)
    {
        $m = $this->model($r, $resource);
        $d = $r->validate(['per_page' => 'nullable|integer|min:1|max:100']);

        return $m::latest('id')->paginate($d['per_page'] ?? 25);
    }

    public function show(Request $r, string $resource, int $id)
    {
        $m = $this->model($r, $resource);

        return ['data' => $m::findOrFail($id)];
    }

    public function save(AdminResourceRequest $r, string $resource, AdminResourceService $s, ?int $id = null)
    {
        return ['data' => $s->save($resource, $r->validated(), $id)];
    }

    public function delete(Request $r, string $resource, int $id)
    {
        $m = $this->model($r, $resource);
        $row = $m::findOrFail($id);
        if ($resource === 'coupons') {
            abort_if($row->used > 0 || $row->reserved > 0, 409, 'Disable used coupons rather than deleting them.');
        }
        app(AuditService::class)->record('resource_deleted', $resource, $row->id);
        $row->delete();

        return response()->noContent();
    }
}
