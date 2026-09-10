<?php

namespace App\Console\Commands\Workers;

use App\Models\OutboxMessage;
use App\Services\Amqp\AmqpConnectionFactory;
use Illuminate\Console\Command;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

class OutboxRelayCommand extends Command
{
    protected $signature   = 'worker:outbox-relay {--sleep=1}';
    protected $description = 'Читает outbox_messages и публикует в orders.topic с publisher confirms';

    public function handle(AmqpConnectionFactory $factory): int
    {
        $conn = $factory->connect();
        $ch   = $conn->channel();
        $ch->confirm_select();                                    // включаем publisher confirms
        $ch->set_nack_handler(fn (AMQPMessage $m) => $this->error("broker NACKed " . $m->get('message_id')));

        while (true) {
            $batch = OutboxMessage::where('status', 'pending')->orderBy('created_at')->limit(50)->get();

            foreach ($batch as $row) {
                $msg = new AMQPMessage(json_encode($row->payload), [
                    'content_type'        => 'application/json',
                    'delivery_mode'       => AMQPMessage::DELIVERY_MODE_PERSISTENT,   // persistent
                    'message_id'          => $row->id,
                    'priority'            => $row->priority,
                    'timestamp'           => time(),
                    'application_headers' => new AMQPTable(['x-retry-count' => 0]),
                ]);
                $ch->basic_publish($msg, 'orders.topic', $row->routing_key);
                $ch->wait_for_pending_acks(timeout: 5);           // блокируемся до confirm от брокера
                $row->update(['status' => 'sent', 'published_at' => now()]);
                $this->info("→ {$row->routing_key} {$row->id}");
            }

            if ($batch->isEmpty()) { sleep((int) $this->option('sleep')); }
        }
    }
}
