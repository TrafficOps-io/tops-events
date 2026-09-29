<?php

namespace TrafficOps\ModelEvents\Jobs;

use Closure;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use TrafficOps\ModelEvents\Enums\OutgoingEventStatus;
use TrafficOps\ModelEvents\Exceptions\EventBusy;
use TrafficOps\ModelEvents\Models\OutgoingEvent;
use TrafficOps\ModelEvents\Support\EventLock;
use TrafficOps\ModelEvents\Support\ModelResolver;

abstract class PruneModelEventsJob implements ShouldQueue
{
    use Queueable;

    private const INTERRUPTED_BY_EXPIRY = 'The delivery expired before this attempt recorded a result; delivery outcome is unknown.';

    private EventLock $lock;

    private int $size;

    final public function handle(EventLock $lock): void
    {
        $size = config('model-events.retention.batch_size');
        if (! is_int($size) || $size < 1) {
            throw new InvalidArgumentException('Retention batch_size must be a positive integer.');
        }
        [$this->lock, $this->size] = [$lock, $size];
        $now = now()->toImmutable();
        $outgoingDays = $this->days('outgoing_days');
        $this->days('expire_days');
        $this->expire($now->subDays(OutgoingEvent::expiryDays()));
        if ($outgoingDays !== null) {
            $this->deleteFinished($now->subDays($outgoingDays));
        }
        $incomingDays = $this->days('incoming_days');
        if ($incomingDays !== null) {
            $this->deleteOrphanedIncoming($now->subDays($incomingDays));
        }
    }

    /**
     * A delivery still unfinished at the end of its window is failed as expired so its incoming
     * event can be pruned later. The window is OutgoingEvent::expiryDays() from created_at, or
     * from scheduled_at when later, and always applies: a delivery must terminate even when
     * retention is disabled. Deliveries held by an active worker are skipped until the next run.
     */
    private function expire(DateTimeImmutable $cutoff): void
    {
        $this->forEachLocked(
            fn () => $this->outgoingQuery()
                ->whereIn('status', OutgoingEventStatus::values(...OutgoingEventStatus::accepting()))
                ->where('created_at', '<=', $cutoff)
                ->where(fn (Builder $query) => $query->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', $cutoff)),
            function (OutgoingEvent $delivery) {
                $delivery->interruptOpenAttempts(self::INTERRUPTED_BY_EXPIRY);
                $delivery->markFailed(OutgoingEvent::ERROR_EXPIRED);
            },
        );
    }

    /** Finished deliveries past retention are deleted with their attempts; the incoming source is preserved. */
    private function deleteFinished(DateTimeImmutable $cutoff): void
    {
        $this->forEachLocked(
            fn () => $this->outgoingQuery()
                ->whereIn('status', OutgoingEventStatus::values(...OutgoingEventStatus::terminal()))
                ->where('completed_at', '<=', $cutoff),
            fn (OutgoingEvent $delivery) => $delivery->delete(),
        );
    }

    private function deleteOrphanedIncoming(DateTimeImmutable $cutoff): void
    {
        $this->incomingQuery()->where('received_at', '<=', $cutoff)->whereDoesntHave('outgoingEvents')
            ->chunkById($this->size, function ($events) use ($cutoff) {
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

    /**
     * Runs $act on every delivery the candidate query selects, one at a time under the event
     * lock and a transaction, after re-selecting the row with the same conditions under
     * lockForUpdate so a delivery that changed since selection is left alone. Deliveries an
     * active worker holds are skipped until the next run.
     *
     * @param  Closure(): Builder  $candidates
     * @param  Closure(OutgoingEvent): void  $act
     */
    private function forEachLocked(Closure $candidates, Closure $act): void
    {
        $candidates()->chunkById($this->size, function ($events) use ($candidates, $act) {
            foreach ($events as $event) {
                try {
                    $this->lock->run($event->getKey(), fn () => $event->getConnection()->transaction(function () use ($event, $candidates, $act) {
                        $current = $candidates()->whereKey($event->getKey())->lockForUpdate()->first();
                        if ($current !== null) {
                            $act($current);
                        }
                    }));
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
