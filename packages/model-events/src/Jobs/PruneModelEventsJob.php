<?php

namespace TrafficOps\ModelEvents\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use TrafficOps\ModelEvents\Enums\AttemptStatus;
use TrafficOps\ModelEvents\Enums\OutgoingEventStatus;
use TrafficOps\ModelEvents\Exceptions\EventBusy;
use TrafficOps\ModelEvents\Models\OutgoingEvent;
use TrafficOps\ModelEvents\Support\EventLock;
use TrafficOps\ModelEvents\Support\ModelResolver;

abstract class PruneModelEventsJob implements ShouldQueue
{
    use Queueable;

    /** Terminal delivery statuses; only these are ever deleted. */
    private const FINISHED = [OutgoingEventStatus::Succeeded, OutgoingEventStatus::Failed, OutgoingEventStatus::Skipped];

    /** Deliveries that outlive their expiry window in one of these statuses are failed as expired. */
    private const UNFINISHED = OutgoingEvent::ACCEPTING_STATUSES;

    final public function handle(EventLock $lock): void
    {
        $size = config('model-events.retention.batch_size');
        if (! is_int($size) || $size < 1) {
            throw new InvalidArgumentException('Retention batch_size must be a positive integer.');
        }
        $now = now()->toImmutable();
        $outgoingDays = $this->days('outgoing_days');
        $expireDays = $this->days('expire_days') ?? $outgoingDays;
        if ($expireDays !== null) {
            $this->expire($lock, $now->subDays($expireDays), $size);
        }
        if ($outgoingDays !== null) {
            $cutoff = $now->subDays($outgoingDays);
            $this->outgoingQuery()->whereIn('status', array_map(fn ($status) => $status->value, self::FINISHED))->where('completed_at', '<=', $cutoff)
                ->chunkById($size, function ($events) use ($lock, $cutoff) {
                    foreach ($events as $event) {
                        try {
                            $lock->run($event->getKey(), function () use ($event, $cutoff) {
                                $event->getConnection()->transaction(function () use ($event, $cutoff) {
                                    $current = $this->outgoingQuery()->whereKey($event->getKey())->lockForUpdate()->first();
                                    if ($current !== null && in_array($current->status, self::FINISHED, true)
                                        && $current->completed_at !== null && $current->completed_at->lte($cutoff)) {
                                        $current->delete(); // Attempts cascade; the incoming source is preserved.
                                    }
                                });
                            });
                        } catch (EventBusy) {
                            // Skip active deliveries; the next cleanup run will reconsider them.
                        }
                    }
                });
        }
        $incomingDays = $this->days('incoming_days');
        if ($incomingDays !== null) {
            $cutoff = $now->subDays($incomingDays);
            $this->incomingQuery()->where('received_at', '<=', $cutoff)->whereDoesntHave('outgoingEvents')
                ->chunkById($size, function ($events) use ($cutoff) {
                    foreach ($events as $event) {
                        $event->getConnection()->transaction(function () use ($event, $cutoff) {
                            // Writers lock the source before attaching an outgoing event.
                            $current = $this->incomingQuery()->whereKey($event->getKey())->lockForUpdate()->first();
                            if ($current !== null && $current->received_at->lte($cutoff) && ! $current->outgoingEvents()->exists()) {
                                $current->delete();
                            }
                        });
                    }
                });
        }
    }

    /**
     * A delivery still unfinished at the end of its window is failed as expired so its incoming
     * event can be pruned later. The window runs from created_at, or from scheduled_at when later.
     * Deliveries held by an active worker are skipped until the next run.
     */
    private function expire(EventLock $lock, \DateTimeImmutable $cutoff, int $size): void
    {
        $stale = fn (Builder $query) => $query
            ->whereIn('status', array_map(fn ($status) => $status->value, self::UNFINISHED))
            ->where('created_at', '<=', $cutoff)
            ->where(fn (Builder $query) => $query->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', $cutoff));
        $stale($this->outgoingQuery())->chunkById($size, function ($events) use ($lock, $stale) {
            foreach ($events as $event) {
                try {
                    $lock->run($event->getKey(), function () use ($event, $stale) {
                        $event->getConnection()->transaction(function () use ($event, $stale) {
                            $current = $stale($this->outgoingQuery()->whereKey($event->getKey()))->lockForUpdate()->first();
                            if ($current === null) {
                                return;
                            }
                            $current->attempts()->whereNull('completed_at')->update([
                                'status' => AttemptStatus::Interrupted->value, 'completed_at' => now(),
                                'error' => 'The delivery expired before this attempt recorded a result; delivery outcome is unknown.',
                            ]);
                            $current->forceFill([
                                'status' => OutgoingEventStatus::Failed, 'completed_at' => now(),
                                'active_attempt_id' => null, 'last_error' => 'expired',
                            ])->saveOrFail();
                        });
                    });
                } catch (EventBusy) {
                    // An active worker owns the delivery; the next cleanup run will reconsider it.
                }
            }
        });
    }

    protected function outgoingQuery(): Builder
    {
        return ModelResolver::make('outgoing')->newQuery();
    }

    protected function incomingQuery(): Builder
    {
        return ModelResolver::make('incoming')->newQuery();
    }

    private function days(string $key): ?int
    {
        $days = config('model-events.retention.'.$key);
        if ($days !== null && (! is_int($days) || $days < 0)) {
            throw new InvalidArgumentException('Retention days must be null or a non-negative integer.');
        }

        return $days;
    }
}
