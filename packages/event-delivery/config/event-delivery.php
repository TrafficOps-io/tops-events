<?php

use TrafficOps\EventDelivery\Channels\DiscordAdapter;
use TrafficOps\EventDelivery\Channels\DiscordWebhookAdapter;
use TrafficOps\EventDelivery\Channels\SlackAdapter;
use TrafficOps\EventDelivery\Channels\SlackWebhookAdapter;
use TrafficOps\EventDelivery\Channels\TelegramAdapter;
use TrafficOps\EventDelivery\Channels\WebhookAdapter;

return [
    'http_timeout' => 30,
    'adapters' => [
        'webhook' => WebhookAdapter::class,
        'telegram' => TelegramAdapter::class,
        'slack' => SlackAdapter::class,
        'slack_webhook' => SlackWebhookAdapter::class,
        'discord' => DiscordAdapter::class,
        'discord_webhook' => DiscordWebhookAdapter::class,
    ],
];
