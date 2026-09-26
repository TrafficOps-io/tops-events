<?php

namespace TrafficOps\ModelEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use TrafficOps\ModelEvents\Enums\OutgoingEventStatus;
use TrafficOps\ModelEvents\Support\ModelResolver;

class OutgoingEvent extends EventModel
{
    protected $table = 'model_outgoing_events';

    /** Recorded attempts a delivery may use when deliveryAttemptLimit() returns null. */
    public const DEFAULT_ATTEMPT_LIMIT = 3;

    /** Statuses a delivery may still be sent from; Succeeded, Failed and Skipped are terminal. */
    public const ACCEPTING_STATUSES = [OutgoingEventStatus::Pending, OutgoingEventStatus::Queued, OutgoingEventStatus::Processing, OutgoingEventStatus::Retrying];

    /**
     * Only an unfinished delivery accepts delivery. Failed is terminal for automatic
     * processing: a stale job or queue:retry never resumes it, only a manual retry
     * (RoutedDeliveryLifecycle::retry) reopens it. Applications may add owner restrictions.
     */
    public function acceptsDelivery(): bool
    {
        return in_array($this->status, self::ACCEPTING_STATUSES, true);
    }

    /**
     * Absolute recorded-attempt limit, the only business limit on a delivery; null applies
     * DEFAULT_ATTEMPT_LIMIT. Queue releases (busy lock, not yet due) are not attempts.
     */
    public function deliveryAttemptLimit(): ?int
    {
        return null;
    }

    protected function casts(): array
    {
        return [
            'status' => OutgoingEventStatus::class, 'metadata' => 'array',
            'scheduled_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime',
        ];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function incomingEvent(): BelongsTo
    {
        return $this->belongsTo(ModelResolver::class('incoming'), 'incoming_event_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ModelResolver::class('attempt'), 'outgoing_event_id')->orderBy('number');
    }

    public function latestAttempt(): HasOne
    {
        return $this->hasOne(ModelResolver::class('attempt'), 'outgoing_event_id')->ofMany('number', 'max');
    }

    public function lastResponse(): mixed
    {
        return $this->latestAttempt?->decodedPayload('response');
    }
}
