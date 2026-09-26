<?php

namespace TrafficOps\ModelEvents\Models;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use TrafficOps\ModelEvents\Enums\AttemptStatus;
use TrafficOps\ModelEvents\Enums\OutgoingEventStatus;
use TrafficOps\ModelEvents\Support\ModelResolver;

class OutgoingEvent extends EventModel
{
    protected $table = 'model_outgoing_events';

    /** last_error of a delivery failed because it was still unfinished at the end of its expiry window. */
    public const ERROR_EXPIRED = 'expired';

    /** Expiry window in days when neither retention.expire_days nor retention.outgoing_days is set. */
    public const DEFAULT_EXPIRY_DAYS = 30;

    /** Recorded attempts a delivery may use unless deliveryAttemptLimit() says otherwise. */
    public const DEFAULT_ATTEMPT_LIMIT = 3;

    /**
     * Only an unfinished delivery accepts delivery (OutgoingEventStatus::accepting()). Failed is
     * terminal for automatic processing: a stale job or queue:retry never resumes it, only a
     * manual retry (RoutedDeliveryLifecycle::retry) reopens it. Applications may add owner restrictions.
     */
    public function acceptsDelivery(): bool
    {
        return $this->status instanceof OutgoingEventStatus && ! $this->status->isTerminal();
    }

    /**
     * Absolute recorded-attempt limit, the only business limit on a delivery.
     * Queue releases (busy lock, not yet due) are not attempts.
     */
    public function deliveryAttemptLimit(): int
    {
        return static::DEFAULT_ATTEMPT_LIMIT;
    }

    /** Finishes the delivery as Failed with the given reason; callers hold the row lock. */
    public function markFailed(string $error): void
    {
        $this->forceFill([
            'status' => OutgoingEventStatus::Failed, 'completed_at' => now(),
            'active_attempt_id' => null, 'last_error' => $error,
        ])->saveOrFail();
    }

    /** Closes attempts that never recorded a result; their delivery outcome is unknown. */
    public function interruptOpenAttempts(string $reason): void
    {
        $this->attempts()->whereNull('completed_at')->update([
            'status' => AttemptStatus::Interrupted->value, 'completed_at' => now(), 'error' => $reason,
        ]);
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
