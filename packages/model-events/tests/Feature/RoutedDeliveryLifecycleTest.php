<?php

namespace TrafficOps\ModelEvents\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LogicException;
use TrafficOps\ModelEvents\DTO\IncomingEventData;
use TrafficOps\ModelEvents\DTO\OutgoingEventData;
use TrafficOps\ModelEvents\DTO\Payload;
use TrafficOps\ModelEvents\Enums\AttemptStatus;
use TrafficOps\ModelEvents\Enums\IncomingEventStatus;
use TrafficOps\ModelEvents\Enums\OutgoingEventStatus;
use TrafficOps\ModelEvents\Exceptions\EventBusy;
use TrafficOps\ModelEvents\Models\OutgoingEventAttempt;
use TrafficOps\ModelEvents\Models\RoutedOutgoingEvent;
use TrafficOps\ModelEvents\Services\RoutedDeliveryLifecycle;
use TrafficOps\ModelEvents\Support\EventLock;
use TrafficOps\ModelEvents\Support\Payloads;
use TrafficOps\ModelEvents\Tests\TestCase;

class RoutedDeliveryLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::table('model_outgoing_events', function (Blueprint $table) {
            $table->timestampTz('available_at')->nullable();
            $table->unsignedInteger('attempts_offset')->default(0);
        });
        config(['model-events.models.outgoing' => RoutedOutgoingEvent::class]);
        $this->travelTo(now()->startOfSecond());
    }

    public function test_retry_preserves_the_incoming_link_target_payload_and_attempt_history(): void
    {
        $owner = $this->owner();
        $incoming = $owner->logIncomingEvent(new IncomingEventData('purchase', Payload::json(['amount' => 0]), IncomingEventStatus::Ok));
        $event = $owner->logOutgoingEvent(new OutgoingEventData('purchase', Payload::json(['target' => 'original']), 'crm', $incoming, ['rule_id' => 7]));
        $attempt = $this->attempt($event, 3);
        $event->update(['status' => OutgoingEventStatus::Failed, 'completed_at' => now(), 'last_error' => 'Unavailable']);
        $this->assertFalse($event->acceptsDelivery());

        $retried = app(RoutedDeliveryLifecycle::class)->retry($event, metadata: ['disposition' => 'retrying']);

        $this->assertSame(OutgoingEventStatus::Pending, $retried->status);
        $this->assertTrue($retried->acceptsDelivery());
        $this->assertSame(6, $retried->deliveryAttemptLimit());
        $this->assertSame($incoming->id, $retried->incoming_event_id);
        $this->assertSame($event->id, $incoming->outgoingEvents()->sole()->id);
        $this->assertSame($attempt->id, $retried->attempts()->sole()->id);
        $this->assertSame(['target' => 'original'], $retried->decodedPayload());
        $this->assertSame('crm', $retried->destination);
        $this->assertSame(['rule_id' => 7, 'disposition' => 'retrying'], $retried->metadata);
        $this->assertNull($retried->scheduled_at);
        $this->assertNull($retried->completed_at);
        $this->assertNull($retried->last_error);
        $this->assertTrue($retried->available_at->equalTo(now()));
    }

    public function test_retry_guard_reads_current_status_and_rejects_without_modifying_history(): void
    {
        $event = $this->outgoing();
        $event->newQuery()->whereKey($event->id)->update(['status' => OutgoingEventStatus::Succeeded]);
        try {
            app(RoutedDeliveryLifecycle::class)->retry($event, function ($current) {
                $this->assertSame(OutgoingEventStatus::Succeeded, $current->status);
                throw new LogicException('Cannot retry this event.');
            });
            $this->fail('Expected guard rejection.');
        } catch (LogicException $exception) {
            $this->assertSame('Cannot retry this event.', $exception->getMessage());
        }
        $this->assertSame(OutgoingEventStatus::Succeeded, $event->fresh()->status);
        $this->assertSame(0, $event->attempts()->count());
    }

    public function test_retry_cannot_overlap_an_active_delivery_lock(): void
    {
        $event = $this->outgoing();
        $this->expectException(EventBusy::class);
        app(EventLock::class)->run($event->id, fn () => app(RoutedDeliveryLifecycle::class)->retry($event));
    }

    public function test_retry_after_uses_the_newest_attempt_and_a_minimum_delay(): void
    {
        $event = $this->outgoing();
        $this->attempt($event, 1, 300);
        $latest = $this->attempt($event, 2, 180);
        $event->status = OutgoingEventStatus::Retrying;
        app(RoutedDeliveryLifecycle::class)->updating($event);
        $this->assertTrue($event->available_at->equalTo(now()->addSeconds(180)));
        $this->assertTrue($event->scheduled_at->equalTo($event->available_at));

        $latest->update(['response_metadata' => ['retry_after' => 5]]);
        app(RoutedDeliveryLifecycle::class)->updating($event);
        $this->assertTrue($event->available_at->equalTo(now()->addSeconds(60)));
    }

    public function test_latest_response_respects_custom_attempt_models_and_keeps_failure_correlation(): void
    {
        config(['model-events.models.attempt' => TracedAttempt::class]);
        $event = $this->outgoing();
        $this->attempt($event, 1, response: ['status' => 503]);
        $this->attempt($event, 2, response: ['status' => 429]);
        $event->update(['status' => OutgoingEventStatus::Failed, 'last_error' => 'Rate limited']);
        $this->assertInstanceOf(TracedAttempt::class, $event->latestAttempt);
        $this->assertSame(['status' => 429], $event->lastResponse());
        $lifecycle = app(RoutedDeliveryLifecycle::class);
        $this->assertTrue($lifecycle->shouldRouteFailure($event));
        $this->assertSame([
            'error' => ['message' => 'Rate limited', 'details' => ['status' => 429]],
            'outgoing' => ['id' => $event->id, 'response' => ['status' => 429]],
        ], $lifecycle->failureContext($event));

        $event->metadata = ['trigger_kind' => 'system', 'trigger_name' => 'delivery_failed'];
        $this->assertFalse($lifecycle->shouldRouteFailure($event));
        $event->metadata = ['disposition' => 'skipped'];
        $this->assertFalse($lifecycle->shouldRouteFailure($event));
        $this->assertTrue($lifecycle->shouldRouteFailure($event, ignoreSkipped: false));
        $event->save();
        $this->assertFalse($lifecycle->shouldRouteFailure($event));
    }

    private function attempt(RoutedOutgoingEvent $event, int $number, int $retryAfter = 0, array $response = []): OutgoingEventAttempt
    {
        return $event->attempts()->create([
            'number' => $number, 'status' => AttemptStatus::Failed, 'started_at' => now(), 'completed_at' => now(),
            'response_metadata' => ['retry_after' => $retryAfter],
            ...app(Payloads::class)->attributes('response', Payload::json($response)),
        ]);
    }
}

class TracedAttempt extends OutgoingEventAttempt {}
