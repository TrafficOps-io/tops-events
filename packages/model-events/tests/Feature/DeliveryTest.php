<?php

namespace TrafficOps\ModelEvents\Tests\Feature;

use RuntimeException;
use TrafficOps\ModelEvents\DTO\DeliveryResult;
use TrafficOps\ModelEvents\DTO\Payload;
use TrafficOps\ModelEvents\DTO\PreparedDelivery;
use TrafficOps\ModelEvents\Enums\AttemptStatus;
use TrafficOps\ModelEvents\Enums\OutgoingEventStatus;
use TrafficOps\ModelEvents\Exceptions\EventBusy;
use TrafficOps\ModelEvents\Exceptions\RetryableDelivery;
use TrafficOps\ModelEvents\Models\OutgoingEventAttempt;
use TrafficOps\ModelEvents\Services\DeliveryService;
use TrafficOps\ModelEvents\Support\EventLock;
use TrafficOps\ModelEvents\Tests\Fixtures\TestSendJob;
use TrafficOps\ModelEvents\Tests\TestCase;

class DeliveryTest extends TestCase
{
    public function test_prepared_snapshot_exists_before_send_and_success_is_not_sent_twice(): void
    {
        $event = $this->outgoing();
        $prepare = fn () => new PreparedDelivery(Payload::text('rendered message'), 'telegram:chat-42', ['transport' => 'telegram']);
        $send = function (PreparedDelivery $delivery) use ($event) {
            $attempt = $event->attempts()->sole();
            $this->assertSame(AttemptStatus::Sending, $attempt->status);
            $this->assertSame($delivery->payload->value, $attempt->decodedPayload());
            $this->assertSame('telegram:chat-42', $attempt->destination);
            $this->assertNull($attempt->completed_at);

            return new DeliveryResult(true, response: Payload::json(['ok' => true]), metadata: ['http_status' => 200]);
        };
        $service = app(DeliveryService::class);
        $service->deliver($event->id, $prepare, $send);
        $service->deliver($event->id, fn () => $this->fail('Duplicate preparation'), fn () => $this->fail('Duplicate send'));
        $attempt = $event->attempts()->sole();
        $this->assertSame(AttemptStatus::Succeeded, $attempt->status);
        $this->assertSame(['ok' => true], $attempt->decodedPayload('response'));
        $this->assertSame(['http_status' => 200], $attempt->response_metadata);
        $this->assertSame(['original' => true], $event->decodedPayload());
        $this->assertSame(OutgoingEventStatus::Succeeded, $event->refresh()->status);
        $this->assertNotNull($event->completed_at);
    }

    public function test_retryable_response_is_preserved_and_next_attempt_is_separate(): void
    {
        $event = $this->outgoing();
        try {
            app(DeliveryService::class)->deliver($event->id,
                fn () => new PreparedDelivery(Payload::text('first'), 'recipient-1'),
                fn () => new DeliveryResult(false, true, Payload::text('slow down'), ['status' => 429], 'Rate limited'),
            );
            $this->fail('Retry was not requested.');
        } catch (RetryableDelivery) {
            $this->assertSame(OutgoingEventStatus::Retrying, $event->refresh()->status);
            $this->assertNull($event->completed_at);
        }
        app(DeliveryService::class)->deliver($event->id,
            fn () => new PreparedDelivery(Payload::text('second'), 'recipient-2'),
            fn () => new DeliveryResult(true, response: Payload::json(null)),
        );
        $attempts = $event->attempts()->get();
        $this->assertSame([1, 2], $attempts->pluck('number')->all());
        $this->assertSame('slow down', $attempts[0]->decodedPayload('response'));
        $this->assertSame('first', $attempts[0]->decodedPayload());
        $this->assertSame('second', $attempts[1]->decodedPayload());
        $this->assertSame('null', $attempts[1]->rawPayload('response'));
    }

    public function test_permanent_failure_finishes_without_requesting_retry(): void
    {
        $event = $this->outgoing();
        app(DeliveryService::class)->deliver($event->id,
            fn () => new PreparedDelivery(Payload::text('x'), 'y'),
            fn () => new DeliveryResult(false, error: 'Recipient does not exist'),
        );
        $this->assertSame(OutgoingEventStatus::Failed, $event->refresh()->status);
        $this->assertNotNull($event->completed_at);
        $this->assertFalse($event->attempts()->sole()->retryable);
    }

    public function test_prepare_and_send_exceptions_are_recorded_and_rethrown(): void
    {
        $event = $this->outgoing();
        foreach (['prepare', 'send'] as $phase) {
            try {
                app(DeliveryService::class)->deliver($event->id,
                    fn () => $phase === 'prepare' ? throw new RuntimeException('prepare failed') : new PreparedDelivery(Payload::text('x'), 'y'),
                    fn () => throw new RuntimeException('send failed'),
                );
                $this->fail('Expected exception.');
            } catch (RuntimeException $error) {
                $this->assertSame($phase.' failed', $error->getMessage());
            }
        }
        [$first, $second] = $event->attempts()->get()->all();
        $this->assertNull($first->prepared_at);
        $this->assertNull($first->rawPayload());
        $this->assertNotNull($second->prepared_at);
        $this->assertSame(RuntimeException::class, $second->exception_class);
        (new TestSendJob($event->id))->failed(new RuntimeException('Exhausted'));
        $this->assertSame(OutgoingEventStatus::Failed, $event->refresh()->status);
        $this->assertSame('send failed', $second->fresh()->error);
        $this->assertSame('Exhausted', $event->last_error);
    }

    public function test_payload_encoding_failure_never_calls_send(): void
    {
        $event = $this->outgoing();
        try {
            app(DeliveryService::class)->deliver($event->id,
                fn () => new PreparedDelivery(Payload::json(NAN), 'target'),
                fn () => $this->fail('Invalid payload must not be sent'),
            );
        } catch (\JsonException) {
            $attempt = $event->attempts()->sole();
            $this->assertSame(AttemptStatus::Failed, $attempt->status);
            $this->assertNull($attempt->prepared_at);
        }
    }

    public function test_response_encoding_failure_is_recorded_without_a_second_send(): void
    {
        $event = $this->outgoing();
        $calls = 0;
        try {
            app(DeliveryService::class)->deliver($event->id,
                fn () => new PreparedDelivery(Payload::text('x'), 'y'),
                function () use (&$calls) {
                    $calls++;

                    return new DeliveryResult(true, response: Payload::json(NAN));
                },
            );
            $this->fail('Invalid response should throw.');
        } catch (\JsonException) {
            $this->assertSame(1, $calls);
            $this->assertSame(AttemptStatus::Failed, $event->attempts()->sole()->status);
        }
    }

    public function test_abandoned_attempt_is_interrupted_before_next_attempt(): void
    {
        $event = $this->outgoing();
        $event->attempts()->create(['number' => 1, 'status' => AttemptStatus::Sending, 'started_at' => now()->subMinutes(10)]);
        $event->update(['status' => OutgoingEventStatus::Processing]);
        (new TestSendJob($event->id))->handle(app(DeliveryService::class));
        $this->assertSame([AttemptStatus::Interrupted, AttemptStatus::Succeeded], $event->attempts()->get()->pluck('status')->all());
    }

    public function test_shared_lock_releases_job_and_does_not_create_an_attempt(): void
    {
        $event = $this->outgoing();
        $job = (new TestSendJob($event->id))->withFakeQueueInteractions();
        app(EventLock::class)->run($event->id, function () use ($job, $event) {
            $job->handle(app(DeliveryService::class));
            $job->assertReleased(5);
            $this->assertSame(0, $event->attempts()->count());
        });
        $job->handle(app(DeliveryService::class));
        $this->assertSame(OutgoingEventStatus::Succeeded, $event->fresh()->status);
    }

    public function test_timeout_failure_callback_can_finalize_the_lock_owned_by_this_worker(): void
    {
        $event = $this->outgoing();
        $event->update(['status' => OutgoingEventStatus::Processing]);
        $event->attempts()->create(['number' => 1, 'status' => AttemptStatus::Sending, 'started_at' => now()]);
        app(EventLock::class)->run($event->id, function () use ($event) {
            (new TestSendJob($event->id))->failed(new RuntimeException('Timed out'));
        });
        $this->assertSame(OutgoingEventStatus::Failed, $event->fresh()->status);
        $this->assertSame(AttemptStatus::Interrupted, $event->attempts()->sole()->status);
    }

    public function test_missing_event_is_a_noop(): void
    {
        $event = $this->outgoing();
        $id = $event->id;
        $event->delete();
        (new TestSendJob($id))->handle(app(DeliveryService::class));
        (new TestSendJob($id))->failed(new RuntimeException('Deleted'));
        $this->assertSame(0, OutgoingEventAttempt::query()->count());
    }

    public function test_delivery_inside_outer_transaction_is_rejected_before_transport(): void
    {
        $event = $this->outgoing();
        $event->getConnection()->beginTransaction();
        try {
            app(DeliveryService::class)->deliver($event->id,
                fn () => $this->fail('Preparation must not run inside an outer transaction'),
                fn () => $this->fail('Sending must not run inside an outer transaction'),
            );
            $this->fail('Expected a transaction guard.');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('outside a database transaction', $error->getMessage());
        } finally {
            $event->getConnection()->rollBack();
        }
        $this->assertSame(0, $event->attempts()->count());
    }

    public function test_lost_attempt_ownership_during_prepare_prevents_stale_sender(): void
    {
        $event = $this->outgoing();
        try {
            app(DeliveryService::class)->deliver($event->id,
                function () use ($event) {
                    // Simulate another worker taking over after lease expiry.
                    $event->newQuery()->whereKey($event->id)->update(['active_attempt_id' => null]);

                    return new PreparedDelivery(Payload::text('stale'), 'recipient');
                },
                fn () => $this->fail('A superseded attempt must not send'),
            );
            $this->fail('Expected ownership guard.');
        } catch (EventBusy) {
            $this->assertNull($event->attempts()->sole()->prepared_at);
        }
    }
}
