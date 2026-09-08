<?php

namespace App\Services;

use App\Models\OutboxMessage;

final class OutboxWriter
{
    /**
     * @param string $routingKey
     * @param array $payload
     * @param int $priority
     * @return OutboxMessage
     */
    public function write(string $routingKey, array $payload, int $priority = 0): OutboxMessage
    {
        return OutboxMessage::query()->create([
            'routing_key' => $routingKey,
            'payload'     => $payload + ['event' => $routingKey, 'occurred_at' => now()->toIso8601String()],
            'priority'    => $priority,
            'status'      => 'pending',
        ]);
    }
}
