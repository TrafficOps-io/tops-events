<?php

namespace TrafficOps\ModelEvents;

use Illuminate\Support\ServiceProvider;
use TrafficOps\ModelEvents\Services\DeliveryService;
use TrafficOps\ModelEvents\Services\EventJournal;
use TrafficOps\ModelEvents\Services\OutgoingScheduler;
use TrafficOps\ModelEvents\Support\EventLock;
use TrafficOps\ModelEvents\Support\Payloads;

class ModelEventsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $defaults = require __DIR__.'/../config/model-events.php';
        $this->app['config']->set('model-events', array_replace_recursive(
            $defaults, $this->app['config']->get('model-events', []),
        ));
        foreach ([Payloads::class, EventLock::class, EventJournal::class, DeliveryService::class, OutgoingScheduler::class] as $service) {
            $this->app->singleton($service);
        }
    }

    public function boot(): void
    {
        if (config('model-events.migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
        $this->publishes([__DIR__.'/../config/model-events.php' => config_path('model-events.php')], 'model-events-config');
        $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'model-events-migrations');
    }
}
