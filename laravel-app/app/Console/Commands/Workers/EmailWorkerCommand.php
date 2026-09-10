<?php

namespace App\Console\Commands\Workers;

use Illuminate\Support\Facades\Mail;
use PhpAmqpLib\Message\AMQPMessage;

class EmailWorkerCommand extends AbstractAmqpWorker
{
    protected $signature = 'worker:email';
    protected function queue(): string        { return 'email.queue'; }
    protected function consumerName(): string { return 'email-worker'; }

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
}
