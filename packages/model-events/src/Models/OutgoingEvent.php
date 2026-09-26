<?php

namespace TrafficOps\ModelEvents\Models;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use TrafficOps\ModelEvents\Enums\OutgoingEventStatus;
use TrafficOps\ModelEvents\Support\ModelResolver;

class OutgoingEvent extends EventModel
{
    protected $table = 'model_outgoing_events';

    /** last_error of a delivery failed because it was still unfinished at the end of its expiry window. */
    public const ERROR_EXPIRED = 'expired';

    /** Expiry window in days when neither retention.expire_days nor retention.outgoing_days is set. */
    public const DEFAULT_EXPIRY_DAYS = 30;

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

    /** Days an unfinished delivery may live: retention.expire_days, else outgoing_days, else the default. */
    public static function expiryDays(): int
    {
        return config('model-events.retention.expire_days') ?? config('model-events.retention.outgoing_days') ?? self::DEFAULT_EXPIRY_DAYS;
    }

    /**
     * When this delivery expires if still unfinished: expiryDays() after created_at, or after
     * scheduled_at when that is later. Bounds the queue job and drives the prune job's expiry pass.
     */
    public function expiresAt(): DateTimeImmutable
    {
        $from = ($this->created_at ?? now())->toImmutable();
        if ($this->scheduled_at?->greaterThan($from)) {
            $from = $this->scheduled_at;
        }

        return $from->addDays(static::expiryDays());
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
