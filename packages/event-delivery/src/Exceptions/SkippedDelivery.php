<?php

namespace TrafficOps\EventDelivery\Exceptions;

/**
 * @deprecated Use TrafficOps\ModelEvents\Exceptions\SkippedDelivery. This name is an alias of
 *             that single class (a PermanentDeliveryFailure, no longer a PreparationFailed) and
 *             will be removed in the next release. EventDeliveryServiceProvider registers the
 *             alias at boot so catch blocks using this name keep matching.
 */
class_alias(\TrafficOps\ModelEvents\Exceptions\SkippedDelivery::class, SkippedDelivery::class);
