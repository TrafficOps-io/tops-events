<?php

namespace TrafficOps\ModelEvents\Exceptions;

/**
 * Thrown from prepare() when a guard rejects the delivery before sending.
 * The delivery finishes as Skipped: terminal, not a failure, no failure alerts.
 */
class SkippedDelivery extends PermanentDeliveryFailure {}
