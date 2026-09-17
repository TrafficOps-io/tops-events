<?php

namespace TrafficOps\ModelEvents\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use TrafficOps\ModelEvents\DTO\DeliveryResult;
use TrafficOps\ModelEvents\DTO\PreparedDelivery;
use TrafficOps\ModelEvents\Exceptions\EventBusy;
use TrafficOps\ModelEvents\Models\OutgoingEvent;
use TrafficOps\ModelEvents\Services\DeliveryService;
use TrafficOps\ModelEvents\Support\ModelResolver;

abstract class SendOutgoingEventJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    // Declare these in a subclass to override config defaults.
    public int $tries;

    public int $backoff;

    public int $timeout;

    public function __construct(public readonly string $eventId)
    {
        $this->tries ??= config('model-events.queue.tries');
        $this->backoff ??= config('model-events.queue.backoff');
        $this->timeout ??= config('model-events.queue.timeout');
        $this->connection ??= config('model-events.queue.connection');
        $this->queue ??= config('model-events.queue.queue');
        $this->afterCommit();
    }

    final public function handle(DeliveryService $delivery): void
    {
        if ($this->timeout < 1 || config('model-events.lock.seconds') <= $this->timeout) {
            throw new InvalidArgumentException('Event lock lifetime must exceed the positive job timeout.');
        }
        $event = ModelResolver::make('outgoing')->newQuery()->find($this->eventId);
        if ($event?->scheduled_at?->isFuture()) {
            $this->release($event->scheduled_at);

            return;
        }
        try {
            $delivery->deliver($this->eventId, fn (OutgoingEvent $event) => $this->prepare($event), fn (PreparedDelivery $prepared) => $this->send($prepared));
        } catch (EventBusy) {
            $this->release(config('model-events.lock.release_after'));
        }
    }

    final public function failed(?Throwable $error): void
    {
        app(DeliveryService::class)->failed($this->eventId, $error ?? new RuntimeException('The outgoing job was manually failed.'));
    }

    abstract protected function prepare(OutgoingEvent $event): PreparedDelivery;

    abstract protected function send(PreparedDelivery $delivery): DeliveryResult;
}
