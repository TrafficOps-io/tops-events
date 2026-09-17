<?php

namespace TrafficOps\ModelEvents\DTO;

final readonly class Payload
{
    public function __construct(public string $format, public mixed $value) {}

    public static function json(mixed $value): self
    {
        return new self('json', $value);
    }

    public static function text(string $value): self
    {
        return new self('text', $value);
    }
}
