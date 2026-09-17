<?php

namespace TrafficOps\EventDelivery\Channels;

use TrafficOps\EventDelivery\DTO\DeliveryTarget;
use TrafficOps\EventDelivery\Exceptions\PreparationFailed;

class WebhookAdapter extends AbstractHttpAdapter
{
    public function configurationSchema(): array
    {
        return ['connection' => ['url' => ['type' => 'password', 'required' => true]], 'destination' => ['method' => ['type' => 'select', 'options' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']], 'headers' => ['type' => 'json'], 'query' => ['type' => 'json'], 'body' => ['type' => 'json_value']]];
    }

    protected function build(DeliveryTarget $target, string $eventId): array
    {
        $url = $target->connection['url'] ?? '';
        $method = $target->value('method', 'POST');
        if (! is_string($method) || ! in_array($method = strtoupper($method), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw new PreparationFailed('Webhook method is invalid.');
        }
        $headers = $target->value('headers', []);
        $query = $target->value('query', []);
        if (! is_array($headers) || ! is_array($query)) {
            throw new PreparationFailed('Webhook headers and query must be objects.');
        }
        foreach ($headers as $name => $value) {
            if (! is_string($name) || ! preg_match('/^[!#$%&\x27*+.^_`|~0-9a-zA-Z-]+$/D', $name) || in_array(strtolower($name), ['host', 'content-length', 'transfer-encoding', 'connection', 'idempotency-key'], true) || str_contains((string) $value, "\r") || str_contains((string) $value, "\n")) {
                throw new PreparationFailed('Webhook header is invalid.');
            }
        }
        $headers['Idempotency-Key'] = $eventId;

        return ['url' => $url, 'method' => $method, 'headers' => $headers, 'query' => $query, 'json' => $target->value('body'), 'destination' => 'webhook:'.$url];
    }
}
