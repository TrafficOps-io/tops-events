<?php

namespace TrafficOps\EventDelivery\Channels;

use TrafficOps\EventDelivery\Contracts\ChannelAdapter;
use TrafficOps\EventDelivery\Exceptions\PreparationFailed;

class ChannelRegistry
{
    public function get(string $type): ChannelAdapter
    {
        $class = config('event-delivery.adapters')[$type] ?? null;
        if (! $class || ! is_a($class, ChannelAdapter::class, true)) {
            throw new PreparationFailed('This channel type is unavailable.');
        }

        return app($class);
    }

    public function schemas(): array
    {
        return collect(config('event-delivery.adapters'))->mapWithKeys(fn ($class, $type) => [$type => $this->get($type)->configurationSchema()])->all();
    }
}
