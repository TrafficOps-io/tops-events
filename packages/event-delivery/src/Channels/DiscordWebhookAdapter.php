<?php

namespace TrafficOps\EventDelivery\Channels;

use TrafficOps\EventDelivery\DTO\DeliveryTarget;
use TrafficOps\EventDelivery\Exceptions\PreparationFailed;

class DiscordWebhookAdapter extends AbstractHttpAdapter
{
    public function configurationSchema(): array
    {
        return ['connection' => ['url' => ['type' => 'password', 'required' => true]], 'destination' => ['message' => ['type' => 'textarea', 'required' => true], 'media_url' => ['type' => 'text']]];
    }

    protected function build(DeliveryTarget $target, string $eventId): array
    {
        $url = $target->connection['url'] ?? '';
        $message = $target->value('message', '');
        $parts = is_string($url) ? parse_url($url) : false;
        $validUrl = $parts && ($parts['scheme'] ?? '') === 'https'
            && preg_match('/\A(?:(?:canary|ptb)\.)?discord(?:app)?\.com\z/', strtolower($parts['host'] ?? ''))
            && ($parts['port'] ?? 443) === 443 && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['fragment'])
            && preg_match('~\A/api/(?:v[0-9]+/)?webhooks/[0-9]+/[a-zA-Z0-9_.-]+/?\z~', $parts['path'] ?? '');
        if (! is_string($message) || $message === '' || mb_strlen($message) > 2000 || ! $validUrl) {
            throw new PreparationFailed('Discord webhook is invalid.');
        }
        $url .= (str_contains($url, '?') ? '&' : '?').'wait=true';

        return ['url' => $url, 'method' => 'POST', 'json' => ['content' => $message] + $this->imagePayload($target, 'discord'), 'destination' => 'discord-webhook'];
    }
}
