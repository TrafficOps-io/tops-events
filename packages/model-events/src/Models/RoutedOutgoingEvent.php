<?php

namespace TrafficOps\ModelEvents\Models;

/** Applications opting in must provide available_at and attempts_offset columns. */
class RoutedOutgoingEvent extends OutgoingEvent
{
    public function deliveryAttemptsPerRun(): int
    {
        return 3;
    }

    public function deliveryAttemptLimit(): ?int
    {
        return (int) $this->attempts_offset + $this->deliveryAttemptsPerRun();
    }

    protected function casts(): array
    {
        return [...parent::casts(), 'available_at' => 'immutable_datetime', 'attempts_offset' => 'integer'];
    }
}
