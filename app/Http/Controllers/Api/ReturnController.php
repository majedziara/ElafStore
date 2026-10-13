<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReturnStoreRequest;
use App\Models\Order;
use App\Models\ReturnRequest;
use App\Services\OrderAccessService;
use App\Services\ReturnService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ReturnController extends Controller
{
    public function __construct(private OrderAccessService $access) {}

    public function index(Request $r, Order $order)
    {
        $this->access->authorize($r, $order);

        return ['data' => $order->returns()->with('items')->latest('id')->get()->makeHidden(['admin_note', 'photo_paths'])];
    }

    public function create(ReturnStoreRequest $r, Order $order, ReturnService $s)
    {
        $this->access->authorize($r, $order);

        return response()->json(['data' => $s->create($order, $r->validated())->makeHidden(['admin_note', 'photo_paths'])], 201);
    }

    public function show(Request $r, ReturnRequest $returnRequest)
    {
        $this->access->authorize($r, $returnRequest->order);

        return ['data' => $returnRequest->load('items')->makeHidden(['admin_note', 'photo_paths'])];
    }

    public function photos(Request $r, ReturnRequest $returnRequest)
    {
        $this->access->authorize($r, $returnRequest->order);
        $r->validate(['photos' => 'required|array|min:1|max:5', 'photos.*' => 'required|image|mimes:jpg,jpeg,png,webp|max:8192']);
        $stored = [];
        try {
            foreach ($r->file('photos') as $file) {
                $stored[] = $file->store('return-photos', 'local');
            }
            DB::transaction(function () use ($returnRequest, $stored) {
                $rr = ReturnRequest::whereKey($returnRequest->id)->lockForUpdate()->firstOrFail();
                abort_unless(in_array($rr->status, ['submitted', 'under_review'], true), 422, 'Photos can no longer be changed.');
                $paths = array_merge($rr->photo_paths ?? [], $stored);
                abort_if(count($paths) > 5, 422, 'Maximum five photos per request.');
                $rr->update(['photo_paths' => $paths]);
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($stored);
            throw $e;
        }

        return ['data' => ['photo_count' => count($returnRequest->fresh()->photo_paths ?? [])]];
    }

    public function photo(Request $r, ReturnRequest $returnRequest, int $index)
    {
        $this->access->authorize($r, $returnRequest->order);
        $path = ($returnRequest->photo_paths ?? [])[$index] ?? null;
        abort_unless($path, 404);

        return Storage::disk('local')->response($path,null,['Cache-Control' => 'private, no-store']);
    }
}
