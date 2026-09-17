<?php

namespace TrafficOps\ModelEvents\Traits;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use TrafficOps\ModelEvents\DTO\OutgoingEventData;
use TrafficOps\ModelEvents\Models\OutgoingEvent;
use TrafficOps\ModelEvents\Services\EventJournal;
use TrafficOps\ModelEvents\Services\OutgoingScheduler;
use TrafficOps\ModelEvents\Support\ModelResolver;
use TrafficOps\ModelEvents\Support\OwnerEventsRelation;

trait OutgoingEvents
{
    public function outgoingEvents(): MorphMany
    {
        $related = ModelResolver::make('outgoing');

        return new OwnerEventsRelation($related->newQuery(), $this,
            $related->qualifyColumn('owner_type'), $related->qualifyColumn('owner_id'), $this->getKeyName());
    }

    public function logOutgoingEvent(OutgoingEventData $data): OutgoingEvent
    {
        return app(EventJournal::class)->outgoing($this, $data);
    }

    public function scheduleOutgoingEvent(OutgoingEvent $event, string $jobClass, ?DateTimeInterface $at = null): OutgoingEvent
    {
        app(EventJournal::class)->assertOwner($this, $event);

        return app(OutgoingScheduler::class)->schedule($event, $jobClass, $at);
    }
}
