<?php
return [
    'simulate_smtp_failure' => (bool) env('LAB_SIMULATE_SMTP_FAILURE', false),
    'crash_before_ack'      => (bool) env('LAB_CRASH_BEFORE_ACK', false),
    'slow_consumer_ms'      => (int)  env('LAB_SLOW_CONSUMER_MS', 0),
];
