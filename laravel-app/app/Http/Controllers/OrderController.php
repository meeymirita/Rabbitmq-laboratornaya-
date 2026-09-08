<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OutboxWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function store(Request $request, OutboxWriter $outbox): JsonResponse
    {
        $data = $request->validate([
            'user_id'            => 'required|integer',
            'priority'           => 'sometimes|integer|min:0|max:10',
            'items'              => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.quantity'   => 'required|integer|min:1',
        ]);

        $order = DB::transaction(function () use ($data, $outbox) {
            $order = Order::create([
                'user_id'  => $data['user_id'],
                'priority' => $data['priority'] ?? 0,
            ]);
            $order->items()->createMany($data['items']);

            $outbox->write('order.created', [
                'order_id' => $order->id,
                'user_id'  => $order->user_id,
                'items'    => $data['items'],
            ], $order->priority);

            return $order;
        });

        return response()->json($order->load('items'), 201);
    }
}
