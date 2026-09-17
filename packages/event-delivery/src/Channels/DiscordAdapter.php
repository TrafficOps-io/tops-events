<?php

namespace TrafficOps\EventDelivery\Channels;

use TrafficOps\EventDelivery\DTO\DeliveryTarget;
use TrafficOps\EventDelivery\Exceptions\PreparationFailed;

class DiscordAdapter extends AbstractHttpAdapter
{
    public function configurationSchema(): array
    {
        return ['connection' => ['token' => ['type' => 'password', 'required' => true], 'recipient' => ['type' => 'text', 'label' => 'Default channel ID']], 'destination' => ['recipient' => ['type' => 'text'], 'message' => ['type' => 'textarea', 'required' => true], 'media_url' => ['type' => 'text']]];
    }

    protected function build(DeliveryTarget $target, string $eventId): array
    {
        [$message, $recipient, $token] = $this->requireMessage($target, 2000);
        if (! ctype_digit($recipient)) {
            throw new PreparationFailed('Discord channel ID is invalid.');
        }

        return ['url' => 'https://discord.com/api/v10/channels/'.$recipient.'/messages', 'method' => 'POST', 'headers' => ['Authorization' => 'Bot '.$token], 'json' => ['content' => $message] + $this->imagePayload($target, 'discord'), 'destination' => 'discord:'.$recipient];
    }
}
