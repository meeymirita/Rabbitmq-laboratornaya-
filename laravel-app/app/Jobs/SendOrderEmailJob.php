<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class SendOrderEmailJob implements ShouldQueue
{
    use Queueable;
    public int $tries = 4;
    public array $backoff = [10, 30, 300];      // те же 10с / 30с / 5мин — но одной строкой

    public function __construct(public int $orderId, public int $userId) {}

    public function handle(): void
    {
        if (config('lab.simulate_smtp_failure')) { throw new \RuntimeException('SMTP server unavailable'); }
        Mail::raw("Заказ №{$this->orderId} (via Laravel Queue)", fn ($m) => $m->to("user{$this->userId}@lab.test"));
    }
}
