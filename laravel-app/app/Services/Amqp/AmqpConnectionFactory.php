<?php

namespace App\Services\Amqp;

use PhpAmqpLib\Connection\AMQPStreamConnection;

final class AmqpConnectionFactory
{
    public function connect(): AMQPStreamConnection
    {
        $c = config('rabbitmq');

        return new AMQPStreamConnection(
            $c['host'], $c['port'], $c['user'], $c['password'], $c['vhost'],
            heartbeat: 30, // брокер поймёт, что воркер умер, за ~60 сек
            keepalive: true,
        );
    }
}
