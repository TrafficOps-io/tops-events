<?php

namespace TrafficOps\EventDelivery\Channels;

use TrafficOps\EventDelivery\DTO\DeliveryTarget;
use TrafficOps\EventDelivery\Exceptions\PreparationFailed;

class SlackAdapter extends AbstractHttpAdapter
{
    public function configurationSchema(): array
    {
        return ['connection' => ['token' => ['type' => 'password', 'required' => true], 'recipient' => ['type' => 'text', 'label' => 'Default channel ID']], 'destination' => ['recipient' => ['type' => 'text'], 'message' => ['type' => 'textarea', 'required' => true], 'media_url' => ['type' => 'text']]];
    }

    protected function build(DeliveryTarget $target, string $eventId): array
    {
        [$message, $recipient, $token] = $this->requireMessage($target, 40000);
        if (! preg_match('/^[A-Z0-9]+$/D', $recipient)) {
            throw new PreparationFailed('Slack channel ID is invalid.');
        }

        return ['url' => 'https://slack.com/api/chat.postMessage', 'method' => 'POST', 'headers' => ['Authorization' => 'Bearer '.$token], 'json' => ['channel' => $recipient, 'text' => $message] + $this->imagePayload($target, 'slack'), 'destination' => 'slack:'.$recipient];
    }
}
