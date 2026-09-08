<?php
return [
    'host'     => env('RABBITMQ_HOST', 'rabbitmq'),
    'port'     => (int) env('RABBITMQ_PORT', 5672),
    'user'     => env('RABBITMQ_USER', 'lab'),
    'password' => env('RABBITMQ_PASSWORD', 'lab'),
    'vhost'    => env('RABBITMQ_VHOST', '/'),
    'prefetch' => (int) env('WORKER_PREFETCH', 1),
];
