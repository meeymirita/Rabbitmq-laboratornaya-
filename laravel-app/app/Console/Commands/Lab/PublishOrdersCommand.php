<?php

namespace App\Console\Commands\Lab;
use App\Models\Order;
use App\Services\OutboxWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
class PublishOrdersCommand extends Command
{
    protected $signature = 'lab:orders {count=100} {--priority=0}';

    public function handle(OutboxWriter $outbox): int
    {
        for ($i = 0; $i < (int) $this->argument('count'); $i++) {
            DB::transaction(function () use ($outbox) {
                $order = Order::create(['user_id' => random_int(1, 50), 'priority' => (int) $this->option('priority')]);
                $items = [['product_id' => random_int(1, 2), 'quantity' => 1]];   // Monitor не трогаем
                $order->items()->createMany($items);
                $outbox->write('order.created', ['order_id' => $order->id, 'user_id' => $order->user_id, 'items' => $items], $order->priority);
            });
        }
        $this->info("queued {$this->argument('count')} orders");
        return self::SUCCESS;
    }
}
