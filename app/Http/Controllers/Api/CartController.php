<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CartRequest;
use App\Services\CartService;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(private CartService $service) {}

    public function create(Request $r)
    {
        $data = $this->service->create($r);

        return response()->json(['data' => $this->service->view($data['cart']), 'cart_token' => $data['cart_token']], 201);
    }

    public function show(Request $r)
    {
        return ['data' => $this->service->view($this->service->resolve($r))];
    }

    public function set(CartRequest $r)
    {
        return ['data' => $this->service->view($this->service->set($r, $r->validated()))];
    }
}
