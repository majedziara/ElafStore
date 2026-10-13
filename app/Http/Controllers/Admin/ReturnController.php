<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ReturnRequest;
use App\Services\ReturnService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ReturnController extends Controller
{
    public function index(Request $r)
    {
        $d = $r->validate(['status' => 'nullable|in:submitted,under_review,approved,rejected,received,completed']);

        return ReturnRequest::with(['order', 'items'])->when(isset($d['status']), fn ($q) => $q->where('status', $d['status']))->latest('id')->paginate(25);
    }

    public function show(ReturnRequest $returnRequest)
    {
        return ['data' => $returnRequest->load(['order', 'items.orderItem'])];
    }

    public function review(Request $r, ReturnRequest $returnRequest, ReturnService $s)
    {
        $d = $r->validate(['status' => 'required|in:under_review,approved,rejected,received,completed', 'admin_note' => 'nullable|string|max:3000']);

        return ['data' => $s->review($returnRequest, $d['status'], $d['admin_note'] ?? null, $r->user()->id)];
    }

    public function restock(Request $r, ReturnRequest $returnRequest, ReturnService $s)
    {
        $s->restock($returnRequest, $r->user()->id);

        return response()->noContent();
    }

    public function photo(ReturnRequest $returnRequest, int $index)
    {
        $path = ($returnRequest->photo_paths ?? [])[$index] ?? null;
        abort_unless($path, 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, no-store']);
    }
}
