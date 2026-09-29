<?php

namespace TrafficOps\EventDelivery;

use Illuminate\Support\ServiceProvider;
use TrafficOps\EventDelivery\Channels\ChannelRegistry;
use TrafficOps\EventDelivery\Exceptions\SkippedDelivery;

class EventDeliveryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $defaults = require __DIR__.'/../config/event-delivery.php';
        $this->app['config']->set('event-delivery', array_replace_recursive($defaults, $this->app['config']->get('event-delivery', [])));
        $this->app->singleton(ChannelRegistry::class);
        // catch blocks never autoload: register the deprecated alias up front.
        class_exists(SkippedDelivery::class);
    }
}
