<?php

namespace TrafficOps\ModelEvents\Tests\Feature;

use TrafficOps\ModelEvents\DTO\DeliveryResult;
use TrafficOps\ModelEvents\DTO\Payload;
use TrafficOps\ModelEvents\DTO\PreparedDelivery;
use TrafficOps\ModelEvents\Enums\AttemptStatus;
use TrafficOps\ModelEvents\Enums\OutgoingEventStatus;
use TrafficOps\ModelEvents\Exceptions\PermanentDeliveryFailure;
use TrafficOps\ModelEvents\Models\OutgoingEvent;
use TrafficOps\ModelEvents\Services\DeliveryService;
use TrafficOps\ModelEvents\Tests\TestCase;

class ApplicationDeliveryTest extends TestCase
{
    public function test_permanent_preparation_failure_does_not_send_or_retry(): void
    {
        $event = $this->outgoing();
        $result = app(DeliveryService::class)->deliver($event->id, fn () => throw new PermanentDeliveryFailure('Missing macro.'), fn () => $this->fail('Transport was invoked.'));
        $this->assertSame(OutgoingEventStatus::Failed, $result->status);
        $this->assertNull($event->attempts()->sole()->prepared_at);
        $this->assertFalse($event->attempts()->sole()->retryable);
    }

    public function test_attempt_limit_is_enforced_before_starting_another_attempt(): void
    {
        config(['model-events.models.outgoing' => LimitedOutgoing::class]);
        $event = $this->outgoing();
        $event->attempts()->create(['number' => 1, 'status' => AttemptStatus::Sending, 'started_at' => now()]);
        $result = app(DeliveryService::class)->deliver($event->id, fn () => $this->fail('Preparation was invoked.'), fn () => $this->fail('Transport was invoked.'));
        $this->assertSame(OutgoingEventStatus::Failed, $result->status);
        $this->assertSame(1, $event->attempts()->count());
        $this->assertSame(AttemptStatus::Interrupted, $event->attempts()->sole()->status);
    }

    public function test_a_delivery_without_an_explicit_limit_uses_the_default_attempt_limit(): void
    {
        $event = $this->outgoing();
        $this->assertNull($event->deliveryAttemptLimit());
        foreach (range(1, OutgoingEvent::DEFAULT_ATTEMPT_LIMIT) as $number) {
            $event->attempts()->create(['number' => $number, 'status' => AttemptStatus::Failed, 'started_at' => now(), 'completed_at' => now()]);
        }
        $event->update(['status' => OutgoingEventStatus::Retrying]);
        $result = app(DeliveryService::class)->deliver($event->id, fn () => $this->fail('Preparation was invoked.'), fn () => $this->fail('Transport was invoked.'));
        $this->assertSame(OutgoingEventStatus::Failed, $result->status);
        $this->assertSame(OutgoingEvent::DEFAULT_ATTEMPT_LIMIT, $event->attempts()->count());
        $this->assertSame('Delivery retry budget exhausted.', $result->last_error);
    }

    public function test_a_retryable_result_on_the_last_permitted_attempt_finishes_as_failed_without_a_retry(): void
    {
        config(['model-events.models.outgoing' => LimitedOutgoing::class]);
        $event = $this->outgoing();
        $result = app(DeliveryService::class)->deliver($event->id,
            fn () => new PreparedDelivery(Payload::text('x'), 'y'),
            fn () => new DeliveryResult(false, true, error: 'Rate limited'),
        );
        $this->assertSame(OutgoingEventStatus::Failed, $result->status);
        $this->assertNotNull($result->completed_at);
        $this->assertSame('Rate limited', $result->last_error);
        $this->assertTrue($event->attempts()->sole()->retryable, 'The attempt keeps the transport verdict; the budget decides the delivery.');
    }

    public function test_failed_delivery_is_terminal_for_stale_jobs_in_the_base_model(): void
    {
        $event = $this->outgoing();
        $event->update(['status' => OutgoingEventStatus::Failed]);
        $result = app(DeliveryService::class)->deliver($event->id, fn () => $this->fail('Preparation was invoked.'), fn () => $this->fail('Transport was invoked.'));
        $this->assertSame(OutgoingEventStatus::Failed, $result->status);
        $this->assertSame(0, $event->attempts()->count());
    }
}

class LimitedOutgoing extends OutgoingEvent
{
    public function deliveryAttemptLimit(): ?int
    {
        return 1;
    }
}
