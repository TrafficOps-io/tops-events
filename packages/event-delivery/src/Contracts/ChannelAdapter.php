<?php

namespace TrafficOps\EventDelivery\Contracts;

use TrafficOps\EventDelivery\DTO\DeliveryTarget;
use TrafficOps\ModelEvents\DTO\DeliveryResult;
use TrafficOps\ModelEvents\DTO\PreparedDelivery;

interface ChannelAdapter
{
    public function configurationSchema(): array;

    public function prepare(DeliveryTarget $target, string $eventId): PreparedDelivery;

    public function send(PreparedDelivery $delivery): DeliveryResult;
}
