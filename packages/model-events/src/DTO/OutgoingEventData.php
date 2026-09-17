<?php

namespace TrafficOps\ModelEvents\DTO;

use TrafficOps\ModelEvents\Models\IncomingEvent;

final readonly class OutgoingEventData
{
    public function __construct(
        public string $name,
        public Payload $payload,
        public string $destination,
        public ?IncomingEvent $incoming = null,
        public array $metadata = [],
    ) {}
}
