<?php

namespace TrafficOps\EventCatalog\Exceptions;

use DomainException;

/**
 * An incoming custom event carried a reserved system event name. This is a hard
 * validation failure: journal the event as validation_failed with errors() as its
 * details and produce no deliveries.
 */
final class ReservedEventName extends DomainException
{
    public const MESSAGE = 'System event names are reserved.';

    public function __construct(public readonly string $name, public readonly string $field)
    {
        parent::__construct(sprintf('%s [%s] is a reserved system event name.', $field, $name));
    }

    /** Same shape as the errors returned by resolve() and FieldValidator::errors(). */
    public function errors(): array
    {
        return [$this->field => [self::MESSAGE]];
    }
}
