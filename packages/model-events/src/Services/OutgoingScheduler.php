<?php

namespace TrafficOps\ModelEvents\Services;

use DateTimeInterface;
use Illuminate\Contracts\Bus\Dispatcher;
use InvalidArgumentException;
use ReflectionClass;
use Throwable;
use TrafficOps\ModelEvents\Enums\OutgoingEventStatus;
use TrafficOps\ModelEvents\Jobs\SendOutgoingEventJob;
use TrafficOps\ModelEvents\Models\OutgoingEvent;
use TrafficOps\ModelEvents\Support\ModelResolver;

final class OutgoingScheduler
{
    public function __construct(private Dispatcher $bus) {}

    public function schedule(OutgoingEvent $event, string $jobClass, ?DateTimeInterface $at = null, bool $retryFailed = true): OutgoingEvent
    {
        if (! $event->exists || ! is_subclass_of($jobClass, SendOutgoingEventJob::class) || (new ReflectionClass($jobClass))->isAbstract()) {
            throw new InvalidArgumentException('Scheduling requires a persisted outgoing event and a concrete SendOutgoingEventJob subclass.');
        }
        $job = new $jobClass((string) $event->getKey());
        $connection = $job->connection ?? config('queue.default');
        if ($at !== null && $at > now() && config("queue.connections.$connection.driver") === 'sync') {
            throw new InvalidArgumentException('Delayed delivery requires an asynchronous queue connection.');
        }
        $job->afterCommit()->delay($at);
        $model = ModelResolver::make('outgoing');
        // Queue publication is deferred until this connection's outer transaction commits.
        $model->getConnection()->transaction(function () use ($model, $event, $job, $at, $retryFailed) {
            $stored = $model->newQuery()->lockForUpdate()->findOrFail($event->getKey());
            $allowed = $retryFailed ? [OutgoingEventStatus::Pending, OutgoingEventStatus::Failed] : [OutgoingEventStatus::Pending];
            if (! in_array($stored->status, $allowed, true)) {
                throw new InvalidArgumentException('Only pending or failed events can be scheduled.');
            }
            $stored->forceFill([
                'status' => OutgoingEventStatus::Queued, 'scheduled_at' => $at ?? now(),
                'completed_at' => null, 'last_error' => null,
            ])->saveOrFail();
            $model->getConnection()->afterCommit(function () use ($stored, $job) {
                try {
                    $this->bus->dispatch($job);
                } catch (Throwable $error) {
                    // A synchronous handler may already have run: do not overwrite its result.
                    $stored->newQuery()->whereKey($stored->getKey())
                        ->where('status', OutgoingEventStatus::Queued->value)
                        ->update(['status' => OutgoingEventStatus::Pending->value, 'last_error' => $error->getMessage()]);
                    throw $error;
                }
            });
        });

        return $model->newQuery()->findOrFail($event->getKey());
    }
}
