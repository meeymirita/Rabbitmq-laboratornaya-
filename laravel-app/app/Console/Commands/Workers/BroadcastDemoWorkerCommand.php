<?php

namespace App\Console\Commands\Workers;

use App\Services\Amqp\AmqpConnectionFactory;
use Illuminate\Console\Command;
use PhpAmqpLib\Message\AMQPMessage;

class BroadcastDemoWorkerCommand extends Command
{
    protected $signature = 'worker:broadcast {queue}';

    public function handle(AmqpConnectionFactory $factory): int
    {
        $conn = $factory->connect();
        $ch = $conn->channel();

        $ch->basic_consume(
            queue: $this->argument('queue'),
            consumer_tag: '',
            no_ack: true,
            callback: fn (AMQPMessage $m) => $this->info("[{$this->argument('queue')}] " . $m->getBody()),
        );

        while ($ch->is_consuming()) {
            $ch->wait();
        }

        return self::SUCCESS;
    }
}
