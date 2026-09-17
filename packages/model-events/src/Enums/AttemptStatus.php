<?php

namespace TrafficOps\ModelEvents\Enums;

enum AttemptStatus: string
{
    case Preparing = 'preparing';
    case Sending = 'sending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Interrupted = 'interrupted';
}
