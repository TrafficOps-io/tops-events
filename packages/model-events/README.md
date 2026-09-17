# Model Events

Модуль `model-events` пакета `trafficops/tops-events` для PHP 8.4 и Laravel 12. Записывает **прикладные** входящие и исходящие события Eloquent-моделей только по явному вызову разработчика. Никаких observers жизненного цикла модели, готовых HTTP endpoints, маршрутизаторов, шаблонизаторов или транспортов.

## Установка

Установите пакет в приложении:

```sh
composer require trafficops/tops-events
```

Затем выполните `php artisan migrate`. Провайдер обнаруживается автоматически. Все три модуля событий публикуются одним пакетом в Packagist.

```sh
php artisan vendor:publish --tag=model-events-config
```

Миграции по умолчанию загружаются из пакета. Если нужны собственные миграции, опубликуйте их через `--tag=model-events-migrations` и установите `model-events.migrations = false`, чтобы не загружать обе копии.

## Запись событий

Трейты независимы, можно подключить любой один или оба:

```php
use TrafficOps\ModelEvents\Traits\IncomingEvents;
use TrafficOps\ModelEvents\Traits\OutgoingEvents;

class Project extends \Illuminate\Database\Eloquent\Model
{
    use IncomingEvents, OutgoingEvents;
}
```

Владелец должен быть сохранён. Поддерживаются integer, UUID, ULID и строковые ключи, а также Laravel morph map. Удаление владельца не удаляет журнал.

```php
use TrafficOps\ModelEvents\DTO\IncomingEventData;
use TrafficOps\ModelEvents\DTO\OutgoingEventData;
use TrafficOps\ModelEvents\DTO\Payload;
use TrafficOps\ModelEvents\Enums\IncomingEventStatus;

$incoming = $project->logIncomingEvent(new IncomingEventData(
    name: 'order.received',
    payload: Payload::json(['order_id' => 42]),
    status: IncomingEventStatus::Ok,
    metadata: ['source' => 'partner'],
    receivedAt: now(),
));

$outgoing = $project->logOutgoingEvent(new OutgoingEventData(
    name: 'order.notification',
    payload: Payload::json(['order_id' => 42]),
    destination: 'telegram:chat-123',
    incoming: $incoming,
    metadata: ['route_id' => 7],
));
```

`incoming` необязателен. Один источник может иметь много outgoing, но источник и outgoing должны принадлежать одному владельцу. Запись outgoing сама по себе не отправляет его.

### Валидация incoming в приложении

`IncomingEventStatus` обязателен, значения по умолчанию нет:

| Enum | Хранимое значение | Смысл |
| --- | --- | --- |
| `Ok` | `ok` | Данные приняты, необходимые проверки пройдены |
| `ValidationFailed` | `validation_failed` | Данные не прошли проверку |
| `Error` | `error` | Ошибка при приёме или обработке |

Пакет не валидирует payload и не выполняет пользовательские правила. Приложение может формировать правила самостоятельно, в том числе переводить пользовательские настройки `required` / `type` в Laravel rules:

```php
$payload = ['quantity' => 'not a number'];
$validator = \Illuminate\Support\Facades\Validator::make($payload, [
    'order_id' => ['required', 'integer'],
    'quantity' => ['required', 'integer'],
]);
$invalid = $validator->fails();

$incoming = $project->logIncomingEvent(new IncomingEventData(
    name: 'order.received',
    payload: Payload::json($payload), // Именно исходные данные, не validated().
    status: $invalid ? IncomingEventStatus::ValidationFailed : IncomingEventStatus::Ok,
    details: $invalid ? Payload::json($validator->errors()->toArray()) : null,
));
```

Для `Error` в `details` можно передать текст или собственную структуру ошибки. Детали необязательны при любом статусе. Статус отражает результат на момент записи; отдельного процесса отложенной валидации нет. Любой статус допускает исходящие события — например, уведомление об ошибке валидации.

Если приложение принимает HTTP, оно само выбирает представление запроса: например, `Payload::json(['body' => $body, 'query' => $query])`. Пакет не объединяет поля, не проверяет HTTP method и не требует HTTP-структуры.

## Форматы данных

`Payload::json($value)` использует JSON с исключениями при ошибках; `Payload::text($string)` сохраняет строку без изменений. Автоопределения формата нет. JSON null отличается от отсутствующего payload по хранимой строке `null` и идентификатору формата. JSON декодируется в массивы/скаляры; исходное форматирование JSON для точного сохранения передавайте через `text`.

Payload, details и response имеют независимые кодеки. Сырые значения хранятся в текстовых колонках; названия форматов — в `<field>_format`.

```php
$incoming->rawPayload();               // Сохранённая строка
$incoming->decodedPayload();           // Декодированное значение
$incoming->decodedPayload('details');
$attempt->decodedPayload('response');
```

Для собственного формата реализуйте `TrafficOps\ModelEvents\Contracts\PayloadCodec`:

```php
class Base64Codec implements \TrafficOps\ModelEvents\Contracts\PayloadCodec
{
    public function encode(mixed $value): string
    {
        if (! is_string($value)) {
            throw new \InvalidArgumentException('Expected bytes as a string.');
        }
        return base64_encode($value);
    }

    public function decode(string $value): mixed
    {
        return base64_decode($value, strict: true);
    }
}
```

Добавьте `'base64.v1' => Base64Codec::class` в массив `codecs` опубликованного config и используйте `new Payload('base64.v1', $bytes)`. Результат `encode()` должен подходить для текстовой колонки БД; бинарные данные кодируйте. Классы кодеков разрешаются через контейнер. Сохраняйте старые версии кодеков, пока существуют записи с их идентификаторами. Неизвестный кодек или ошибка сериализации вызывает исключение.

## Отправка

Приложение наследует `SendOutgoingEventJob`, готовит сообщение и реализует транспорт. Следующий пример использует **условный сервис приложения** `MessageTransport`; пакет его не предоставляет:

```php
use TrafficOps\ModelEvents\DTO\DeliveryResult;
use TrafficOps\ModelEvents\DTO\PreparedDelivery;
use TrafficOps\ModelEvents\Jobs\SendOutgoingEventJob;
use TrafficOps\ModelEvents\Models\OutgoingEvent;

class SendProjectMessage extends SendOutgoingEventJob
{
    protected function prepare(OutgoingEvent $event): PreparedDelivery
    {
        $data = $event->decodedPayload();
        return new PreparedDelivery(
            payload: Payload::text('Order #'.$data['order_id']),
            destination: $event->destination,
            metadata: ['event_id' => $event->id],
        );
    }

    protected function send(PreparedDelivery $delivery): DeliveryResult
    {
        // Send именно подготовленные данные. Авторизацию/секреты сервис получает сам.
        $reply = app(MessageTransport::class)->send(
            $delivery->destination,
            $delivery->payload->value,
            $delivery->metadata['event_id'], // Может служить ключом идемпотентности.
        );
        return new DeliveryResult(
            successful: $reply->successful(),
            retryable: $reply->retryable(),
            response: Payload::text($reply->body()),
            metadata: ['status' => $reply->status()],
            error: $reply->successful() ? null : 'Delivery rejected',
        );
    }
}

$project->scheduleOutgoingEvent($outgoing, SendProjectMessage::class);
// Или на заданное время; нужна асинхронная очередь:
// $project->scheduleOutgoingEvent($outgoing, SendProjectMessage::class, now()->addMinutes(10));
```

`prepare()` не должен выполнять внешнюю отправку. Подготовленный payload, destination и metadata фиксируются **до** `send()`. Ответ, response_metadata и ошибка дописываются в ту же попытку. Исходный payload outgoing не меняется. Приложение само определяет успешность ответа, например HTTP 200 с `ok: false` может быть ошибкой.

Состояния outgoing: `pending → queued → processing → succeeded / retrying / failed`. Каждая попытка имеет номер, состояние (`preparing`, `sending`, `succeeded`, `failed`, `interrupted`) и временные отметки. При ошибке подготовки `prepared_at` и данные отправки отсутствуют.

Повторяемый `DeliveryResult` сохраняется и вызывает `RetryableDelivery`; исключения обработчика записываются и пробрасываются. Laravel повторяет job согласно tries/backoff. Неисправимая ошибка результата завершает outgoing без повтора. `failed()` фиксирует исчерпание попыток, не заменяя ответы предыдущих попыток.

Постановка в очередь происходит после commit внешней транзакции, rollback отменяет её. Ошибка публикации возвращается вызывающему коду и возвращает не начатое событие в `pending`. Уже выполненный синхронный обработчик не затирается этой обработкой ошибки. Планировать можно `pending` и `failed`; повторная постановка уже запланированного события отклоняется. Повторная доставка успешно выполненного job ничего не отправляет. `queue:retry` или явное повторное планирование failed сохраняют прежние попытки.

### Настройки и ограничения очереди

Для постоянной ошибки подготовки выбрасывайте `Exceptions\PermanentDeliveryFailure` или его наследника: попытка завершится без повторной отправки и без проброса исключения. Прочие исключения сохраняются и пробрасываются.

Настроенная модель OutgoingEvent может переопределить `acceptsDelivery(): bool` (по умолчанию отклоняет только succeeded) и `deliveryAttemptLimit(): ?int` (по умолчанию null). Абсолютный лимит учитывает все прежние попытки, включая interrupted, и проверяется под блокировкой до создания следующей попытки. Приложение само определяет изменение лимита при ручном повторе. Для автоматического восстановления вызовите `OutgoingScheduler::schedule(..., retryFailed: false)`, чтобы разрешить только pending и не возобновить terminal failed из-за гонки. По умолчанию scheduler сохраняет возможность явного повторного планирования failed.

Config `queue`: `connection = null`, `queue = null` (наследовать очередь приложения), `tries = 3`, `backoff = 60`, `timeout = 60`. В наследнике можно задать свои свойства `public int $tries`, `$backoff`, `$timeout`, а также `$connection` и `$queue`, либо использовать методы `Queueable` при самостоятельном dispatch. Если переопределяете конструктор, вызовите родительский с ID события.

Workers и очистка должны использовать общее хранилище cache с атомарными locks. Config `lock`: `store = null` (default cache), `seconds = 120`, `release_after = 5`. TTL должен превышать job timeout; backend `retry_after`/visibility timeout должен быть больше timeout, а при восстановлении после падений желательно больше TTL блокировки. Захват занятой блокировки освобождает job с задержкой и учитывается Laravel как попытка получения job; ограничение tries выбирайте соответственно нагрузке. `array` cache пригоден только для тестов в одном процессе.

Отложенную отправку нельзя использовать с `sync`; ограничения максимальной задержки задаёт queue backend. Прямой `DeliveryService::deliver()` запрещён внутри транзакции, чтобы снимок попытки был закоммичен до внешнего действия.

Обработчики получают данные из БД по ID. После аварии незавершённая попытка отмечается `interrupted` при следующей обработке. Exactly-once не гарантируется: внешняя система могла принять отправку до падения worker или ошибки сохранения ответа. Идемпотентность и восстановление событий после остановки worker/недоступности очереди обеспечивает приложение. В пакете нет сканера зависших записей и transactional outbox.

Если отправкой управляет собственный код приложения, используйте тот же сервис отдельно от job:

```php
app(\TrafficOps\ModelEvents\Services\DeliveryService::class)->deliver(
    $outgoing->id,
    fn (OutgoingEvent $event) => new PreparedDelivery(Payload::text('hello'), $event->destination),
    fn (PreparedDelivery $prepared) => app(MessageTransport::class)->deliver($prepared),
);
```

Здесь `deliver()` транспорта приложения должен возвращать `DeliveryResult`. Внешний код обрабатывает исключения и повторы сам; длительность вызова должна быть меньше TTL блокировки. Для окончательного завершения после собственных исчерпанных повторов вызовите `DeliveryService::failed($id, $exception)`.

## Чтение истории

`OutgoingEvent::latestAttempt()` выбирает попытку с максимальным номером, включая настроенную через `model-events.models.attempt` модель. `lastResponse()` декодирует её ответ. Связь `IncomingEvent::outgoingEvents()` сохраняет цепочку входящее событие → перенаправления → попытки отправки.

### Общий жизненный цикл перенаправлений

Оба приложения наследуют `Models\RoutedOutgoingEvent`. Этот необязательный базовый класс требует существующие в приложениях столбцы `available_at` и `attempts_offset`, запрещает автоматическую доставку terminal failed и даёт три попытки на запуск. Для другого лимита переопределите `deliveryAttemptsPerRun()`; ограничения владельца добавляются в `acceptsDelivery()` с вызовом родительского метода.

`Services\RoutedDeliveryLifecycle` используется из наблюдателей и редакторов журналов:

- `updating($event)` назначает повтор с учётом `retry_after` **последней** попытки и минимальной задержки 60 секунд.
- `shouldRouteFailure($event)` проверяет переход в failed и предотвращает цикл ошибок доставки; `ignoreSkipped: false` сохраняет политику Gateway.
- `failureContext($event)` возвращает контекст `error`/`outgoing` с ID исходящей отправки и последним ответом.
- `retry($event, guard: ..., metadata: ...)` захватывает блокировку отправки и строку внутри транзакции, проверяет актуальную запись через guard и сбрасывает состояние для нового бюджета попыток. Исходный target, incoming ID и история попыток сохраняются. Авторизацию и допустимые состояния задаёт приложение; после возврата оно запускает свой dispatcher/scheduler.

Эта интеграция не требует миграции существующих журналов. Приложения продолжают управлять outbox, транспортами, правами доступа и отображением журнала.

```php
$events = $project->incomingEvents()
    ->withStatus(IncomingEventStatus::ValidationFailed)
    ->with('outgoingEvents.attempts')
    ->latest('received_at')->paginate();

$attempts = $outgoing->attempts()->get(); // По номеру попытки.
$response = $attempts->last()?->decodedPayload('response');
```

## Модели и очистка

Config `models.incoming`, `models.outgoing`, `models.attempt` принимает наследников `IncomingEvent`, `OutgoingEvent`, `OutgoingEventAttempt`. Сервисы, jobs и отношения используют настроенные классы. Сохраняйте обязательные поля, casts и семантику hard delete базовых моделей. Все три модели журнала должны использовать **одно соединение БД**. При изменении таблиц предоставьте согласованные миграции с FK и индексами; простое наследование использует таблицы пакета.

```php
class PruneProjectEvents extends \TrafficOps\ModelEvents\Jobs\PruneModelEventsJob
{
    // При необходимости сузьте outgoingQuery() / incomingQuery().
}

\Illuminate\Support\Facades\Schedule::job(new PruneProjectEvents)->daily();
```

Defaults `retention`: `incoming_days = 30`, `outgoing_days = 30`, `batch_size = 1000`. `null` отключает соответствующую очистку; `0` делает записи допустимыми к удалению сразу после соответствующего времени. Граница срока включительна.

Срок incoming любого статуса считается от `received_at`, outgoing — от `completed_at`. Удаляются только окончательно завершённые outgoing вместе с попытками. Incoming удаляется только при отсутствии связанных outgoing; активные отправки сохраняются. Срок действия связи может продлить жизнь incoming. Выборка проверяется заново под блокировкой перед удалением. Провайдер не запускает расписания автоматически.

## Проверки пакета

From the repository root:

```sh
composer install
composer check
```

По умолчанию тесты используют SQLite in-memory. Для PostgreSQL из корня монорепозитория:

```sh
MODEL_EVENTS_DB=pgsql MODEL_EVENTS_PG_DATABASE=trafficops_test \
MODEL_EVENTS_PG_USER=postgres MODEL_EVENTS_PG_PASSWORD=postgres \
  vendor/bin/phpunit --testsuite model-events
```

Тесты создают и удаляют отдельную схему `model_events_test_<pid>` в тестовой БД. Параметры подключения: `MODEL_EVENTS_PG_HOST`, `MODEL_EVENTS_PG_PORT`, `MODEL_EVENTS_PG_DATABASE`, `MODEL_EVENTS_PG_USER`, `MODEL_EVENTS_PG_PASSWORD`. В CI передавайте свои значения. Настройки приложения и его таблицы не меняются.
