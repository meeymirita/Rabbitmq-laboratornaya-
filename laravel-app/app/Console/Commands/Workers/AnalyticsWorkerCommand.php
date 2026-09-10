<?php

namespace App\Console\Commands\Workers;

use App\Models\OrderEvent;
use PhpAmqpLib\Message\AMQPMessage;

class AnalyticsWorkerCommand extends AbstractAmqpWorker
{
    protected $signature = 'worker:analytics';
    protected function queue(): string        { return 'analytics.queue'; }
    protected function consumerName(): string { return 'analytics-worker'; }

    protected function process(array $p, AMQPMessage $msg): void
    {
        OrderEvent::query()->create([
            'order_id' => $p['order_id'], 'event_type' => $p['event'],
            'payload'  => $p,            'occurred_at' => $p['occurred_at'],
        ]);
    }
}
