<?php

namespace TrafficOps\EventDelivery\Jobs;

use TrafficOps\EventDelivery\Channels\ChannelRegistry;
use TrafficOps\EventDelivery\Contracts\DeliveryGuard;
use TrafficOps\EventDelivery\DTO\DeliveryTarget;
use TrafficOps\ModelEvents\DTO\DeliveryResult;
use TrafficOps\ModelEvents\DTO\PreparedDelivery;
use TrafficOps\ModelEvents\Exceptions\SkippedDelivery;
use TrafficOps\ModelEvents\Jobs\SendOutgoingEventJob;
use TrafficOps\ModelEvents\Models\OutgoingEvent;
use TrafficOps\ModelEvents\Support\ModelResolver;

class DeliverEvent extends SendOutgoingEventJob
{
    public function backoff(): int
    {
        $event = ModelResolver::make('outgoing')->newQuery()->find($this->eventId);

        return $event?->available_at ? max(60, (int) now()->diffInSeconds($event->available_at, false)) : 60;
    }

    protected function prepare(OutgoingEvent $event): PreparedDelivery
    {
        if (app()->bound(DeliveryGuard::class) && $reason = app(DeliveryGuard::class)->rejectionReason($event)) {
            // The delivery finishes as Skipped with last_error = reason. The metadata mirror is
            // deprecated and kept for one release; read status and last_error instead.
            $event->forceFill(['metadata' => [...$event->metadata, 'disposition' => 'skipped', 'skip_reason' => $reason]])->saveOrFail();
            throw new SkippedDelivery($reason);
        }
        $snapshot = $event->decodedPayload();
        $target = DeliveryTarget::fromArray($snapshot['target'] ?? []);

        return app(ChannelRegistry::class)->get($target->type)->prepare($target, $event->id);
    }

    protected function send(PreparedDelivery $delivery): DeliveryResult
    {
        return app(ChannelRegistry::class)->get($delivery->metadata['type'])->send($delivery);
    }
}
