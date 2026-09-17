<?php

namespace TrafficOps\ModelEvents\Enums;

enum IncomingEventStatus: string
{
    case Ok = 'ok';
    case ValidationFailed = 'validation_failed';
    case Error = 'error';
}
