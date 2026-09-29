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
use TrafficOps\ModelEvents\Exceptions\SkippedDelivery;
use TrafficOps\ModelEvents\Models\OutgoingEvent;
use TrafficOps\ModelEvents\Models\OutgoingEventAttempt;
use TrafficOps\ModelEvents\Support\EventLock;
use TrafficOps\ModelEvents\Support\ModelResolver;
use TrafficOps\ModelEvents\Support\Payloads;

final class DeliveryService
{
    private const INTERRUPTED_BY_WORKER = 'The worker stopped before recording a result; delivery outcome is unknown.';

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
                $event->interruptOpenAttempts(self::INTERRUPTED_BY_WORKER);
                $number = ((int) $event->attempts()->max('number')) + 1;
                if ($number > $event->deliveryAttemptLimit()) {
                    $event->markFailed('Delivery retry budget exhausted.');

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
                $retry = $this->finish($event, $attempt, $result);
            } catch (PermanentDeliveryFailure $error) {
                $this->finish($event, $attempt, new DeliveryResult(false, error: $error->getMessage()), $error);

                return $event->refresh();
            } catch (Throwable $error) {
                // Includes serialization failures. Never invoke the transport again here.
                $this->finish($event, $attempt, new DeliveryResult(false, true, error: $error->getMessage()), $error);
                throw $error;
            }

            if ($retry) {
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
                    if ($event === null || ! $event->acceptsDelivery()) {
                        return;
                    }
                    $event->interruptOpenAttempts(self::INTERRUPTED_BY_WORKER);
                    $event->markFailed($error->getMessage());
                });
            }, allowOwned: true);
        } catch (EventBusy) {
            // An overlapping job exhausted its queue attempts; the active sender owns the result.
        }
    }

    /**
     * Records the attempt outcome and the delivery status. $error is the exception that ended
     * the attempt, if any; a SkippedDelivery finishes the delivery as Skipped. Returns whether
     * another attempt follows: a retryable failure on the last permitted attempt fails the
     * delivery instead, so the job is not released only to discover an exhausted budget.
     */
    private function finish(OutgoingEvent $event, OutgoingEventAttempt $attempt, DeliveryResult $result, ?Throwable $error = null): bool
    {
        $attributes = $this->payloads->attributes('response', $result->response);
        $retryable = ! $result->successful && $result->retryable;
        $retry = $retryable && $attempt->number < $event->deliveryAttemptLimit();
        $status = match (true) {
            $result->successful => OutgoingEventStatus::Succeeded,
            $retry => OutgoingEventStatus::Retrying,
            $error instanceof SkippedDelivery => OutgoingEventStatus::Skipped,
            default => OutgoingEventStatus::Failed,
        };
        $event->getConnection()->transaction(function () use ($event, $attempt, $result, $error, $attributes, $retryable, $retry, $status) {
            $current = $event->newQuery()->lockForUpdate()->findOrFail($event->getKey());
            if ($current->active_attempt_id !== $attempt->getKey()) {
                throw new EventBusy('This attempt no longer owns the outgoing event.');
            }
            $attempt->forceFill([
                ...$attributes, 'response_metadata' => $result->metadata,
                'status' => $result->successful ? AttemptStatus::Succeeded : AttemptStatus::Failed,
                'retryable' => $retryable, 'error' => $result->error, 'exception_class' => $error === null ? null : $error::class,
                'completed_at' => now(),
            ])->saveOrFail();
            $current->forceFill([
                'status' => $status,
                'completed_at' => $retry ? null : now(), 'active_attempt_id' => null,
                'last_error' => $result->error,
            ])->saveOrFail();
        });

        return $retry;
    }
}
