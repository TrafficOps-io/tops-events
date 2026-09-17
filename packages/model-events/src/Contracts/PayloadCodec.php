<?php

namespace TrafficOps\ModelEvents\Contracts;

interface PayloadCodec
{
    public function encode(mixed $value): string;

    public function decode(string $value): mixed;
}
