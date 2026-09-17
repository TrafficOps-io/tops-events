<?php

namespace TrafficOps\ModelEvents\Tests\Fixtures;

use TrafficOps\ModelEvents\DTO\DeliveryResult;
use TrafficOps\ModelEvents\DTO\Payload;
use TrafficOps\ModelEvents\DTO\PreparedDelivery;
use TrafficOps\ModelEvents\Jobs\SendOutgoingEventJob;
use TrafficOps\ModelEvents\Models\OutgoingEvent;

class TestSendJob extends SendOutgoingEventJob
{
    protected function prepare(OutgoingEvent $event): PreparedDelivery
    {
        return new PreparedDelivery(Payload::text('hello'), $event->destination);
    }

    protected function send(PreparedDelivery $delivery): DeliveryResult
    {
        return new DeliveryResult(true, response: Payload::json(['accepted' => true]));
    }
}
