# Путь заказа через систему — подробный разбор с точками наблюдения

Этот документ проводит **один заказ** от `curl` до последней строки в БД,
шаг за шагом. На каждом шаге показано:

- **какой файл** и какой код за него отвечает;
- **что именно** происходит с данными (что за структура летит дальше);
- **куда вставить `dd()` / `dump()` / лог**, чтобы увидеть это своими глазами;
- **где посмотреть результат** (таблица в БД, лог контейнера, UI RabbitMQ, Mailpit).

Отдельно разобран случай **«товара не хватает на складе»**.

> Правило чтения: не пытайся понять всё сразу. Пройди один раз happy-path
> из раздела 6, вставляя точки наблюдения по очереди. Когда увидишь данные
> на каждом шаге — картинка соберётся сама.

---

## Оглавление

1. [Мысленная модель за одну минуту](#1-мысленная-модель-за-одну-минуту)
2. [Действующие лица](#2-действующие-лица)
3. [Инструменты наблюдения](#3-инструменты-наблюдения)
4. [Путь заказа: хоп за хопом](#4-путь-заказа-хоп-за-хопом)
5. [Что происходит, когда товара не хватает](#5-что-происходит-когда-товара-не-хватает)
6. [Эксперимент A: happy path (всё хорошо)](#6-эксперимент-a-happy-path)
7. [Эксперимент B: не хватило склада](#7-эксперимент-b-не-хватило-склада)
8. [Эксперимент C: падение до ack (идемпотентность)](#8-эксперимент-c-падение-до-ack)
9. [Шпаргалка: что в какой таблице должно оказаться](#9-шпаргалка)
10. [FAQ — почему так, а не проще](#10-faq)

---

## 1. Мысленная модель за одну минуту

Клиент оформляет заказ по HTTP. После этого нужно **три независимых действия**:

| Действие | Кто делает | Что трогает |
|---|---|---|
| зарезервировать товар на складе | `worker:order` | таблицу `products` |
| отправить письмо-подтверждение | `worker:email` | Mailpit (SMTP) |
| записать событие в аналитику | `worker:analytics` | таблицу `order_events` |

Если делать это всё **внутри HTTP-запроса**, то клиент ждёт, пока отработает
почта, а падение аналитики роняет весь заказ. Поэтому:

```
HTTP-запрос делает МИНИМУМ:
  1. пишет заказ в БД
  2. пишет "записку" (строку в таблице outbox_messages): "случилось событие order.created"
  и всё. В RabbitMQ он НЕ ходит.

Дальше отдельные фоновые процессы:
  - worker:outbox-relay  — берёт записки из таблицы и кладёт их в RabbitMQ
  - RabbitMQ             — разносит копию сообщения по трём очередям
  - три воркера          — каждый забирает из своей очереди и делает свою работу
```

Схема целиком:

```
  curl POST /api/orders
        │
        ▼
  ┌───────────────────────────────┐
  │ OrderController::store()       │   ОДНА транзакция БД:
  │                               │     • INSERT orders (+ order_items)
  │                               │     • INSERT outbox_messages (status=pending)
  └───────────────────────────────┘
        │ (в БД лежит строка outbox_messages)
        ▼
  ┌───────────────────────────────┐
  │ worker:outbox-relay (цикл)    │   SELECT ... WHERE status='pending'
  │                               │   publish → exchange "orders.topic"  (routing key = order.created)
  │                               │   ждёт confirm от брокера → UPDATE status='sent'
  └───────────────────────────────┘
        │
        ▼
  ┌───────────────────────────────┐
  │ RabbitMQ: exchange orders.topic│  по правилам binding (см. rabbitmq/definitions.json):
  │                               │    order.created → order.queue
  │        ▼      ▼      ▼         │    order.created → email.queue
  │   order.q  email.q  analytics.q│    order.*       → analytics.queue
  └───────────────────────────────┘
        │         │         │
        ▼         ▼         ▼
   worker:order  worker:email  worker:analytics
   резерв склада  письмо        строка в order_events
        │
        │ пишет НОВУЮ записку order.reserved в outbox_messages
        ▼
   (relay снова подхватит её → orders.topic → order.* → только analytics.queue)
```

---

## 2. Действующие лица

### 2.1. Таблицы БД (`laravel-app/database/migrations/2026_09_08_113007_create_lab_tables.php`)

| Таблица | Зачем | Ключевые поля |
|---|---|---|
| `products` | товары на складе | `id`, `name`, `stock` |
| `orders` | заказы | `id`, `user_id`, `status` (`pending`→`reserved`→…), `priority` |
| `order_items` | позиции заказа | `order_id`, `product_id`, `quantity` |
| `outbox_messages` | **«стопка записок»** — события, которые надо опубликовать | `id` (UUID, станет `message_id` в RabbitMQ), `routing_key`, `payload` (jsonb), `status` (`pending`/`sent`), `published_at` |
| `processed_messages` | **«что я уже обработал»** — защита от повторной обработки | `message_id`, `consumer`, уникальный индекс `(message_id, consumer)` |
| `order_events` | журнал аналитики | `order_id`, `event_type`, `payload`, `occurred_at` |

### 2.2. Процессы (контейнеры в `docker-compose.yml`)

| Контейнер | Команда | Роль |
|---|---|---|
| `app` | `php artisan serve` | принимает HTTP на `localhost:8000` |
| `outbox-relay` | `php artisan worker:outbox-relay` | таблица `outbox_messages` → RabbitMQ |
| `order-worker` | `php artisan worker:order` | резерв склада |
| `email-worker` | `php artisan worker:email` | письма |
| `analytics-worker` | `php artisan worker:analytics` | `order_events` |
| `postgres`, `rabbitmq`, `mailpit` | — | инфраструктура |

### 2.3. Объекты RabbitMQ (`rabbitmq/definitions.json`, загружаются при старте брокера)

- **exchange** `orders.topic` (тип `topic`) — «сортировочный узел». Сам сообщения не хранит.
- **очереди** `order.queue`, `email.queue`, `analytics.queue` — здесь сообщения лежат и ждут воркера.
- **binding** — правило «из exchange X в очередь Y попадают сообщения с routing key по маске Z»:

  | Из exchange | В очередь | Маска routing key | Смысл |
  |---|---|---|---|
  | `orders.topic` | `order.queue` | `order.created` | точное совпадение |
  | `orders.topic` | `email.queue` | `order.created` | точное совпадение |
  | `orders.topic` | `analytics.queue` | `order.*` | любое `order.<одно-слово>` |

  Поэтому событие `order.created` попадает во **все три** очереди, а `order.reserved` — **только** в `analytics.queue` (совпадает с `order.*`, но не с `order.created`).

---

## 3. Инструменты наблюдения

Держи их под рукой — они понадобятся на каждом шаге.

### Логи контейнера
```bash
docker compose logs -f outbox-relay
docker compose logs -f order-worker email-worker analytics-worker
```

### Заглянуть в БД (psql)
```bash
docker compose exec postgres psql -U lab -d lab -c "SELECT id, name, stock FROM products;"
docker compose exec postgres psql -U lab -d lab -c "SELECT id, status, priority FROM orders ORDER BY id DESC LIMIT 5;"
docker compose exec postgres psql -U lab -d lab -c "SELECT id, status, routing_key, payload FROM outbox_messages ORDER BY created_at DESC LIMIT 5;"
docker compose exec postgres psql -U lab -d lab -c "SELECT message_id, consumer, processed_at FROM processed_messages ORDER BY processed_at DESC LIMIT 10;"
docker compose exec postgres psql -U lab -d lab -c "SELECT id, order_id, event_type, occurred_at FROM order_events ORDER BY id DESC LIMIT 10;"
```

### Заглянуть в БД (tinker — если удобнее PHP)
```bash
docker compose exec app php artisan tinker --execute="dump(App\Models\OutboxMessage::latest()->first()->toArray());"
```

### RabbitMQ Management UI
Браузер → **http://localhost:15672** , логин / пароль `lab` / `lab`.
Вкладка **Queues and Streams**: колонки **Ready** (лежит, ждёт воркера) и **Unacked**
(отдано воркеру, ещё не подтверждено). Клик по очереди → **Get messages** —
подсмотреть тело сообщения, не удаляя его (поставь *Requeue: Yes*).

### Mailpit (входящие письма)
Браузер → **http://localhost:8025** .

---

## 4. Путь заказа: хоп за хопом

Дальше — по одному хопу. Формат: **что происходит → код → точка наблюдения**.

Запрос, который будем слать (Monitor — это `product_id = 3`, `stock = 5`):

```bash
curl -s -X POST http://localhost:8000/api/orders \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{"user_id": 1, "priority": 0, "items": [{"product_id": 3, "quantity": 3}]}'
```

---

### Хоп 1. HTTP приходит в контроллер, валидация

**Файл:** `laravel-app/app/Http/Controllers/OrderController.php`, метод `store()`.

```php
$data = $request->validate([
    'user_id'            => 'required|integer',
    'priority'           => 'sometimes|integer|min:0|max:10',
    'items'              => 'required|array|min:1',
    'items.*.product_id' => 'required|integer|exists:products,id',  // ← товар обязан существовать
    'items.*.quantity'   => 'required|integer|min:1',
]);
```

`$data` — это очищенный массив ровно из тех полей, что описаны в правилах.
Если `product_id` нет в таблице `products` — сюда мы даже не дойдём, вернётся `422`.

> ⚠️ На этом шаге **склад ещё не проверяется**. Правило `exists:products,id` проверяет
> только что «товар с таким id есть», а не «его достаточно». Проверка количества —
> позже, в `worker:order` (Хоп 7a).

**👀 Точка наблюдения 1 — что пришло в контроллер.**
Вставь сразу после `$request->validate([...]);`:

```php
dd('ХОП 1 — данные после валидации', $data);
```

Пошли `curl` — в его ответе увидишь дамп `$data`:
```
["user_id" => 1, "priority" => 0, "items" => [["product_id" => 3, "quantity" => 3]]]
```
Убери `dd()`, идём дальше.

---

### Хоп 2. Одна транзакция БД: заказ + позиции

**Тот же файл**, тело `DB::transaction(function () { ... })`:

```php
$order = Order::create([
    'user_id'  => $data['user_id'],
    'priority' => $data['priority'] ?? 0,
]);                                   // INSERT INTO orders (...) — status по умолчанию 'pending'
$order->items()->createMany($data['items']);   // INSERT INTO order_items (...) — по строке на позицию
```

Всё внутри `DB::transaction` — значит либо **и заказ, и позиции, и запись в outbox**
сохранятся вместе, либо (при ошибке) **не сохранится ничего** (ROLLBACK).

**👀 Точка наблюдения 2 — заказ создан.**
Вставь перед `return $order;` (внутри замыкания):

```php
dd('ХОП 2 — заказ в БД', $order->toArray(), $order->items()->get()->toArray());
```

Или без остановки — посмотри в БД сразу после запроса:
```bash
docker compose exec postgres psql -U lab -d lab -c "SELECT * FROM orders ORDER BY id DESC LIMIT 1;"
docker compose exec postgres psql -U lab -d lab -c "SELECT * FROM order_items ORDER BY id DESC LIMIT 3;"
```
Ожидаешь: одна строка в `orders` со `status = pending`, одна в `order_items`.

---

### Хоп 3. Пишем «записку» в outbox

**Файл:** `laravel-app/app/Services/OutboxWriter.php`, метод `write()`.
Вызывается из контроллера так:

```php
$outbox->write('order.created', [
    'order_id' => $order->id,
    'user_id'  => $order->user_id,
    'items'    => $data['items'],
], $order->priority);
```

Внутри `write()`:

```php
return OutboxMessage::query()->create([
    'routing_key' => $routingKey,                       // 'order.created'
    'payload'     => $payload + ['event' => $routingKey, 'occurred_at' => now()->toIso8601String()],
    'priority'    => $priority,
    'status'      => 'pending',                         // ← relay ищет именно такие
]);
```

То есть в таблицу `outbox_messages` ложится **одна строка**:

```
id           = 01a08158-...   (UUID, сгенерирован моделью; станет message_id в RabbitMQ)
routing_key  = order.created
payload      = {"order_id":42,"user_id":1,"items":[{"product_id":3,"quantity":3}],
                "event":"order.created","occurred_at":"2026-09-10T18:03:11+00:00"}
priority     = 0
status       = pending
published_at = NULL
```

RabbitMQ на этом шаге **ещё ничего не знает** о заказе. Есть только строка в БД.

**👀 Точка наблюдения 3 — что записали в outbox.**
В начало `write()` добавь **лог** (не `dd()` — этот метод дальше будет
вызываться внутри воркера, где `dd()` убьёт процесс):

```php
logger()->info('ХОП 3 — OUTBOX WRITE', compact('routingKey', 'payload', 'priority'));
```
Смотреть: `docker compose exec app tail -f storage/logs/laravel.log`

Или прямо в БД:
```bash
docker compose exec postgres psql -U lab -d lab -c \
  "SELECT id, status, routing_key, payload FROM outbox_messages ORDER BY created_at DESC LIMIT 1;"
```

На этом HTTP-запрос **закончился**: контроллер вернул `201` с телом заказа.
Клиент свободен. Дальнейшее происходит в фоне.

---

### Хоп 4. Relay забирает записку и публикует в RabbitMQ

**Файл:** `laravel-app/app/Console/Commands/Workers/OutboxRelayCommand.php`.
Это бесконечный цикл в отдельном контейнере `outbox-relay`.

```php
$ch->confirm_select();   // включаем publisher confirms: брокер будет подтверждать каждое сообщение

while (true) {
    $batch = OutboxMessage::where('status', 'pending')->orderBy('created_at')->limit(50)->get();

    foreach ($batch as $row) {
        $msg = new AMQPMessage(json_encode($row->payload), [
            'content_type'        => 'application/json',
            'delivery_mode'       => AMQPMessage::DELIVERY_MODE_PERSISTENT,  // 2 = «хранить на диске»
            'message_id'          => $row->id,          // ← тот самый UUID из БД
            'priority'            => $row->priority,
            'timestamp'           => time(),
            'application_headers' => new AMQPTable(['x-retry-count' => 0]),
        ]);
        $ch->basic_publish($msg, 'orders.topic', $row->routing_key);   // ← отправка в exchange
        $ch->wait_for_pending_acks(timeout: 5);        // ← ЖДЁМ, пока брокер подтвердит приём
        $row->update(['status' => 'sent', 'published_at' => now()]);   // только теперь помечаем sent
    }

    if ($batch->isEmpty()) { sleep((int) $this->option('sleep')); }
}
```

Порядок важен: **сначала** дождались подтверждения от брокера, **потом** пометили строку
`sent`. Если контейнер умрёт между `basic_publish` и `update` — строка останется
`pending`, и после перезапуска сообщение опубликуется **ещё раз с тем же `message_id`**.
Дубль потом погасит воркер (Хоп 6, проверка `processed_messages`).

**👀 Точка наблюдения 4 — что публикует relay.**
Добавь перед `$ch->basic_publish(...)`:

```php
$this->info('ХОП 4 — RELAY publish rk=' . $row->routing_key
    . ' id=' . $row->id
    . ' body=' . json_encode($row->payload, JSON_UNESCAPED_UNICODE));
```

Смотреть: `docker compose logs -f outbox-relay`. Там уже есть строка `→ order.created <id>` —
она печатается штатно после успешной публикации (`$this->info("→ ...")` в конце цикла).

**Второй взгляд — в БД:** та же строка `outbox_messages` теперь `status = sent`,
`published_at` заполнен:
```bash
docker compose exec postgres psql -U lab -d lab -c \
  "SELECT id, status, published_at FROM outbox_messages ORDER BY created_at DESC LIMIT 1;"
```

---

### Хоп 5. RabbitMQ разносит сообщение по очередям

Тут кода нет — работает конфигурация из `rabbitmq/definitions.json`.

`basic_publish($msg, 'orders.topic', 'order.created')` означает:
«отдать сообщение в exchange `orders.topic` с меткой `order.created`».

Exchange смотрит на свои **binding'и** и копирует сообщение в каждую подходящую очередь:

| binding | подходит для `order.created`? | итог |
|---|---|---|
| `orders.topic` → `order.queue`, key `order.created` | да (точное совпадение) | копия в `order.queue` |
| `orders.topic` → `email.queue`, key `order.created` | да | копия в `email.queue` |
| `orders.topic` → `analytics.queue`, key `order.*` | да (`order.` + одно слово) | копия в `analytics.queue` |

Итого **три независимые копии** сообщения, по одной в каждой очереди.
Дальше каждая живёт своей жизнью: если `order-worker` упадёт, копии в `email.queue`
и `analytics.queue` это никак не заденет.

**👀 Точка наблюдения 5 — увидеть сообщения в очередях.**
Проще всего «поймать» их так: **останови воркеры**, отправь заказ, посмотри очереди.

```bash
docker compose stop order-worker email-worker analytics-worker
# отправь curl из раздела 4
```
Открой http://localhost:15672 → **Queues**. У `order.queue`, `email.queue`,
`analytics.queue` в колонке **Ready** будет по `1`. Кликни очередь → **Get messages**
(*Requeue: Yes*) — увидишь тело (тот же JSON payload) и свойства (`message_id`, `priority`).

Потом:
```bash
docker compose start order-worker email-worker analytics-worker
```
— и **Ready** снова упадёт в `0` (воркеры разобрали).

---

### Хоп 6. Воркер получает сообщение (общая механика)

**Файл:** `laravel-app/app/Console/Commands/Workers/AbstractAmqpWorker.php`.
От него наследуются все три воркера. Здесь — вся «обвязка», кроме бизнес-логики.

Метод `handle()` подключается к брокеру и подписывается на очередь:

```php
$this->channel->basic_qos(0, config('rabbitmq.prefetch'), false);   // prefetch: сколько неподтверждённых сообщений держать
$this->channel->basic_consume(
    queue: $this->queue(),                       // 'order.queue' / 'email.queue' / 'analytics.queue'
    consumer_tag: $this->consumerName() . '-' . getmypid(),
    no_ack: false,                               // ← подтверждать будем вручную
    callback: fn (AMQPMessage $m) => $this->handleMessage($m),
);

while ($this->channel->is_consuming() && !$this->stopping) {
    try { $this->channel->wait(timeout: 1); }   // ждём сообщение, но не дольше 1 сек — чтобы успевать реагировать на SIGTERM
    catch (AMQPTimeoutException) { }
}
```

Когда сообщение пришло — вызывается `handleMessage()`:

```php
$id      = $msg->get('message_id');             // UUID из БД
$payload = json_decode($msg->getBody(), true);  // тот самый массив с order_id/user_id/items/event/occurred_at

// 1) УЖЕ обрабатывали это сообщение этим воркером? → просто ack, ничего не делаем
if (ProcessedMessage::query()->where('message_id', $id)->where('consumer', $this->consumerName())->exists()) {
    $msg->ack();
    return;
}

// 2) бизнес-логика + отметка «обработано» — В ОДНОЙ ТРАНЗАКЦИИ
try {
    DB::transaction(function () use ($payload, $msg, $id) {
        $this->process($payload, $msg);                       // ← абстрактный метод, свой у каждого воркера
        ProcessedMessage::query()->create([
            'message_id' => $id, 'consumer' => $this->consumerName(), 'processed_at' => now(),
        ]);
    });
} catch (\Throwable $e) {
    $this->onFailure($msg, $e);                               // → nack(requeue: false)
    return;
}

$msg->ack();                                                  // 3) подтверждаем: брокер удалит копию из очереди
```

Три исхода:

| Что случилось | Транзакция | Сообщение в RabbitMQ |
|---|---|---|
| `process()` отработал | COMMIT (эффект + строка в `processed_messages`) | `ack` → брокер удаляет копию из очереди |
| `process()` бросил исключение | ROLLBACK (не осталось ничего) | `nack(requeue: false)` → см. ниже |
| дубликат (строка в `processed_messages` уже есть) | — | `ack` без обработки |

`nack(requeue: false)` = «не могу обработать, обратно в очередь не возвращай».
Куда денется сообщение — зависит от очереди:
- у `order.queue` и `analytics.queue` в `definitions.json` **нет** `x-dead-letter-exchange` → сообщение **просто исчезает**;
- у `email.queue` DLX появится на шаге 5.x — тогда nack отправит письмо в retry-цепочку.

**👀 Точка наблюдения 6 — что получил воркер.**
В `handleMessage()` после `$payload = json_decode(...)` добавь:

```php
$this->info('ХОП 6 — ' . $this->consumerName() . ' получил id=' . $id
    . ' payload=' . json_encode($payload, JSON_UNESCAPED_UNICODE));
```
Штатно там уже печатается `← <id>` и в конце `✔ ack` либо `✖ ... → nack`.
Смотреть: `docker compose logs -f order-worker email-worker analytics-worker`.

---

### Хоп 7a. `worker:order` — резервирование склада

**Файл:** `laravel-app/app/Console/Commands/Workers/OrderWorkerCommand.php`, метод `process()`.

```php
$items = collect($p['items'])->sortBy('product_id');   // всегда один порядок захвата блокировок → нет deadlock
foreach ($items as $item) {
    $product = Product::query()->lockForUpdate()->findOrFail($item['product_id']);
    //         SELECT * FROM products WHERE id = ? FOR UPDATE
    //         ↑ строка блокируется до конца транзакции: параллельный order-worker будет ЖДАТЬ здесь,
    //           а не читать устаревший stock

    if ($product->stock < $item['quantity']) {
        throw new \DomainException("not enough stock for product {$product->id}");
        //  ↑ вылетает из process() → ловится в AbstractAmqpWorker → ROLLBACK → nack
    }

    $product->decrement('stock', $item['quantity']);
    //  UPDATE products SET stock = stock - ? WHERE id = ?   (именно этой строки; + обновляет $product->stock в памяти)
}

Order::query()->whereKey($p['order_id'])->update(['status' => 'reserved']);

app(OutboxWriter::class)->write('order.reserved', ['order_id' => $p['order_id']]);
//  ↑ НОВАЯ строка в outbox_messages — в той же транзакции, что и списание склада
```

Всё это выполняется **внутри `DB::transaction()`** из `AbstractAmqpWorker` (Хоп 6).
Значит: списание остатков, смена статуса заказа, новая запись в outbox и отметка
в `processed_messages` — атомарны. Откат — откатывает всё разом.

**👀 Точка наблюдения 7a — склад до/после.**
В `process()` внутри `foreach`:

```php
$this->info("ХОП 7a — product {$product->id}: stock={$product->stock}, need={$item['quantity']}");
```
И проверка в БД:
```bash
docker compose exec postgres psql -U lab -d lab -c "SELECT id, name, stock FROM products;"
docker compose exec postgres psql -U lab -d lab -c "SELECT id, status FROM orders ORDER BY id DESC LIMIT 1;"
```
После успешного заказа Monitor×3: `stock` Monitor = `5 → 2`, заказ `status = reserved`.

---

### Хоп 7b. `worker:email` — письмо

**Файл:** `EmailWorkerCommand.php`.

```php
if (config('lab.simulate_smtp_failure')) {          // хук для шага 5.x — «сломать почту»
    throw new \RuntimeException('SMTP server unavailable');
}
Mail::raw("Ваш заказ №{$p['order_id']} создан",
    fn ($m) => $m->to("user{$p['user_id']}@lab.test")->subject("Заказ №{$p['order_id']}"));
```

**👀 Точка наблюдения 7b:** открой http://localhost:8025 — там появится письмо
`Заказ №42` для `user1@lab.test`.

---

### Хоп 7c. `worker:analytics` — строка в журнал

**Файл:** `AnalyticsWorkerCommand.php`.

```php
OrderEvent::query()->create([
    'order_id'    => $p['order_id'],
    'event_type'  => $p['event'],          // 'order.created' (пришло в payload на Хопе 3)
    'payload'     => $p,
    'occurred_at' => $p['occurred_at'],
]);
```

**👀 Точка наблюдения 7c:**
```bash
docker compose exec postgres psql -U lab -d lab -c \
  "SELECT id, order_id, event_type, occurred_at FROM order_events ORDER BY id DESC LIMIT 5;"
```

---

### Хоп 8. Второй круг: событие `order.reserved`

На Хопе 7a `worker:order` записал в `outbox_messages` новую строку:
`routing_key = order.reserved`, `payload = {"order_id":42,"event":"order.reserved","occurred_at":"..."}`.

Дальше — тот же путь, что и у `order.created`:

1. `outbox-relay` видит `status='pending'` → публикует в `orders.topic` с key `order.reserved`;
2. exchange проверяет binding'и: `order.reserved` совпадает с `order.*` (`analytics.queue`),
   но **не** совпадает с `order.created` (`order.queue`, `email.queue`);
3. → сообщение уходит **только в `analytics.queue`**;
4. `worker:analytics` пишет вторую строку в `order_events` (`event_type = order.reserved`).

**Итог по одному заказу:** в `order_events` — **две** строки: `order.created` и `order.reserved`.
Это и есть смысл topic-exchange: одно событие, разные подписчики, маска решает, кому что.

---

## 5. Что происходит, когда товара не хватает

Запрос: Monitor (`stock` уже `2` после эксперимента A) заказываем в количестве `3`.

```bash
curl -s -X POST http://localhost:8000/api/orders \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"user_id": 1, "items": [{"product_id": 3, "quantity": 3}]}'
```

Пошагово:

1. **Контроллер (Хопы 1–3): всё как обычно.** Валидация проходит (`product_id=3` существует),
   заказ создаётся со `status='pending'`, в `outbox_messages` ложится `order.created`.
   **HTTP-ответ `201`.** На этом уровне «не хватает склада» ещё никто не знает.

2. **Relay (Хоп 4):** публикует `order.created` в `orders.topic`. Строка → `status='sent'`.

3. **RabbitMQ (Хоп 5):** три копии — в `order.queue`, `email.queue`, `analytics.queue`.

4. **`worker:email` и `worker:analytics`:** свои копии обрабатывают **успешно** —
   письмо уходит в Mailpit, в `order_events` появляется строка `order.created`.
   Нехватка склада их вообще не касается.

5. **`worker:order` (Хоп 7a):** заходит в `DB::transaction`, в цикле:
   ```php
   $product = Product::query()->lockForUpdate()->findOrFail(3);   // stock = 2
   if (2 < 3) {
       throw new \DomainException("not enough stock for product 3");
   }
   ```
   Исключение вылетает из `process()`.

6. **`AbstractAmqpWorker` ловит его** (`catch (\Throwable $e)`):
   - `DB::transaction` делает **ROLLBACK** — отменяется **всё**, что успело произойти
     в транзакции: если бы в заказе было несколько товаров и первый уже списался —
     его списание тоже откатывается. Строка в `processed_messages` **не создаётся**.
     Статус заказа **остаётся `pending`**.
   - вызывается `onFailure()` → `$msg->nack(requeue: false)`.

7. **RabbitMQ:** у `order.queue` нет DLX → сообщение из `order.queue` **исчезает**.
   (Копии в `email.queue` / `analytics.queue` уже обработаны на шаге 4 и к этому моменту их нет.)

8. **`order.reserved` не публикуется** — строка `app(OutboxWriter::class)->write('order.reserved', ...)`
   находится после `throw` и просто не выполняется.

**Что видно в итоге:**

| Место | Состояние |
|---|---|
| `orders` | новая строка, `status = pending` (не `reserved`!) |
| `products` | `stock` Monitor не изменился (остался `2`) |
| `processed_messages` | есть строки для `email-worker` и `analytics-worker`, **нет** для `order-worker` |
| `order_events` | одна новая строка `order.created`, **нет** `order.reserved` |
| Mailpit | письмо всё равно пришло |
| лог `order-worker` | `✖ not enough stock for product 3 → nack(requeue=false)` |

**👀 Точка наблюдения:**
```bash
docker compose logs --tail=20 order-worker
docker compose exec postgres psql -U lab -d lab -c "SELECT id, status FROM orders ORDER BY id DESC LIMIT 3;"
docker compose exec postgres psql -U lab -d lab -c "SELECT id, name, stock FROM products;"
```

> Это **намеренно грубое** поведение для учебных целей: заказ «завис» в `pending`,
> сообщение потеряно. В реальной системе тут была бы или компенсация (событие
> `order.rejected` + письмо клиенту), или DLQ для разбора вручную. Обрати внимание,
> насколько по-разному ведут себя три ветки одного события — это и есть суть
> «слабо связанных» подписчиков.

---

## 6. Эксперимент A: happy path

### Подготовка (один раз, с чистого листа)

```bash
docker compose up -d postgres rabbitmq mailpit app
docker compose exec app php artisan migrate:fresh
docker compose exec app php artisan tinker --execute="App\Models\Product::insert([['name'=>'Keyboard','stock'=>100],['name'=>'Mouse','stock'=>100],['name'=>'Monitor','stock'=>5]]);"
docker compose exec postgres psql -U lab -d lab -c "SELECT id, name, stock FROM products;"
```
Ожидаешь: `1 Keyboard 100`, `2 Mouse 100`, `3 Monitor 5`.

### Запуск фоновых процессов

```bash
docker compose up -d outbox-relay order-worker email-worker analytics-worker
docker compose logs -f outbox-relay order-worker email-worker analytics-worker
```
Оставь этот терминал открытым — сюда пойдут логи. Открой **второй** терминал для `curl`.

### Отправка заказа (второй терминал)

```bash
curl -s -X POST http://localhost:8000/api/orders \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"user_id": 1, "priority": 0, "items": [{"product_id": 3, "quantity": 3}]}' | jq
```

### Что должно произойти (в логах, за 1–2 секунды)

```
outbox-relay-1     | → order.created 01a08158-....
order-worker-1     | ← 01a08158-....
order-worker-1     |    ✔ ack
email-worker-1     | ← 01a08158-....
email-worker-1     |    ✔ ack
analytics-worker-1 | ← 01a08158-....
analytics-worker-1 |    ✔ ack
outbox-relay-1     | → order.reserved 7c1f9a02-....      ← второй круг (Хоп 8)
analytics-worker-1 | ← 7c1f9a02-....
analytics-worker-1 |    ✔ ack
```

### Проверка результата

```bash
docker compose exec postgres psql -U lab -d lab -c "SELECT id, name, stock FROM products;"
#   Monitor stock = 2  (5 - 3)

docker compose exec postgres psql -U lab -d lab -c "SELECT id, status FROM orders ORDER BY id DESC LIMIT 1;"
#   status = reserved

docker compose exec postgres psql -U lab -d lab -c "SELECT status, routing_key FROM outbox_messages ORDER BY created_at DESC LIMIT 2;"
#   обе строки status = sent: order.reserved и order.created

docker compose exec postgres psql -U lab -d lab -c "SELECT consumer, message_id FROM processed_messages ORDER BY processed_at DESC LIMIT 5;"
#   order-worker, email-worker, analytics-worker для id заказа + analytics-worker для order.reserved

docker compose exec postgres psql -U lab -d lab -c "SELECT order_id, event_type FROM order_events ORDER BY id DESC LIMIT 5;"
#   две строки: order.reserved и order.created
```

Открой http://localhost:8025 — письмо `Заказ №<id>`.

---

## 7. Эксперимент B: не хватило склада

Продолжай сразу после эксперимента A (Monitor `stock = 2`).

```bash
curl -s -X POST http://localhost:8000/api/orders \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"user_id": 1, "items": [{"product_id": 3, "quantity": 3}]}' | jq
#   HTTP 201 — заказ создан, ответ успешный!
```

В логах:
```
outbox-relay-1     | → order.created <новый-id>
email-worker-1     |    ✔ ack
analytics-worker-1 |    ✔ ack
order-worker-1     |    ✖ not enough stock for product 3 → nack(requeue=false)
```

Проверка (см. таблицу в разделе 5): заказ `pending`, `stock` Monitor всё ещё `2`,
`order.reserved` не появился, письмо — пришло.

> Мораль: **HTTP-ответ `201` не значит «заказ выполнен»**. Он значит «заказ принят
> в обработку». Успех/неуспех резервирования — асинхронный, его надо смотреть по
> статусу заказа или по событиям.

---

## 8. Эксперимент C: падение до ack

Проверяет **идемпотентность** — что повторная доставка не портит данные.

```bash
# включаем «упасть сразу после COMMIT, но до ack»
docker compose stop order-worker
LAB_CRASH_BEFORE_ACK=true docker compose up -d order-worker
docker compose logs -f order-worker
```

Механика (`AbstractAmqpWorker::handleMessage`):
```php
DB::transaction(function () { ... });          // COMMIT прошёл: склад списан, processed_messages записан
if (config('lab.crash_before_ack')) {
    $this->error('CRASH before ack'); posix_kill(getmypid(), SIGKILL);   // ← процесс убит ДО $msg->ack()
}
$msg->ack();                                   // не выполнится
```

Что происходит:
1. Воркер обработал сообщение (COMMIT), но `ack` не отправил и умер.
2. RabbitMQ видит: канал закрылся, сообщение было **unacked** → возвращает его в очередь,
   помечает `redelivered = true`.
3. Docker перезапускает контейнер (`restart: unless-stopped`).
4. Воркер получает то же сообщение снова, в логе `← <id> (REDELIVERED)`.
5. Проверка `ProcessedMessage::query()->where(...)->exists()` → **строка уже есть** →
   `ack` без повторной обработки. Склад второй раз **не** списывается.

Проверь, что `stock` уменьшился ровно на `quantity`, а не вдвое.

Не забудь выключить хук:
```bash
docker compose stop order-worker && docker compose up -d order-worker
```

---

## 9. Шпаргалка

После **одного** успешного заказа `{"items":[{"product_id":3,"quantity":3}]}`:

| Таблица / место | Ожидаемое состояние |
|---|---|
| `orders` | +1 строка, `status = reserved` |
| `order_items` | +1 строка (`product_id=3, quantity=3`) |
| `products` | Monitor `stock`: было `5` → стало `2` |
| `outbox_messages` | +2 строки (`order.created`, `order.reserved`), обе `status = sent`, `published_at` заполнен |
| `processed_messages` | +4 строки: `(order.created id → order-worker / email-worker / analytics-worker)` + `(order.reserved id → analytics-worker)` |
| `order_events` | +2 строки: `order.created`, `order.reserved` |
| Mailpit (`:8025`) | +1 письмо |
| RabbitMQ (`:15672`) | все очереди снова `Ready = 0`, `Unacked = 0` |

Если чего-то не хватает — ищи, на каком хопе оборвалось (логи соответствующего контейнера).

---

## 10. FAQ — почему так, а не проще

**Зачем таблица `outbox_messages`? Почему контроллер не публикует в RabbitMQ напрямую?**
Потому что тогда возможны два «полусостояния»: (а) заказ записан в БД, но публикация
в RabbitMQ упала → событие потеряно; (б) событие опубликовано, но транзакция БД
откатилась → событие о несуществующем заказе. Запись в `outbox_messages` идёт
**в той же транзакции**, что и сам заказ: либо есть и заказ, и запись о событии,
либо нет ничего. Публикацию берёт на себя relay — отдельно и с повторами.
Это паттерн **Transactional Outbox**.

**Зачем relay — отдельный процесс?**
Чтобы публикация не зависела от жизненного цикла HTTP-запроса. Запрос завершился
за 20 мс, а relay спокойно, с подтверждениями от брокера и ретраями, доставляет
события в своём темпе. Плюс: единственное место в системе, которое реально
подключается к RabbitMQ для публикации.

**Что такое `ack` и зачем он вручную (`no_ack: false`)?**
`ack` — «я это сообщение обработал, можешь удалять». Пока воркер не прислал `ack`,
брокер держит сообщение как *unacked* и, если воркер отвалится, вернёт его в очередь
другому. Ручной `ack` (после успешной транзакции) даёт гарантию **at-least-once**:
сообщение не пропадёт, но может прийти повторно — отсюда `processed_messages`.

**Зачем `processed_messages` и уникальный индекс `(message_id, consumer)`?**
Раз доставка «хотя бы один раз», одно и то же сообщение может прийти воркеру дважды
(перезапуск, потеря `ack`, повторная публикация relay). Перед обработкой воркер
проверяет: «а не делал ли я уже это?». Отметку он пишет **в той же транзакции**, что
и эффект — поэтому «эффект есть, а отметки нет» невозможно. Это **идемпотентный
consumer**. `consumer` в ключе — потому что одно событие законно обрабатывают
три разных воркера, у каждого своя отметка.

**Чем topic-exchange отличается от fanout?**
`fanout` шлёт копию **во все** привязанные очереди, метку игнорирует.
`topic` смотрит на routing key и маску в binding (`order.created`, `order.*`, `order.#`)
и шлёт только в подходящие. Здесь это позволяет `order.created` доставлять в три
очереди, а `order.reserved` — только в аналитику, **не меняя код** — только конфигурацию
binding'ов в `definitions.json`.

**Почему `lockForUpdate()` при резервировании?**
`SELECT ... FOR UPDATE` блокирует строку товара до конца транзакции. Если два
`order-worker` одновременно резервируют один товар, второй **ждёт** на этой строке,
а не читает устаревший `stock`. Без блокировки оба прочитали бы `stock=5`, оба
списали бы по 3, и `stock` стал бы `-1`. Сортировка `items` по `product_id` — чтобы
все воркеры захватывали блокировки в одном порядке и не поймали deadlock.

**Почему письмо отправляется внутри `DB::transaction`?**
Строго говоря — спорно (внешний вызов внутри транзакции держит соединение с БД, а при
откате письмо уже ушло). Для лабы допустимо. В проде правильнее: отметку в транзакции,
отправку — после `COMMIT`, приняв, что редкий дубль письма — это нормально. Мысль,
которую лаба доносит: **exactly-once с внешним миром недостижим**, надо выбирать,
на чьей стороне возможен дубль.
