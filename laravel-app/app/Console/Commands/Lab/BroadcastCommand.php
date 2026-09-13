<?php

namespace App\Console\Commands\Lab;

use App\Services\Amqp\AmqpConnectionFactory;
use Illuminate\Console\Command;
use PhpAmqpLib\Message\AMQPMessage;

class BroadcastCommand extends Command
{
    protected $signature = 'lab:broadcast {text}';

    public function handle(AmqpConnectionFactory $factory): int
    {
        $conn = $factory->connect();
        $ch = $conn->channel();

        $ch->basic_publish(new AMQPMessage($this->argument('text')), 'system.broadcast', 'этот-ключ-никто-не-читает');
        $this->info("published to system.broadcast: {$this->argument('text')}");

        $ch->close();
        $conn->close();
        return self::SUCCESS;
    }
}
