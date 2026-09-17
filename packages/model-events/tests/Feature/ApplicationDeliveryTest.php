<?php

namespace TrafficOps\ModelEvents\Tests\Feature;

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

    public function test_application_can_prevent_stale_jobs_from_restarting_failed_events(): void
    {
        config(['model-events.models.outgoing' => LimitedOutgoing::class]);
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

    public function acceptsDelivery(): bool
    {
        return parent::acceptsDelivery() && $this->status !== OutgoingEventStatus::Failed;
    }
}
