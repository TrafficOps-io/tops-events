<?php

namespace TrafficOps\ModelEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use TrafficOps\ModelEvents\Enums\AttemptStatus;
use TrafficOps\ModelEvents\Support\ModelResolver;

class OutgoingEventAttempt extends EventModel
{
    protected $table = 'model_outgoing_event_attempts';

    protected function casts(): array
    {
        return [
            'status' => AttemptStatus::class, 'number' => 'integer', 'metadata' => 'array',
            'response_metadata' => 'array', 'retryable' => 'boolean',
            'started_at' => 'immutable_datetime', 'prepared_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function outgoingEvent(): BelongsTo
    {
        return $this->belongsTo(ModelResolver::class('outgoing'), 'outgoing_event_id');
    }
}
