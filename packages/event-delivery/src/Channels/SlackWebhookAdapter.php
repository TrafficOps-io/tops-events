<?php

namespace TrafficOps\EventDelivery\Channels;

use TrafficOps\EventDelivery\DTO\DeliveryTarget;
use TrafficOps\EventDelivery\Exceptions\PreparationFailed;

class SlackWebhookAdapter extends AbstractHttpAdapter
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
            && in_array(strtolower($parts['host'] ?? ''), ['hooks.slack.com', 'hooks.slack-gov.com'], true)
            && ($parts['port'] ?? 443) === 443 && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['fragment'])
            && preg_match('~\A/services/[a-zA-Z0-9_-]+/[a-zA-Z0-9_-]+/[a-zA-Z0-9_-]+/?\z~', $parts['path'] ?? '');
        if (! is_string($message) || $message === '' || mb_strlen($message) > 40000 || ! $validUrl) {
            throw new PreparationFailed('Slack webhook is invalid.');
        }

        return ['url' => $url, 'method' => 'POST', 'json' => ['text' => $message] + $this->imagePayload($target, 'slack'), 'destination' => 'slack-webhook'];
    }

    protected function providerAccepted(?array $json, array $response): bool
    {
        return $response['status'] === 200 && trim($response['body']) === 'ok';
    }
}
