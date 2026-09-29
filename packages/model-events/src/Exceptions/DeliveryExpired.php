<?php

namespace TrafficOps\ModelEvents\Exceptions;

use RuntimeException;
use Throwable;
use TrafficOps\ModelEvents\Models\OutgoingEvent;

/** The delivery was still unfinished when its expiry horizon passed; recorded as last_error = expired. */
final class DeliveryExpired extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(OutgoingEvent::ERROR_EXPIRED, 0, $previous);
    }
}
