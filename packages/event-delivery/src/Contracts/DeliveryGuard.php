<?php

namespace TrafficOps\EventDelivery\Contracts;

use TrafficOps\ModelEvents\Models\OutgoingEvent;

interface DeliveryGuard
{
    public function rejectionReason(OutgoingEvent $event): ?string;
}
