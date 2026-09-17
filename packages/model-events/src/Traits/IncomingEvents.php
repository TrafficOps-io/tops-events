<?php

namespace TrafficOps\ModelEvents\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use TrafficOps\ModelEvents\DTO\IncomingEventData;
use TrafficOps\ModelEvents\Models\IncomingEvent;
use TrafficOps\ModelEvents\Services\EventJournal;
use TrafficOps\ModelEvents\Support\ModelResolver;
use TrafficOps\ModelEvents\Support\OwnerEventsRelation;

trait IncomingEvents
{
    public function incomingEvents(): MorphMany
    {
        $related = ModelResolver::make('incoming');

        return new OwnerEventsRelation($related->newQuery(), $this,
            $related->qualifyColumn('owner_type'), $related->qualifyColumn('owner_id'), $this->getKeyName());
    }

    public function logIncomingEvent(IncomingEventData $data): IncomingEvent
    {
        return app(EventJournal::class)->incoming($this, $data);
    }
}
