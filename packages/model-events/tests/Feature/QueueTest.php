<?php

namespace TrafficOps\ModelEvents\Tests\Feature;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Mockery;
use RuntimeException;
use TrafficOps\ModelEvents\DTO\DeliveryResult;
use TrafficOps\ModelEvents\DTO\Payload;
use TrafficOps\ModelEvents\DTO\PreparedDelivery;
use TrafficOps\ModelEvents\Enums\OutgoingEventStatus;
use TrafficOps\ModelEvents\Exceptions\RetryableDelivery;
use TrafficOps\ModelEvents\Services\OutgoingScheduler;
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

    public function test_actual_worker_retries_and_finalizes_after_max_tries(): void
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
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertNotNull($event->completed_at);
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
