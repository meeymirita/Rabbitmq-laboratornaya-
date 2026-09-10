<?php

namespace App\Console\Commands\Workers;

use App\Models\{Order, Product};
use App\Services\OutboxWriter;
use PhpAmqpLib\Message\AMQPMessage;

class OrderWorkerCommand extends AbstractAmqpWorker
{
    protected $signature = 'worker:order';
    protected function queue(): string        { return 'order.queue'; }
    protected function consumerName(): string { return 'order-worker'; }

    protected function process(array $p, AMQPMessage $msg): void
    {
        $items = collect($p['items'])->sortBy('product_id');       // одинаковый порядок блокировок → нет deadlock
        foreach ($items as $item) {
            $product = Product::query()->lockForUpdate()->findOrFail($item['product_id']);
            if ($product->stock < $item['quantity']) {
                throw new \DomainException("not enough stock for product {$product->id}");
            }
            $product->decrement('stock', $item['quantity']);
        }
        Order::query()->whereKey($p['order_id'])->update(['status' => 'reserved']);
        app(OutboxWriter::class)->write('order.reserved', ['order_id' => $p['order_id']]);  // та же транзакция
    }
}
