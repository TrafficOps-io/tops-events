<?php

namespace TrafficOps\ModelEvents\DTO;

use DateTimeInterface;
use TrafficOps\ModelEvents\Enums\IncomingEventStatus;

final readonly class IncomingEventData
{
    public function __construct(
        public string $name,
        public Payload $payload,
        public IncomingEventStatus $status,
        public ?Payload $details = null,
        public array $metadata = [],
        public ?DateTimeInterface $receivedAt = null,
    ) {}
}
