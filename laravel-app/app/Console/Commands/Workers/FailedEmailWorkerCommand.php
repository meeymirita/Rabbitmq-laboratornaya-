<?php

namespace App\Console\Commands\Workers;

use Illuminate\Console\Command;
use App\Services\Amqp\AmqpConnectionFactory;
use PhpAmqpLib\Message\AMQPMessage;
class FailedEmailWorkerCommand extends Command
{
    protected $signature = 'worker:failed-email';

    public function handle(AmqpConnectionFactory $factory): int
    {
        $ch = $factory->connect()->channel();
        $ch->basic_consume('email.dlq', 'failed-email', no_ack: false, callback: function (AMQPMessage $m) {
            $h = $m->get('application_headers')?->getNativeData() ?? [];
            $this->table(['field', 'value'], [
                ['message_id', $m->get('message_id')],
                ['attempts',   $h['x-retry-count'] ?? '?'],
                ['reason',     $h['x-last-error'] ?? '?'],
                ['failed_at',  now()->toDateTimeString()],
                ['body',       $m->getBody()],
            ]);
            $m->ack();
        });
        while ($ch->is_consuming()) { $ch->wait(); }
        return self::SUCCESS;
    }
}
