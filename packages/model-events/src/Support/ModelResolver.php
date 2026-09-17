<?php

namespace TrafficOps\ModelEvents\Support;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use TrafficOps\ModelEvents\Models\IncomingEvent;
use TrafficOps\ModelEvents\Models\OutgoingEvent;
use TrafficOps\ModelEvents\Models\OutgoingEventAttempt;

final class ModelResolver
{
    public static function class(string $name): string
    {
        $base = match ($name) {
            'incoming' => IncomingEvent::class,
            'outgoing' => OutgoingEvent::class,
            'attempt' => OutgoingEventAttempt::class,
            default => throw new InvalidArgumentException("Unknown event model [$name]."),
        };
        $class = config("model-events.models.$name");
        if (! is_string($class) || ! is_a($class, $base, true)) {
            throw new InvalidArgumentException("Event model [$name] must extend [$base].");
        }

        return $class;
    }

    public static function make(string $name): Model
    {
        $class = self::class($name);
        $model = new $class;
        // Related rows and transaction/cleanup locks must share one connection.
        foreach (['incoming', 'outgoing', 'attempt'] as $related) {
            $relatedClass = self::class($related);
            if ((new $relatedClass)->getConnection()->getName() !== $model->getConnection()->getName()) {
                throw new InvalidArgumentException('All event journal models must use the same database connection.');
            }
        }

        return $model;
    }
}
