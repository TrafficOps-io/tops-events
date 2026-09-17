<?php

namespace TrafficOps\ModelEvents\DTO;

final readonly class DeliveryResult
{
    public function __construct(
        public bool $successful,
        public bool $retryable = false,
        public ?Payload $response = null,
        public array $metadata = [],
        public ?string $error = null,
    ) {}
}
