<?php

namespace TrafficOps\ModelEvents\Tests\Feature;

use Illuminate\Database\Eloquent\Builder;
use TrafficOps\ModelEvents\DTO\IncomingEventData;
use TrafficOps\ModelEvents\DTO\OutgoingEventData;
use TrafficOps\ModelEvents\DTO\Payload;
use TrafficOps\ModelEvents\Enums\AttemptStatus;
use TrafficOps\ModelEvents\Enums\IncomingEventStatus;
use TrafficOps\ModelEvents\Enums\OutgoingEventStatus;
use TrafficOps\ModelEvents\Jobs\PruneModelEventsJob;
use TrafficOps\ModelEvents\Models\IncomingEvent;
use TrafficOps\ModelEvents\Models\OutgoingEvent;
use TrafficOps\ModelEvents\Models\OutgoingEventAttempt;
use TrafficOps\ModelEvents\Services\OutgoingScheduler;
use TrafficOps\ModelEvents\Support\EventLock;
use TrafficOps\ModelEvents\Tests\Fixtures\TestSendJob;
use TrafficOps\ModelEvents\Tests\TestCase;

class PruneTest extends TestCase
{
    public function test_cleanup_preserves_chain_until_all_outgoing_expire(): void
    {
        $this->travelTo(now()->startOfSecond());
        config(['model-events.retention.batch_size' => 1]);
        $owner = $this->owner();
        $source = $owner->logIncomingEvent(new IncomingEventData('in', Payload::text('raw'), IncomingEventStatus::ValidationFailed, receivedAt: now()->subDays(40)));
        $old = $owner->logOutgoingEvent(new OutgoingEventData('old', Payload::text('1'), 'target', $source));
        $new = $owner->logOutgoingEvent(new OutgoingEventData('new', Payload::text('2'), 'target', $source));
        $old->update(['status' => OutgoingEventStatus::Succeeded, 'completed_at' => now()->subDays(30)]);
        $new->update(['status' => OutgoingEventStatus::Failed, 'completed_at' => now()->subDays(29)]);
        $old->attempts()->create(['number' => 1, 'status' => AttemptStatus::Succeeded, 'started_at' => now()->subDays(30)]);
        $this->prune();
        $this->assertNull($old->fresh());
        $this->assertSame(0, OutgoingEventAttempt::query()->count());
        $this->assertNotNull($source->fresh());
        $this->assertNotNull($new->fresh());
        $this->travel(1)->days();
        $this->prune();
        $this->assertSame(0, IncomingEvent::query()->count());
        $this->assertSame(0, OutgoingEvent::query()->count());
    }

    public function test_active_events_and_their_sources_never_expire(): void
    {
        $owner = $this->owner();
        $source = $owner->logIncomingEvent(new IncomingEventData('in', Payload::text('raw'), IncomingEventStatus::Error, receivedAt: now()->subDays(100)));
        foreach ([OutgoingEventStatus::Pending, OutgoingEventStatus::Queued, OutgoingEventStatus::Processing, OutgoingEventStatus::Retrying] as $status) {
            $event = $owner->logOutgoingEvent(new OutgoingEventData('out', Payload::text('x'), 'y', $source));
            $event->update(['status' => $status, 'completed_at' => now()->subDays(100)]);
        }
        $this->prune();
        $this->assertNotNull($source->fresh());
        $this->assertSame(4, OutgoingEvent::query()->count());
    }

    public function test_disabled_retention_keeps_records_and_locks_skip_busy_events(): void
    {
        $event = $this->outgoing();
        $event->update(['status' => OutgoingEventStatus::Failed, 'completed_at' => now()->subDays(100)]);
        config(['model-events.retention.outgoing_days' => null]);
        $this->prune();
        $this->assertNotNull($event->fresh());
        config(['model-events.retention.outgoing_days' => 30]);
        app(EventLock::class)->run($event->id, function () use ($event) {
            $this->prune();
            $this->assertNotNull($event->fresh());
        });
        $this->prune();
        $this->assertNull($event->fresh());
    }

    public function test_recheck_does_not_delete_event_rescheduled_after_candidate_selection(): void
    {
        config(['queue.default' => 'database']);
        $event = $this->outgoing();
        $event->update(['status' => OutgoingEventStatus::Failed, 'completed_at' => now()->subDays(100)]);
        $job = new class($event) extends PruneModelEventsJob
        {
            private int $queries = 0;

            public function __construct(private OutgoingEvent $event) {}

            protected function outgoingQuery(): Builder
            {
                if (++$this->queries === 2) {
                    app(OutgoingScheduler::class)->schedule($this->event, TestSendJob::class, now()->addDay());
                }

                return parent::outgoingQuery();
            }
        };
        $job->handle(app(EventLock::class));
        $this->assertSame(OutgoingEventStatus::Queued, $event->refresh()->status);
    }

    public function test_incoming_disabled_retention_and_custom_query(): void
    {
        $owner = $this->owner();
        foreach (['keep', 'delete'] as $name) {
            $owner->logIncomingEvent(new IncomingEventData($name, Payload::text('raw'), IncomingEventStatus::Ok, receivedAt: now()->subDays(40)));
        }
        config(['model-events.retention.incoming_days' => null]);
        $this->prune();
        $this->assertSame(2, IncomingEvent::query()->count());
        config(['model-events.retention.incoming_days' => 30]);
        $job = new class extends PruneModelEventsJob
        {
            protected function incomingQuery(): Builder
            {
                return parent::incomingQuery()->where('name', 'delete');
            }
        };
        $job->handle(app(EventLock::class));
        $this->assertSame('keep', IncomingEvent::query()->sole()->name);
    }

    private function prune(): void
    {
        (new class extends PruneModelEventsJob {})->handle(app(EventLock::class));
    }
}
