<?php

namespace TrafficOps\ModelEvents\Exceptions;

use DomainException;

/** A manual retry was requested for a delivery whose status forbids it (Succeeded). */
final class DeliveryNotRetryable extends DomainException {}
