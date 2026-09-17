<?php

namespace TrafficOps\ModelEvents\Codecs;

use InvalidArgumentException;
use TrafficOps\ModelEvents\Contracts\PayloadCodec;

final class TextCodec implements PayloadCodec
{
    public function encode(mixed $value): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('The text codec requires a string.');
        }

        return $value;
    }

    public function decode(string $value): string
    {
        return $value;
    }
}
