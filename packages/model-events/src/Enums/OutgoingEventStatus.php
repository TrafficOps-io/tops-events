<?php

namespace TrafficOps\ModelEvents\Enums;

enum OutgoingEventStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Retrying = 'retrying';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /** Rejected by a guard before sending. Terminal and not a failure. */
    case Skipped = 'skipped';

    /** Succeeded, Failed and Skipped are terminal: automatic processing never leaves them. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Skipped], true);
    }

    /**
     * Statuses a delivery may still be sent from.
     *
     * @return list<self>
     */
    public static function accepting(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status) => ! $status->isTerminal()));
    }

    /** @return list<self> */
    public static function terminal(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status) => $status->isTerminal()));
    }

    /** @return list<string> */
    public static function values(self ...$statuses): array
    {
        return array_map(fn (self $status) => $status->value, $statuses);
    }
}
