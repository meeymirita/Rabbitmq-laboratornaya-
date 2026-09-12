<?php

namespace App\Console\Commands\Workers;

use Illuminate\Support\Facades\Mail;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

class EmailWorkerCommand extends AbstractAmqpWorker
{
    protected $signature = 'worker:email';
    protected function queue(): string        { return 'email.queue'; }
    protected function consumerName(): string { return 'email-worker'; }
    private const int MAX_ATTEMPTS = 3;

    protected function process(array $p, AMQPMessage $msg): void
    {
        if (config('lab.simulate_smtp_failure')) {
            throw new \RuntimeException('SMTP server unavailable');
        }
        Mail::raw("Ваш заказ №{$p['order_id']} создан",
            fn ($m) => $m->to("user{$p['user_id']}@lab.test")
                ->subject("Заказ №{$p['order_id']}")
        );
    }

    protected function onFailure(AMQPMessage $msg, \Throwable $e): void
    {
        $headers = $msg->has('application_headers') ? $msg->get('application_headers')->getNativeData() : [];
        $attempt = ((int) ($headers['x-retry-count'] ?? 0)) + 1;

        $copy = new AMQPMessage($msg->getBody(), [
            'content_type'        => 'application/json',
            'delivery_mode'       => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'message_id'          => $msg->get('message_id'),          // тот же id — идемпотентность сохраняется
            'application_headers' => new AMQPTable($headers + ['x-retry-count' => $attempt, 'x-last-error' => $e->getMessage()]),
        ]);

        if ($attempt <= self::MAX_ATTEMPTS) {
            $this->warn("   ✖ {$e->getMessage()} → retry.{$attempt}");
            $this->channel->basic_publish($copy, 'email.retry', "retry.{$attempt}");   // → email.retry.N (TTL) → email.dlx → email.queue
        } else {
            $this->error("   ☠ {$e->getMessage()} → DLQ после {$attempt}-й попытки");
            $this->channel->basic_publish($copy, 'email.dlx', 'dlq');                  // → email.dlq
        }
        $msg->ack();   // оригинал больше не нужен — копия уже в пути
    }
}
