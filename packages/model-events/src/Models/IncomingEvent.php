<?php

namespace TrafficOps\ModelEvents\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use TrafficOps\ModelEvents\Enums\IncomingEventStatus;
use TrafficOps\ModelEvents\Support\ModelResolver;

class IncomingEvent extends EventModel
{
    protected $table = 'model_incoming_events';

    protected function casts(): array
    {
        return ['status' => IncomingEventStatus::class, 'metadata' => 'array', 'received_at' => 'immutable_datetime'];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function outgoingEvents(): HasMany
    {
        return $this->hasMany(ModelResolver::class('outgoing'), 'incoming_event_id');
    }

    public function scopeWithStatus(Builder $query, IncomingEventStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }
}
