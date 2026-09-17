<?php

namespace TrafficOps\ModelEvents\Services;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use TrafficOps\ModelEvents\DTO\IncomingEventData;
use TrafficOps\ModelEvents\DTO\OutgoingEventData;
use TrafficOps\ModelEvents\Enums\OutgoingEventStatus;
use TrafficOps\ModelEvents\Models\EventModel;
use TrafficOps\ModelEvents\Models\IncomingEvent;
use TrafficOps\ModelEvents\Models\OutgoingEvent;
use TrafficOps\ModelEvents\Support\ModelResolver;
use TrafficOps\ModelEvents\Support\Payloads;

final class EventJournal
{
    public function __construct(private Payloads $payloads) {}

    public function incoming(Model $owner, IncomingEventData $data): IncomingEvent
    {
        $this->assertSaved($owner);
        $event = ModelResolver::make('incoming');
        $event->forceFill([
            'owner_type' => $owner->getMorphClass(), 'owner_id' => (string) $owner->getKey(),
            'name' => $data->name, 'status' => $data->status,
            'metadata' => $data->metadata, 'received_at' => $data->receivedAt ?? now(),
            ...$this->payloads->attributes('payload', $data->payload),
            ...$this->payloads->attributes('details', $data->details),
        ])->saveOrFail();

        return $event;
    }

    public function outgoing(Model $owner, OutgoingEventData $data): OutgoingEvent
    {
        $this->assertSaved($owner);
        $event = ModelResolver::make('outgoing');

        return $event->getConnection()->transaction(function () use ($owner, $data, $event) {
            if ($data->incoming !== null) {
                $this->assertSaved($data->incoming);
                $source = ModelResolver::make('incoming')->newQuery()->lockForUpdate()->findOrFail($data->incoming->getKey());
                $this->assertOwner($owner, $source);
            }
            $event->forceFill([
                'owner_type' => $owner->getMorphClass(), 'owner_id' => (string) $owner->getKey(),
                'incoming_event_id' => $data->incoming?->getKey(),
                'name' => $data->name, 'destination' => $data->destination,
                'status' => OutgoingEventStatus::Pending, 'metadata' => $data->metadata,
                ...$this->payloads->attributes('payload', $data->payload),
            ])->saveOrFail();

            return $event;
        });
    }

    public function assertOwner(Model $owner, EventModel $event): void
    {
        $this->assertSaved($owner);
        if ($event->owner_type !== $owner->getMorphClass() || (string) $event->owner_id !== (string) $owner->getKey()) {
            throw new InvalidArgumentException('The event must belong to the same owner.');
        }
    }

    private function assertSaved(Model $model): void
    {
        if (! $model->exists || $model->getKey() === null) {
            throw new InvalidArgumentException('A persisted model is required.');
        }
    }
}
