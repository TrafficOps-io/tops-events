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
        'tries' => 3,
        'backoff' => 60,
        'timeout' => 60,
    ],
    // Must be shared between workers. TTL must exceed the job timeout.
    'lock' => ['store' => null, 'seconds' => 120, 'release_after' => 5],
    'retention' => ['incoming_days' => 30, 'outgoing_days' => 30, 'batch_size' => 1000],
];
