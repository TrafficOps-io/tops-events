<?php

namespace TrafficOps\ModelEvents\Exceptions;

/** A preparation failure that cannot be resolved by retrying the same snapshot. */
class PermanentDeliveryFailure extends \RuntimeException {}
