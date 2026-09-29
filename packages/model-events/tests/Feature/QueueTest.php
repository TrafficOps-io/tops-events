<?php

namespace TrafficOps\ModelEvents\Tests\Feature;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use TrafficOps\ModelEvents\DTO\DeliveryResult;
use TrafficOps\ModelEvents\DTO\OutgoingEventData;
use TrafficOps\ModelEvents\DTO\Payload;
use TrafficOps\ModelEvents\DTO\PreparedDelivery;
use TrafficOps\ModelEvents\Enums\OutgoingEventStatus;
use TrafficOps\ModelEvents\Exceptions\RetryableDelivery;
use TrafficOps\ModelEvents\Models\OutgoingEvent;
use TrafficOps\ModelEvents\Services\OutgoingScheduler;
use TrafficOps\ModelEvents\Support\EventLock;
use TrafficOps\ModelEvents\Tests\Fixtures\TestSendJob;
use TrafficOps\ModelEvents\Tests\TestCase;

class QueueTest extends TestCase
{
    public function test_delayed_dispatch_waits_for_outer_commit_and_serializes_only_id(): void
    {
        config(['queue.default' => 'database']);
        $event = $this->outgoing();
        $at = now()->addHour()->startOfSecond();
        DB::beginTransaction();
        $scheduled = $event->owner->scheduleOutgoingEvent($event, TestSendJob::class, $at);
        $this->assertSame(OutgoingEventStatus::Queued, $scheduled->status);
        $this->assertSame(0, DB::table('jobs')->count());
        DB::commit();
        $row = DB::table('jobs')->sole();
        $this->assertSame($at->timestamp, (int) $row->available_at);
        $payload = json_decode($row->payload, true);
        $job = unserialize($payload['data']['command']);
        $this->assertSame($event->id, $job->eventId);
        $this->assertTrue($job->afterCommit);
        $this->assertStringNotContainsString('original', $payload['data']['command']);
        $this->assertNull(Queue::connection('database')->pop());
        $this->travelTo($at->addSecond());
        Queue::connection('database')->pop()->fire();
        $this->assertSame(OutgoingEventStatus::Succeeded, $event->refresh()->status);
    }

    public function test_outer_rollback_does_not_publish_job_or_change_event(): void
    {
        config(['queue.default' => 'database']);
        $event = $this->outgoing();
        DB::beginTransaction();
        app(OutgoingScheduler::class)->schedule($event, TestSendJob::class);
        DB::rollBack();
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(OutgoingEventStatus::Pending, $event->refresh()->status);
    }

    public function test_dispatch_failure_is_visible_and_can_be_scheduled_again(): void
    {
        $event = $this->outgoing();
        $bus = Mockery::mock(Dispatcher::class);
        $bus->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue unavailable'));
        $scheduler = new OutgoingScheduler($bus);
        DB::beginTransaction();
        $scheduler->schedule($event, TestSendJob::class);
        try {
            DB::commit();
            $this->fail('Expected dispatch failure.');
        } catch (RuntimeException $error) {
            $this->assertSame('Queue unavailable', $error->getMessage());
        }
        $this->assertSame(OutgoingEventStatus::Pending, $event->refresh()->status);
        $this->assertSame('Queue unavailable', $event->last_error);
        app(OutgoingScheduler::class)->schedule($event, TestSendJob::class);
        $this->assertSame(OutgoingEventStatus::Succeeded, $event->refresh()->status);
    }

    public static function queueAttemptLimits(): array
    {
        return ['default' => [0], 'legacy consumer config' => [3]];
    }

    #[DataProvider('queueAttemptLimits')]
    public function test_lock_busy_releases_are_not_attempts_and_never_consume_the_budget(int $tries): void
    {
        config(['queue.default' => 'database', 'model-events.queue.tries' => $tries]);
        $event = $this->outgoing();
        app(OutgoingScheduler::class)->schedule($event, TestSendJob::class);
        app(EventLock::class)->run($event->id, function () use ($event) {
            for ($release = 1; $release <= 5; $release++) {
                app('queue.worker')->process('database', Queue::connection('database')->pop(), new WorkerOptions);
                $this->assertSame(0, $event->attempts()->count());
                $this->assertSame(OutgoingEventStatus::Queued, $event->refresh()->status);
                $this->assertSame($release, (int) DB::table('jobs')->sole()->attempts);
                $this->travel(6)->seconds();
            }
        });
        app('queue.worker')->process('database', Queue::connection('database')->pop(), new WorkerOptions);
        $this->assertSame(OutgoingEventStatus::Succeeded, $event->refresh()->status);
        $this->assertSame(1, $event->attempts()->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    #[DataProvider('queueAttemptLimits')]
    public function test_not_yet_due_releases_are_not_attempts_and_never_consume_the_budget(int $tries): void
    {
        config(['queue.default' => 'database', 'model-events.queue.tries' => $tries]);
        $event = $this->outgoing();
        app(OutgoingScheduler::class)->schedule($event, TestSendJob::class);
        for ($release = 1; $release <= 5; $release++) {
            $event->update(['scheduled_at' => now()->addSeconds(10)]);
            app('queue.worker')->process('database', Queue::connection('database')->pop(), new WorkerOptions);
            $this->assertSame(0, $event->attempts()->count());
            $this->assertSame($release, (int) DB::table('jobs')->sole()->attempts);
            $this->assertNull(Queue::connection('database')->pop());
            $this->travel(10)->seconds();
        }
        app('queue.worker')->process('database', Queue::connection('database')->pop(), new WorkerOptions);
        $this->assertSame(OutgoingEventStatus::Succeeded, $event->refresh()->status);
        $this->assertSame(1, $event->attempts()->count());
    }

    public function test_retry_until_is_the_delivery_expiry_horizon(): void
    {
        $this->travelTo(now()->startOfSecond());
        $event = $this->outgoing();
        $job = new TestSendJob($event->id);
        $this->assertTrue($job->retryUntil()->equalTo(now()->addDays(30)), 'expire_days defaults to outgoing_days.');

        $event->update(['scheduled_at' => now()->addDays(40)]);
        $this->assertTrue($job->retryUntil()->equalTo(now()->addDays(70)), 'A later scheduled_at starts the window.');

        config(['model-events.retention.outgoing_days' => null, 'model-events.retention.expire_days' => null]);
        $this->assertTrue($job->retryUntil()->equalTo(now()->addDays(40 + OutgoingEvent::DEFAULT_EXPIRY_DAYS)), 'Without retention the hard default bounds the job.');

        config(['model-events.retention.expire_days' => 2]);
        $this->assertTrue($job->retryUntil()->equalTo(now()->addDays(42)));
        $this->assertTrue((new TestSendJob('missing'))->retryUntil()->equalTo(now()->addDays(2)));
    }

    public function test_a_delivery_scheduled_far_in_the_future_is_delivered_when_due(): void
    {
        config(['queue.default' => 'database']);
        $event = $this->outgoing();
        app(OutgoingScheduler::class)->schedule($event, TestSendJob::class, now()->addDays(40));
        $this->travel(40)->days();
        $this->travel(1)->seconds();
        app('queue.worker')->process('database', Queue::connection('database')->pop(), new WorkerOptions);
        $this->assertSame(OutgoingEventStatus::Succeeded, $event->refresh()->status);
    }

    public function test_a_delivery_pushed_beyond_its_expiry_horizon_is_failed_as_expired_instead_of_waiting_forever(): void
    {
        config(['queue.default' => 'database']);
        $event = $this->outgoing();
        app(OutgoingScheduler::class)->schedule($event, TestSendJob::class);
        $event->update(['scheduled_at' => now()->addDays(100)]);
        app('queue.worker')->process('database', Queue::connection('database')->pop(), new WorkerOptions);
        $this->assertSame(OutgoingEventStatus::Queued, $event->refresh()->status, 'Not due yet: released, not an attempt.');
        $this->travel(100)->days();
        try {
            app('queue.worker')->process('database', Queue::connection('database')->pop(), new WorkerOptions);
            $this->fail('Laravel fails the job once retryUntil has passed.');
        } catch (MaxAttemptsExceededException) {
            // Worker rethrows after failing the job; failed() has finished the delivery.
        }
        $event->refresh();
        $this->assertSame(OutgoingEventStatus::Failed, $event->status);
        $this->assertSame(OutgoingEvent::ERROR_EXPIRED, $event->last_error);
        $this->assertNotNull($event->completed_at);
        $this->assertSame(0, $event->attempts()->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_queue_tries_are_unlimited_by_default_so_only_recorded_attempts_limit_a_delivery(): void
    {
        $this->assertSame(0, config('model-events.queue.tries'));
        $this->assertSame(0, (new TestSendJob('id'))->tries);
    }

    public function test_a_retryable_failure_on_the_last_permitted_attempt_fails_the_delivery(): void
    {
        config(['queue.default' => 'database']);
        $event = $this->outgoing();
        app(OutgoingScheduler::class)->schedule($event, FailingSendJob::class);
        for ($number = 1; $number <= 3; $number++) {
            $job = Queue::connection('database')->pop();
            $this->assertNotNull($job);
            try {
                app('queue.worker')->process('database', $job, new WorkerOptions);
            } catch (RetryableDelivery) {
                // Worker rethrows after recording failure/releasing for the next attempt.
            }
            $this->assertSame($number, $event->attempts()->count());
            $this->assertSame($number === 3 ? OutgoingEventStatus::Failed : OutgoingEventStatus::Retrying, $event->refresh()->status);
            $this->travel(61)->seconds();
        }
        // The third attempt is the last one the default budget permits: the delivery fails there, without a fourth job run.
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertNotNull($event->completed_at);
        $this->assertSame('Retry', $event->last_error);
        $this->assertSame(['response-1', 'response-2', 'response-3'], $event->attempts()->get()->map(fn ($attempt) => $attempt->decodedPayload('response'))->all());
    }

    public function test_queue_defaults_and_subclass_overrides(): void
    {
        config(['model-events.queue.tries' => 7]);
        $default = new TestSendJob('id');
        $custom = new CustomSendJob('id');
        $this->assertSame(7, $default->tries);
        $this->assertSame(60, $default->backoff);
        $this->assertSame(60, $default->timeout);
        $this->assertSame(2, $custom->tries);
        $this->assertSame(10, $custom->backoff);
    }

    public function test_synchronous_delayed_queue_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(OutgoingScheduler::class)->schedule($this->outgoing(), TestSendJob::class, now()->addHour());
    }

    public function test_scheduler_queues_only_a_pending_delivery(): void
    {
        $owner = $this->owner();
        foreach (OutgoingEventStatus::cases() as $status) {
            if ($status === OutgoingEventStatus::Pending) {
                continue;
            }
            $event = $owner->logOutgoingEvent(new OutgoingEventData('notify', Payload::text('x'), 'recipient'));
            $event->update(['status' => $status]);
            try {
                app(OutgoingScheduler::class)->schedule($event, TestSendJob::class);
                $this->fail("A {$status->value} delivery must not be scheduled; reopening Failed is retry()'s job.");
            } catch (InvalidArgumentException $refusal) {
                $this->assertSame('Only pending deliveries can be scheduled.', $refusal->getMessage());
                $this->assertSame($status, $event->fresh()->status);
            }
        }
        $this->assertFalse((new \ReflectionMethod(OutgoingScheduler::class, 'schedule'))->getParameters()[3] ?? false, 'schedule() takes no retryFailed flag.');
    }

    public function test_duplicate_scheduling_is_rejected(): void
    {
        config(['queue.default' => 'database']);
        $event = $this->outgoing();
        app(OutgoingScheduler::class)->schedule($event, TestSendJob::class);
        try {
            app(OutgoingScheduler::class)->schedule($event, TestSendJob::class);
            $this->fail('Duplicate accepted.');
        } catch (InvalidArgumentException) {
            $this->assertSame(1, DB::table('jobs')->count());
        }
    }
}

class FailingSendJob extends TestSendJob
{
    protected function send(PreparedDelivery $delivery): DeliveryResult
    {
        return new DeliveryResult(false, true, Payload::text('response-'.$this->attempts()), error: 'Retry');
    }
}

class CustomSendJob extends TestSendJob
{
    public int $tries = 2;

    public int $backoff = 10;
}
