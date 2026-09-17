<?php

namespace TrafficOps\ModelEvents\Models;

use TrafficOps\ModelEvents\Enums\OutgoingEventStatus;

/** Applications opting in must provide available_at and attempts_offset columns. */
class RoutedOutgoingEvent extends OutgoingEvent
{
    public function acceptsDelivery(): bool
    {
        return parent::acceptsDelivery() && $this->status !== OutgoingEventStatus::Failed;
    }

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
