<?php

namespace TrafficOps\EventDelivery\DTO;

use TrafficOps\EventDelivery\Exceptions\PreparationFailed;

final readonly class DeliveryTarget
{
    public function __construct(
        public string $type,
        public array $connection,
        public ?string $recipient,
        public array $values,
    ) {}

    public static function fromArray(array $target): self
    {
        $type = $target['type'] ?? null;
        $connection = $target['connection'] ?? null;
        $recipient = $target['recipient'] ?? null;
        if (! is_string($type) || $type === '' || ! is_array($connection) || $recipient !== null && ! is_string($recipient)) {
            throw new PreparationFailed('The delivery target snapshot is invalid.');
        }

        return new self($type, $connection, $recipient, array_diff_key($target, array_flip(['type', 'connection', 'recipient'])));
    }

    public function value(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }
}
