<?php

namespace TrafficOps\ModelEvents\Support;

use InvalidArgumentException;
use TrafficOps\ModelEvents\Contracts\PayloadCodec;
use TrafficOps\ModelEvents\DTO\Payload;

final class Payloads
{
    public function codec(string $format): PayloadCodec
    {
        // Array lookup permits versioned identifiers containing dots.
        $class = config('model-events.codecs', [])[$format] ?? null;
        if (! is_string($class) || ! is_a($class, PayloadCodec::class, true)) {
            throw new InvalidArgumentException("Unknown or invalid payload codec [$format].");
        }

        return app($class);
    }

    public function attributes(string $field, ?Payload $payload): array
    {
        return [
            $field => $payload === null ? null : $this->codec($payload->format)->encode($payload->value),
            $field.'_format' => $payload?->format,
        ];
    }
}
