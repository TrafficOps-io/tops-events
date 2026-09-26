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
}
