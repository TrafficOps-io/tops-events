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
}
