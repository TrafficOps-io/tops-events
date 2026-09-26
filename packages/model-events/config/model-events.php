<?php

use TrafficOps\ModelEvents\Codecs\EncryptedJsonCodec;
use TrafficOps\ModelEvents\Codecs\JsonCodec;
use TrafficOps\ModelEvents\Codecs\TextCodec;
use TrafficOps\ModelEvents\Models\IncomingEvent;
use TrafficOps\ModelEvents\Models\OutgoingEvent;
use TrafficOps\ModelEvents\Models\OutgoingEventAttempt;

return [
    'models' => [
        'incoming' => IncomingEvent::class,
        'outgoing' => OutgoingEvent::class,
        'attempt' => OutgoingEventAttempt::class,
    ],
    'codecs' => ['json' => JsonCodec::class, 'text' => TextCodec::class, 'encrypted-json.v1' => EncryptedJsonCodec::class],
    'migrations' => true,
    'queue' => [
        'connection' => null,
        'queue' => null,
        // 0 = unlimited queue attempts. A job released because the event lock is busy or the
        // delivery is not due yet is a wait, not an attempt, and must never fail the delivery.
        // The only business limit is the recorded-attempt budget: OutgoingEvent::deliveryAttemptLimit()
        // (OutgoingEvent::DEFAULT_ATTEMPT_LIMIT = 3 unless overridden). Setting tries > 0 makes
        // waits count again and is discouraged.
        'tries' => 0,
        'backoff' => 60,
        'timeout' => 60,
    ],
    // Must be shared between workers. TTL must exceed the job timeout.
    'lock' => ['store' => null, 'seconds' => 120, 'release_after' => 5],
    // expire_days: a delivery still unfinished (pending/queued/processing/retrying) this many days
    // after it was created, or after its scheduled_at if that is later, is failed with
    // last_error = OutgoingEvent::ERROR_EXPIRED so an incoming event never outlives its deliveries.
    // The expired row is a finished delivery and stays visible for one more outgoing_days window.
    // The same window bounds the queue job (retryUntil), so a never-due delivery cannot wait forever.
    // null = same as outgoing_days; when that is null too, OutgoingEvent::DEFAULT_EXPIRY_DAYS (30) applies.
    'retention' => ['incoming_days' => 30, 'outgoing_days' => 30, 'expire_days' => null, 'batch_size' => 1000],
];
