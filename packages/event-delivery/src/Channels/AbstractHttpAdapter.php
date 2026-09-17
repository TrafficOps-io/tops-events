<?php

namespace TrafficOps\EventDelivery\Channels;

use Throwable;
use TrafficOps\EventDelivery\Contracts\ChannelAdapter;
use TrafficOps\EventDelivery\DTO\DeliveryTarget;
use TrafficOps\EventDelivery\Exceptions\PreparationFailed;
use TrafficOps\EventDelivery\Support\DeliveryCapture;
use TrafficOps\EventDelivery\Support\HttpClientFactory;
use TrafficOps\EventDelivery\Support\MediaUrl;
use TrafficOps\EventDelivery\Support\PublicUrlPolicy;
use TrafficOps\ModelEvents\Codecs\EncryptedJsonCodec;
use TrafficOps\ModelEvents\DTO\DeliveryResult;
use TrafficOps\ModelEvents\DTO\PreparedDelivery;

abstract class AbstractHttpAdapter implements ChannelAdapter
{
    public function prepare(DeliveryTarget $target, string $eventId): PreparedDelivery
    {
        $data = $this->build($target, $eventId);
        app(PublicUrlPolicy::class)->resolve($data['url']);

        return new PreparedDelivery(EncryptedJsonCodec::payload($data), $data['destination'], ['type' => $target->type, 'event_id' => $eventId]);
    }

    public function send(PreparedDelivery $delivery): DeliveryResult
    {
        $data = $delivery->payload->value;
        $capture = new DeliveryCapture;
        $error = null;
        try {
            $endpoint = app(PublicUrlPolicy::class)->resolve($data['url']);
            $options = ['headers' => $data['headers'] ?? [], 'query' => $data['query'] ?? [], 'http_errors' => false];
            if (array_key_exists('body', $data)) {
                $options['body'] = $data['body'];
            }
            if (array_key_exists('json', $data)) {
                $options['json'] = $data['json'];
            }
            app(HttpClientFactory::class)->make($capture, $endpoint)->request($data['method'], $data['url'], $options);
        } catch (Throwable $exception) {
            $error = $exception;
        }
        $response = $capture->response;
        $status = $response['status'] ?? null;
        $json = $response ? json_decode($response['body'], true) : null;
        $successful = $error === null && $status !== null && $status >= 200 && $status < 300 && $this->providerAccepted($json, $response);
        $retryable = ! $successful && ($status === null || in_array($status, [408, 429], true) || $status >= 500);
        $retryAfter = 60;
        foreach ($response['headers'] ?? [] as $name => $values) {
            if (strtolower($name) === 'retry-after') {
                $value = $values[0] ?? '';
                $retryAfter = max($retryAfter, ctype_digit($value) ? (int) $value : max(0, (strtotime($value) ?: time()) - time()));
            }
        }
        $retryAfter = max($retryAfter, (int) ($json['parameters']['retry_after'] ?? $json['retry_after'] ?? 0));

        return new DeliveryResult($successful, $retryable,
            $response ? EncryptedJsonCodec::payload($response) : null,
            ['http_status' => $status, 'retry_after' => $retryAfter],
            $successful ? null : ($error ? 'Unable to obtain a provider response.' : 'Provider rejected the delivery'.($status ? ' (HTTP '.$status.').' : '.')),
        );
    }

    protected function providerAccepted(?array $json, array $response): bool
    {
        return ! is_array($json) || ($json['ok'] ?? true) !== false;
    }

    abstract protected function build(DeliveryTarget $target, string $eventId): array;

    protected function requireMessage(DeliveryTarget $target, int $limit): array
    {
        $message = $target->value('message');
        $recipient = $target->recipient ?? ($target->connection['recipient'] ?? null);
        $token = $target->connection['token'] ?? null;
        if (! is_string($message) || $message === '' || mb_strlen($message) > $limit || ! is_string($recipient) || $recipient === '' || ! is_string($token) || $token === '') {
            throw new PreparationFailed('Message, recipient and connection token are required.');
        }

        return [$message, $recipient, $token];
    }

    protected function mediaUrl(DeliveryTarget $target): ?string
    {
        $url = $target->value('media_url', '');
        if ($url === '') {
            return null;
        }
        if (! MediaUrl::valid($url)) {
            throw new PreparationFailed('Media must be an HTTP or HTTPS URL.');
        }

        return $url;
    }

    protected function imagePayload(DeliveryTarget $target, string $provider): array
    {
        $url = $this->mediaUrl($target);
        if ($url === null) {
            return [];
        }
        if ($target->value('media_type', 'photo') !== 'photo') {
            throw new PreparationFailed('This connection supports image URLs only.');
        }

        return $provider === 'slack'
            ? ['attachments' => [['image_url' => $url, 'fallback' => $target->value('message')]]]
            : ['embeds' => [['image' => ['url' => $url]]]];
    }
}
