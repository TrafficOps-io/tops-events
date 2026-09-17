<?php

namespace TrafficOps\EventDelivery;

use Illuminate\Support\ServiceProvider;
use TrafficOps\EventDelivery\Channels\ChannelRegistry;

class EventDeliveryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $defaults = require __DIR__.'/../config/event-delivery.php';
        $this->app['config']->set('event-delivery', array_replace_recursive($defaults, $this->app['config']->get('event-delivery', [])));
        $this->app->singleton(ChannelRegistry::class);
    }
}
