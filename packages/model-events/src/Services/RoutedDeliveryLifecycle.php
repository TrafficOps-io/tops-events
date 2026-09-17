<?php

namespace TrafficOps\ModelEvents\Services;

use Closure;
use TrafficOps\ModelEvents\Enums\OutgoingEventStatus;
use TrafficOps\ModelEvents\Models\RoutedOutgoingEvent;
use TrafficOps\ModelEvents\Support\EventLock;

/** Shared journal transitions; authorization, dispatch and trigger names belong to the application. */
final class RoutedDeliveryLifecycle
{
    public function __construct(private EventLock $lock) {}

    public function updating(RoutedOutgoingEvent $event, int $minimumDelay = 60): void
    {
        if ($event->isDirty('status') && $event->status === OutgoingEventStatus::Retrying) {
            $last = $event->attempts()->reorder('number', 'desc')->first();
            $event->available_at = now()->addSeconds(max($minimumDelay, (int) ($last?->response_metadata['retry_after'] ?? 0)));
            $event->scheduled_at = $event->available_at;
        }
    }

    public function shouldRouteFailure(RoutedOutgoingEvent $event, bool $ignoreSkipped = true): bool
    {
        return $event->wasChanged('status') && $event->status === OutgoingEventStatus::Failed
            && (! $ignoreSkipped || ($event->metadata['disposition'] ?? null) !== 'skipped')
            && ! (($event->metadata['trigger_kind'] ?? '') === 'system' && ($event->metadata['trigger_name'] ?? '') === 'delivery_failed');
    }

    public function failureContext(RoutedOutgoingEvent $event): array
    {
        $response = $event->lastResponse();

        return [
            'error' => ['message' => $event->last_error, 'details' => $response],
            'outgoing' => ['id' => $event->id, 'response' => $response],
        ];
    }

    /** The guard runs on the locked current row; history and the original target remain intact. */
    public function retry(RoutedOutgoingEvent $event, ?Closure $guard = null, array $metadata = []): RoutedOutgoingEvent
    {
        return $this->lock->run($event->id, fn () => $event->getConnection()->transaction(function () use ($event, $guard, $metadata) {
            $current = $event->newQuery()->lockForUpdate()->findOrFail($event->id);
            $guard?->__invoke($current);
            $current->forceFill([
                'attempts_offset' => $current->attempts()->max('number') ?? 0,
                'status' => OutgoingEventStatus::Pending,
                'available_at' => now(), 'scheduled_at' => null,
                'completed_at' => null, 'last_error' => null,
                'metadata' => [...($current->metadata ?? []), ...$metadata],
            ])->saveOrFail();

            return $current;
        }));
    }
}
