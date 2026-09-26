<?php

namespace TrafficOps\EventDelivery\Tests;

use TrafficOps\EventDelivery\Contracts\DeliveryGuard;
use TrafficOps\EventDelivery\EventDeliveryServiceProvider;
use TrafficOps\EventDelivery\Jobs\DeliverEvent;
use TrafficOps\ModelEvents\Enums\OutgoingEventStatus;
use TrafficOps\ModelEvents\Models\OutgoingEvent;
use TrafficOps\ModelEvents\Services\DeliveryService;
use TrafficOps\ModelEvents\Tests\TestCase;

class DeliverEventTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), EventDeliveryServiceProvider::class];
    }

    public function test_guard_rejection_skips_the_delivery_before_sending(): void
    {
        $this->app->instance(DeliveryGuard::class, new class implements DeliveryGuard
        {
            public function rejectionReason(OutgoingEvent $event): ?string
            {
                return 'Owner is paused.';
            }
        });
        $event = $this->outgoing();
        (new DeliverEvent($event->id))->handle(app(DeliveryService::class));

        $event->refresh();
        $this->assertSame(OutgoingEventStatus::Skipped, $event->status);
        $this->assertSame('Owner is paused.', $event->last_error);
        $this->assertNotNull($event->completed_at);
        $this->assertFalse($event->acceptsDelivery());
        // Deprecated mirror kept for one release; read status and last_error instead.
        $this->assertSame(['disposition' => 'skipped', 'skip_reason' => 'Owner is paused.'], $event->metadata);
        $this->assertSame(1, $event->attempts()->count());
    }

    public function test_guard_acceptance_continues_to_preparation(): void
    {
        $this->app->instance(DeliveryGuard::class, new class implements DeliveryGuard
        {
            public function rejectionReason(OutgoingEvent $event): ?string
            {
                return null;
            }
        });
        $event = $this->outgoing();
        // The fixture payload has no target: preparation fails permanently, which is a Failed delivery, not a Skipped one.
        (new DeliverEvent($event->id))->handle(app(DeliveryService::class));
        $this->assertSame(OutgoingEventStatus::Failed, $event->refresh()->status);
    }
}
