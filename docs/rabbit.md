# Справочные материалы — RabbitMQ Lab

Ссылки, собранные по ходу лабы, сгруппированные по темам (см. также
[план лабы](RabbitMQ_Lab_Plan_v1_pro_max.html), [разбор пути заказа](order-path-explained.md)
и [ответы на вопросы для самопроверки](self-check-answers.md)).

## Видео-введение

- [RabbitMQ in 100 Seconds](https://www.youtube.com/watch?v=NQ3fZtyXji0)
- [RabbitMQ Explained — Exchanges](https://www.youtube.com/watch?v=o8eU5WiO8fw)
- [RabbitMQ - Creating Queue, Exchange and Binding and Publishing Message](https://www.youtube.com/watch?v=OP2MjpYY5Oc)

## Модель AMQP: connection, channel, exchange (раздел 2, вопросы 1–4)

- [AMQP 0-9-1 Model Explained](https://www.rabbitmq.com/tutorials/amqp-concepts) — официальное описание модели, основа для connection/channel/frames
- [Connections](https://www.rabbitmq.com/docs/connections)
- [Channels](https://www.rabbitmq.com/docs/channels)
- [Detecting Dead TCP Connections with Heartbeats and TCP Keepalives](https://www.rabbitmq.com/docs/heartbeats) — вопрос 7 (redelivery + heartbeat)
- [pcntl_async_signals](https://www.php.net/manual/en/function.pcntl-async-signals.php) — graceful shutdown в `AbstractAmqpWorker`
- [RabbitMQ tutorial - Publish/Subscribe (Tutorial 3, PHP)](https://www.rabbitmq.com/tutorials/tutorial-three-php) — fanout, шаг 6.2
- [Tutorial 5 (PHP): Topics](https://www.rabbitmq.com/tutorials/tutorial-five-php) — topic-exchange, маски `*`/`#`
- [Temporary Queues](https://www.rabbitmq.com/docs/queues#temporary-queues)
- [README php-amqplib](https://github.com/php-amqplib/php-amqplib#readme) — библиотека, на которой написаны все воркеры

## Публикация и надёжность издателя: Outbox Pattern, Publisher Confirms (раздел 5, вопросы 13–15)

- [Transactional Outbox Pattern (microservices.io)](https://microservices.io/patterns/data/transactional-outbox.html)
- [Transactional outbox pattern explained (видео)](https://www.youtube.com/watch?v=5YLpjPmsPCA)
- [Publisher Confirms](https://www.rabbitmq.com/docs/confirms#publisher-confirms)
- [Tutorial 7 (PHP): Publisher Confirms](https://www.rabbitmq.com/tutorials/tutorial-seven-php)
- [Laravel: Database Transactions](https://laravel.com/docs/13.x/database#database-transactions) — `DB::transaction` в `OrderController`
- [Laravel: Отправка почты (Mail → Sending)](https://laravel.com/docs/13.x/mail#sending-mail)

## Надёжность потребителя: ACK/NACK/Prefetch (раздел 2.6–2.7, вопросы 5–8)

- [(Consumer) Delivery Acknowledgements](https://www.rabbitmq.com/docs/confirms#consumer-acknowledgements)
- [Automatic Requeueing](https://www.rabbitmq.com/docs/confirms#automatic-requeueing) — что происходит при обрыве канала с unacked-сообщениями
- [Negative Acknowledgement and Requeuing of Deliveries](https://www.rabbitmq.com/docs/confirms#consumer-nacks-requeue)
- [Consumer Prefetch](https://www.rabbitmq.com/docs/consumer-prefetch) — вопрос 8, `config('rabbitmq.prefetch')`

## Retry / TTL / DLX / DLQ (раздел 7, вопросы 9–11)

- [Time-to-Live and Expiration](https://www.rabbitmq.com/docs/ttl) — почему нужна отдельная очередь на каждую задержку (вопрос 9)
- [Dead Letter Exchanges](https://www.rabbitmq.com/docs/dlx)
- [Dead-Lettered Effects on Messages](https://www.rabbitmq.com/docs/dlx#effects) — что кладёт брокер в `x-death` (вопрос 11)

## Priority, Prefetch, Competing Consumers (раздел 8, вопросы 8, 16)

- [Priority Support in Queues](https://www.rabbitmq.com/docs/priority) — почему приоритет виден только при backlog

## Топология и конфигурация брокера (раздел 9, сессия 1)

- [Schema Definition Export and Import](https://www.rabbitmq.com/docs/definitions) — как `definitions.json` грузится при старте
- [Queues → Optional Arguments](https://www.rabbitmq.com/docs/queues#optional-arguments) — почему `x-*`-аргументы неизменяемы
- [Configuration → config file](https://www.rabbitmq.com/docs/configure#config-file) — формат `rabbitmq.conf`
- [Management Plugin](https://www.rabbitmq.com/docs/management) — UI на `:15672`
- [rabbitmq — официальный образ Docker](https://hub.docker.com/_/rabbitmq)
- [php — официальный образ Docker](https://hub.docker.com/_/php)
- [docker compose up](https://docs.docker.com/reference/cli/docker/compose/up/) — флаги `--scale`, `--force-recreate`

## Laravel Queue — слой для сравнения (шаг 5.4, вопрос 18)

- [Dealing With Failed Jobs](https://laravel.com/docs/13.x/queues#dealing-with-failed-jobs)
- [Specifying Max Job Attempts / Timeout Values](https://laravel.com/docs/13.x/queues#max-job-attempts-and-timeout)
- [README laravel-queue-rabbitmq](https://github.com/vyuldashev/laravel-queue-rabbitmq#readme) — драйвер `config/queue.php`
- [Laravel: Artisan Commands](https://laravel.com/docs/13.x/artisan#writing-commands) — все воркеры лабы это Artisan-команды
- [Laravel: Artisan → Tables](https://laravel.com/docs/13.x/artisan#tables) — вывод `$this->table()` в `worker:failed-email`

## Laravel: инфраструктура проекта (сессия 1, установка и модели)

- [Laravel 13: Installation](https://laravel.com/docs/13.x/installation)
- [Laravel 13: Environment Configuration](https://laravel.com/docs/13.x/configuration#environment-configuration)
- [Laravel 13: Migrations → column types](https://laravel.com/docs/13.x/migrations#available-column-types)
- [Laravel 13: Eloquent → UUID keys](https://laravel.com/docs/13.x/eloquent#uuid-and-ulid-keys) — `message_id` заказов
- [Laravel 13: Validation](https://laravel.com/docs/13.x/validation#quick-writing-the-validation-logic)

## PostgreSQL

- [Режимы блокировки на уровне строк (Row-Level Locks)](https://www.postgresql.org/docs/16/explicit-locking.html#LOCKING-ROWS) — `lockForUpdate()` при резервировании склада
- [PostgreSQL: JSON Types](https://www.postgresql.org/docs/16/datatype-json.html) — `jsonb`-колонки в `outbox_messages`/`order_events`

## Общая надёжность и наблюдаемость

- [Reliability Guide](https://www.rabbitmq.com/docs/reliability) — все механизмы надёжности брокера в одном месте
- [Monitoring](https://www.rabbitmq.com/docs/monitoring) — какие метрики смотреть (Ready/Unacked/Redelivered)

## Что дальше — после лабы (раздел 13)

- [Quorum Queues](https://www.rabbitmq.com/docs/quorum-queues) — вопрос 18, classic vs quorum
- [Streams](https://www.rabbitmq.com/docs/streams) — append-only лог с повторным чтением
- [Policies](https://www.rabbitmq.com/docs/parameters#policies) — TTL/DLX по паттерну имени вместо `x-*`-аргументов
- [Single Active Consumer](https://www.rabbitmq.com/docs/consumers#single-active-consumer)
- [Consistent Hash Exchange (плагин)](https://github.com/rabbitmq/rabbitmq-server/tree/main/deps/rabbitmq_consistent_hash_exchange)
- [Prometheus-интеграция](https://www.rabbitmq.com/docs/prometheus)
- [Clustering & Federation](https://www.rabbitmq.com/docs/clustering)
- [Delayed Message Exchange (плагин)](https://github.com/rabbitmq/rabbitmq-delayed-message-exchange) — альтернатива retry-цепочке на TTL+DLX
- [Saga / Process Manager (microservices.io)](https://microservices.io/patterns/data/saga.html)
