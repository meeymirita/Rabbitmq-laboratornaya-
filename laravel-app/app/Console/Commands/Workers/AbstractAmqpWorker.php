<?php

namespace App\Console\Commands\Workers;

use App\Models\ProcessedMessage;
use App\Services\Amqp\AmqpConnectionFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

abstract class AbstractAmqpWorker extends Command
{
    private bool $stopping = false;
    protected AMQPChannel $channel;

    abstract protected function queue(): string;
    abstract protected function consumerName(): string;
    /** Бизнес-логика. Бросай исключение — попадёшь в onFailure(). */
    abstract protected function process(array $payload, AMQPMessage $msg): void;

    public function handle(AmqpConnectionFactory $factory): int
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->stopping = true);   // docker stop
        pcntl_signal(SIGINT,  fn () => $this->stopping = true);   // Ctrl+C

        $conn = $factory->connect();
        $this->channel = $conn->channel();
        $this->channel->basic_qos(0, config('rabbitmq.prefetch'), false);      // prefetch
        $this->channel->basic_consume(
            queue: $this->queue(), consumer_tag: $this->consumerName() . '-' . getmypid(),
            no_ack: false,                                                    // ручной ack
            callback: fn (AMQPMessage $m) => $this->handleMessage($m),
        );
        $this->info("[{$this->consumerName()}] listening {$this->queue()} prefetch=" . config('rabbitmq.prefetch'));

        while ($this->channel->is_consuming() && !$this->stopping) {
            try { $this->channel->wait(timeout: 1); }                        // раз в секунду проверяем флаг
            catch (AMQPTimeoutException) { /* тишина — это нормально */ }
        }

        $this->info('graceful shutdown: закрываем канал');
        $this->channel->close(); $conn->close();
        return self::SUCCESS;
    }

    protected function handleMessage(AMQPMessage $msg): void
    {
        $id      = $msg->get('message_id');
        $payload = json_decode($msg->getBody(), true);
        $redeliv = $msg->get('redelivered') ? ' (REDELIVERED)' : '';
        $this->line("← {$id}{$redeliv}");

        if (ProcessedMessage::where('message_id', $id)->where('consumer', $this->consumerName())->exists()) {
            $this->warn("   duplicate → ack без обработки");
            $msg->ack();
            return;
        }

        if ($ms = config('lab.slow_consumer_ms')) { usleep($ms * 1000); }     // "медленный consumer"

        try {
            DB::transaction(function () use ($payload, $msg, $id) {
                $this->process($payload, $msg);
                ProcessedMessage::create([
                    'message_id' => $id, 'consumer' => $this->consumerName(), 'processed_at' => now(),
                ]);
            });
        } catch (\Throwable $e) {
            $this->onFailure($msg, $e);
            return;
        }

        if (config('lab.crash_before_ack')) {                                  // "💥 упали до ACK"
            $this->error('CRASH before ack'); posix_kill(getmypid(), SIGKILL);
        }
        $msg->ack();
        $this->info("   ✔ ack");
    }

    /** По умолчанию: отклонить без requeue (уйдёт в DLX очереди, если он настроен, иначе — исчезнет). */
    protected function onFailure(AMQPMessage $msg, \Throwable $e): void
    {
        $this->error("   ✖ {$e->getMessage()} → nack(requeue=false)");
        $msg->nack(requeue: false);
    }
}
