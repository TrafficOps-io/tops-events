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

    public function test_skipped_deliveries_are_finished_and_pruned_with_their_source(): void
    {
        $owner = $this->owner();
        $source = $owner->logIncomingEvent(new IncomingEventData('in', Payload::text('raw'), IncomingEventStatus::Ok, receivedAt: now()->subDays(40)));
        $skipped = $owner->logOutgoingEvent(new OutgoingEventData('out', Payload::text('x'), 'y', $source));
        $skipped->update(['status' => OutgoingEventStatus::Skipped, 'completed_at' => now()->subDays(31)]);
        $this->prune();
        $this->assertNull($skipped->fresh());
        $this->assertNull($source->fresh());
    }

    public function test_unfinished_delivery_older_than_retention_is_failed_as_expired(): void
    {
        $this->travelTo(now()->startOfSecond());
        $owner = $this->owner();
        $source = $owner->logIncomingEvent(new IncomingEventData('in', Payload::text('raw'), IncomingEventStatus::Ok, receivedAt: now()->subDays(40)));
        $stuck = [];
        foreach ([OutgoingEventStatus::Pending, OutgoingEventStatus::Queued, OutgoingEventStatus::Processing, OutgoingEventStatus::Retrying] as $status) {
            $event = $owner->logOutgoingEvent(new OutgoingEventData('out', Payload::text('x'), 'y', $source));
            $attempt = $event->attempts()->create(['number' => 1, 'status' => AttemptStatus::Sending, 'started_at' => now()->subDays(40)]);
            $event->update(['status' => $status, 'created_at' => now()->subDays(40), 'active_attempt_id' => $attempt->id]);
            $stuck[] = $event;
        }
        $this->prune();
        foreach ($stuck as $event) {
            $event->refresh();
            $this->assertSame(OutgoingEventStatus::Failed, $event->status);
            $this->assertSame('expired', $event->last_error);
            $this->assertTrue($event->completed_at->equalTo(now()));
            $this->assertNull($event->active_attempt_id);
            $this->assertFalse($event->acceptsDelivery());
            $this->assertSame(AttemptStatus::Interrupted, $event->attempts()->sole()->status);
            $this->assertNotNull($event->attempts()->sole()->completed_at);
        }
        // Expired deliveries are finished now and follow the normal retention; the source waits for them.
        $this->assertNotNull($source->fresh());
        $this->travel(30)->days();
        $this->prune();
        $this->assertSame(0, OutgoingEvent::query()->count());
        $this->assertNull($source->fresh());
    }

    public function test_a_later_scheduled_at_extends_the_life_of_an_unfinished_delivery(): void
    {
        $owner = $this->owner();
        $waiting = $owner->logOutgoingEvent(new OutgoingEventData('out', Payload::text('x'), 'y'));
        $waiting->update(['status' => OutgoingEventStatus::Queued, 'created_at' => now()->subDays(40), 'scheduled_at' => now()->subDays(5)]);
        $overdue = $owner->logOutgoingEvent(new OutgoingEventData('out', Payload::text('x'), 'y'));
        $overdue->update(['status' => OutgoingEventStatus::Queued, 'created_at' => now()->subDays(40), 'scheduled_at' => now()->subDays(31)]);
        $this->prune();
        $this->assertSame(OutgoingEventStatus::Queued, $waiting->fresh()->status);
        $this->assertSame(OutgoingEventStatus::Failed, $overdue->fresh()->status);
        $this->assertSame('expired', $overdue->fresh()->last_error);
    }

    public function test_expiry_skips_a_delivery_held_by_an_active_worker(): void
    {
        $event = $this->outgoing();
        $event->update(['status' => OutgoingEventStatus::Processing, 'created_at' => now()->subDays(40)]);
        app(EventLock::class)->run($event->id, function () use ($event) {
            $this->prune();
            $this->assertSame(OutgoingEventStatus::Processing, $event->fresh()->status);
        });
        $this->prune();
        $this->assertSame(OutgoingEventStatus::Failed, $event->fresh()->status);
    }

    public function test_expiry_window_defaults_to_outgoing_retention_and_can_be_set_separately(): void
    {
        $event = $this->outgoing();
        $event->update(['status' => OutgoingEventStatus::Pending, 'created_at' => now()->subDays(3)]);
        $this->assertNull(config('model-events.retention.expire_days'));
        $this->prune();
        $this->assertSame(OutgoingEventStatus::Pending, $event->fresh()->status);

        config(['model-events.retention.outgoing_days' => null, 'model-events.retention.expire_days' => 2]);
        $this->prune();
        $this->assertSame(OutgoingEventStatus::Failed, $event->fresh()->status);
        $this->assertSame('expired', $event->fresh()->last_error);
    }

    public function test_active_deliveries_within_retention_and_their_sources_are_kept(): void
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
                    // A manual retry reopened the delivery between candidate selection and the locked re-check.
                    $this->event->update(['status' => OutgoingEventStatus::Pending, 'completed_at' => null]);
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
