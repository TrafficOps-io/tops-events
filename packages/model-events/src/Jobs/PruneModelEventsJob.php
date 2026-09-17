<?php

namespace TrafficOps\ModelEvents\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use TrafficOps\ModelEvents\Enums\OutgoingEventStatus;
use TrafficOps\ModelEvents\Exceptions\EventBusy;
use TrafficOps\ModelEvents\Support\EventLock;
use TrafficOps\ModelEvents\Support\ModelResolver;

abstract class PruneModelEventsJob implements ShouldQueue
{
    use Queueable;

    final public function handle(EventLock $lock): void
    {
        $size = config('model-events.retention.batch_size');
        if (! is_int($size) || $size < 1) {
            throw new InvalidArgumentException('Retention batch_size must be a positive integer.');
        }
        $now = now()->toImmutable();
        $outgoingDays = $this->days('outgoing_days');
        if ($outgoingDays !== null) {
            $cutoff = $now->subDays($outgoingDays);
            $this->outgoingQuery()->whereIn('status', ['succeeded', 'failed'])->where('completed_at', '<=', $cutoff)
                ->chunkById($size, function ($events) use ($lock, $cutoff) {
                    foreach ($events as $event) {
                        try {
                            $lock->run($event->getKey(), function () use ($event, $cutoff) {
                                $event->getConnection()->transaction(function () use ($event, $cutoff) {
                                    $current = $this->outgoingQuery()->whereKey($event->getKey())->lockForUpdate()->first();
                                    if ($current !== null && in_array($current->status, [OutgoingEventStatus::Succeeded, OutgoingEventStatus::Failed], true)
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
