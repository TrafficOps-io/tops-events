<?php

namespace TrafficOps\ModelEvents\Support;

use Closure;
use Illuminate\Contracts\Cache\Factory;
use InvalidArgumentException;
use TrafficOps\ModelEvents\Exceptions\EventBusy;

final class EventLock
{
    private array $held = [];

    public function __construct(private Factory $cache) {}

    public function run(string $id, Closure $callback, bool $allowOwned = false): mixed
    {
        $seconds = config('model-events.lock.seconds');
        if (! is_int($seconds) || $seconds < 1) {
            throw new InvalidArgumentException('Event lock seconds must be a positive integer.');
        }
        $model = ModelResolver::make('outgoing');
        $key = hash('sha256', $model->getConnection()->getName().':'.$model->getTable().':'.$id);
        // Laravel invokes failed() inside a worker timeout signal, before finally runs.
        if ($allowOwned && isset($this->held[$key]) && $this->held[$key]->isOwnedByCurrentProcess()) {
            return $callback();
        }
        $lock = $this->cache->store(config('model-events.lock.store'))->lock('model-events:'.$key, $seconds);
        if (! $lock->get()) {
            throw new EventBusy("Outgoing event [$id] is being processed.");
        }
        $this->held[$key] = $lock;

        try {
            return $callback();
        } finally {
            unset($this->held[$key]);
            $lock->release();
        }
    }
}
