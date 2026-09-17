<?php

namespace TrafficOps\ModelEvents\DTO;

final readonly class PreparedDelivery
{
    public function __construct(
        public Payload $payload,
        public string $destination,
        public array $metadata = [],
    ) {}
}
