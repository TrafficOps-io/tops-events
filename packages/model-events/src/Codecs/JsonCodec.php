<?php

namespace TrafficOps\ModelEvents\Codecs;

use TrafficOps\ModelEvents\Contracts\PayloadCodec;

final class JsonCodec implements PayloadCodec
{
    public function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    public function decode(string $value): mixed
    {
        return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }
}
