<?php

namespace TrafficOps\ModelEvents\Services;

use Closure;
use LogicException;
use Throwable;
use TrafficOps\ModelEvents\DTO\DeliveryResult;
use TrafficOps\ModelEvents\DTO\PreparedDelivery;
use TrafficOps\ModelEvents\Enums\AttemptStatus;
use TrafficOps\ModelEvents\Enums\OutgoingEventStatus;
use TrafficOps\ModelEvents\Exceptions\EventBusy;
use TrafficOps\ModelEvents\Exceptions\PermanentDeliveryFailure;
use TrafficOps\ModelEvents\Exceptions\RetryableDelivery;
use TrafficOps\ModelEvents\Models\OutgoingEvent;
use TrafficOps\ModelEvents\Models\OutgoingEventAttempt;
use TrafficOps\ModelEvents\Support\EventLock;
use TrafficOps\ModelEvents\Support\ModelResolver;
use TrafficOps\ModelEvents\Support\Payloads;

final class DeliveryService
{
    public function __construct(private EventLock $lock, private Payloads $payloads) {}

    /**
     * @param  Closure(OutgoingEvent): PreparedDelivery  $prepare  No external side effects.
     * @param  Closure(PreparedDelivery): DeliveryResult  $send  Send the prepared snapshot unchanged.
     */
    public function deliver(string $eventId, Closure $prepare, Closure $send): ?OutgoingEvent
    {
        if (ModelResolver::make('outgoing')->getConnection()->transactionLevel() !== 0) {
            throw new LogicException('Delivery must run outside a database transaction so its journal is committed before sending.');
        }

        return $this->lock->run($eventId, function () use ($eventId, $prepare, $send) {
            $model = ModelResolver::make('outgoing');
            $started = $model->getConnection()->transaction(function () use ($model, $eventId) {
                $event = $model->newQuery()->lockForUpdate()->find($eventId);
                if ($event === null || ! $event->acceptsDelivery()) {
                    return [$event, null];
                }
                if ($event->scheduled_at?->isFuture()) {
                    throw new LogicException('The outgoing event is not due yet.');
                }
                $this->interruptAttempts($event);
                $number = ((int) $event->attempts()->max('number')) + 1;
                if ($event->deliveryAttemptLimit() !== null && $number > $event->deliveryAttemptLimit()) {
                    $event->forceFill(['status' => OutgoingEventStatus::Failed, 'completed_at' => now(),
                        'active_attempt_id' => null, 'last_error' => 'Delivery retry budget exhausted.'])->saveOrFail();

                    return [$event, null];
                }
                $attempt = ModelResolver::make('attempt');
                $attempt->forceFill([
                    'outgoing_event_id' => $eventId,
                    'number' => $number,
                    'status' => AttemptStatus::Preparing, 'started_at' => now(),
                ])->saveOrFail();
                $event->forceFill([
                    'status' => OutgoingEventStatus::Processing, 'completed_at' => null,
                    'active_attempt_id' => $attempt->getKey(), 'last_error' => null,
                ])->saveOrFail();

                return [$event, $attempt];
            });
            [$event, $attempt] = $started;
            if ($attempt === null) {
                return $event;
            }

            try {
                $delivery = $prepare($event);
                if (! $delivery instanceof PreparedDelivery) {
                    throw new LogicException('prepare() must return PreparedDelivery.');
                }
                $preparedAttributes = [
                    ...$this->payloads->attributes('payload', $delivery->payload),
                    'destination' => $delivery->destination, 'metadata' => $delivery->metadata,
                    'prepared_at' => now(), 'status' => AttemptStatus::Sending,
                ];
                $event->getConnection()->transaction(function () use ($event, $attempt, $preparedAttributes) {
                    $current = $event->newQuery()->lockForUpdate()->findOrFail($event->getKey());
                    if ($current->active_attempt_id !== $attempt->getKey()) {
                        throw new EventBusy('This attempt no longer owns the outgoing event.');
                    }
                    $attempt->forceFill($preparedAttributes)->saveOrFail();
                });
                $result = $send($delivery);
                if (! $result instanceof DeliveryResult) {
                    throw new LogicException('send() must return DeliveryResult.');
                }
                $this->finish($event, $attempt, $result);
            } catch (PermanentDeliveryFailure $error) {
                $this->finish($event, $attempt, new DeliveryResult(false, error: $error->getMessage()), $error::class);

                return $event->refresh();
            } catch (Throwable $error) {
                // Includes serialization failures. Never invoke the transport again here.
                $this->finish($event, $attempt, new DeliveryResult(false, true, error: $error->getMessage()), $error::class);
                throw $error;
            }

            if (! $result->successful && $result->retryable) {
                throw new RetryableDelivery($result->error ?? 'Delivery requested a retry.');
            }

            return $event->refresh();
        });
    }

    /** Laravel may call this on a fresh job instance, including after a timeout. */
    public function failed(string $eventId, Throwable $error): void
    {
        try {
            $this->lock->run($eventId, function () use ($eventId, $error) {
                $model = ModelResolver::make('outgoing');
                $model->getConnection()->transaction(function () use ($model, $eventId, $error) {
                    $event = $model->newQuery()->lockForUpdate()->find($eventId);
                    if ($event === null || in_array($event->status, [OutgoingEventStatus::Succeeded, OutgoingEventStatus::Failed], true)) {
                        return;
                    }
                    $this->interruptAttempts($event);
                    $event->forceFill([
                        'status' => OutgoingEventStatus::Failed, 'completed_at' => now(),
                        'active_attempt_id' => null, 'last_error' => $error->getMessage(),
                    ])->saveOrFail();
                });
            }, allowOwned: true);
        } catch (EventBusy) {
            // An overlapping job exhausted its queue attempts; the active sender owns the result.
        }
    }

    private function finish(OutgoingEvent $event, OutgoingEventAttempt $attempt, DeliveryResult $result, ?string $exceptionClass = null): void
    {
        $attributes = $this->payloads->attributes('response', $result->response);
        $event->getConnection()->transaction(function () use ($event, $attempt, $result, $exceptionClass, $attributes) {
            $current = $event->newQuery()->lockForUpdate()->findOrFail($event->getKey());
            if ($current->active_attempt_id !== $attempt->getKey()) {
                throw new EventBusy('This attempt no longer owns the outgoing event.');
            }
            $retry = ! $result->successful && $result->retryable;
            $attempt->forceFill([
                ...$attributes, 'response_metadata' => $result->metadata,
                'status' => $result->successful ? AttemptStatus::Succeeded : AttemptStatus::Failed,
                'retryable' => $retry, 'error' => $result->error, 'exception_class' => $exceptionClass,
                'completed_at' => now(),
            ])->saveOrFail();
            $current->forceFill([
                'status' => $result->successful ? OutgoingEventStatus::Succeeded : ($retry ? OutgoingEventStatus::Retrying : OutgoingEventStatus::Failed),
                'completed_at' => $retry ? null : now(), 'active_attempt_id' => null,
                'last_error' => $result->error,
            ])->saveOrFail();
        });
    }

    private function interruptAttempts(OutgoingEvent $event): void
    {
        $event->attempts()->whereNull('completed_at')->update([
            'status' => AttemptStatus::Interrupted->value, 'completed_at' => now(),
            'error' => 'The worker stopped before recording a result; delivery outcome is unknown.',
        ]);
    }
}
