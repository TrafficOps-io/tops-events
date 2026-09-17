<?php

namespace TrafficOps\EventDelivery\Channels;

use TrafficOps\EventDelivery\DTO\DeliveryTarget;
use TrafficOps\EventDelivery\Exceptions\PreparationFailed;

class TelegramAdapter extends AbstractHttpAdapter
{
    public function configurationSchema(): array
    {
        return [
            'connection' => [
                'token' => ['type' => 'password', 'required' => true],
                'recipient' => ['type' => 'text', 'label' => 'Default chat ID or @channel'],
            ],
            'destination' => [
                'recipient' => ['type' => 'text'],
                'message' => ['type' => 'textarea', 'required' => true],
                'parse_mode' => ['type' => 'select', 'options' => ['', 'HTML', 'MarkdownV2']],
                'media_url' => ['type' => 'text'],
                'media_type' => ['type' => 'select', 'options' => ['photo', 'video', 'animation', 'audio', 'document']],
            ],
        ];
    }

    protected function build(DeliveryTarget $target, string $eventId): array
    {
        $mediaUrl = $this->mediaUrl($target);
        [$message, $recipient, $token] = $this->requireMessage($target, $mediaUrl === null ? 4096 : 1024);
        $mode = $target->value('parse_mode', '');
        if (! preg_match('/^(?:-?\d+|@[a-zA-Z0-9_]+)$/D', $recipient) || ! in_array($mode, ['', 'HTML', 'MarkdownV2'], true)) {
            throw new PreparationFailed('Telegram destination is invalid.');
        }

        $method = 'sendMessage';
        $payload = ['chat_id' => $recipient, 'text' => $message, 'parse_mode' => $mode];
        if ($mediaUrl !== null) {
            $mediaType = $target->value('media_type', 'photo');
            if (! in_array($mediaType, ['photo', 'video', 'animation', 'audio', 'document'], true)) {
                throw new PreparationFailed('Telegram media type is invalid.');
            }
            $method = 'send'.ucfirst($mediaType);
            $payload = ['chat_id' => $recipient, $mediaType => $mediaUrl, 'caption' => $message, 'parse_mode' => $mode];
        }

        return ['url' => 'https://api.telegram.org/bot'.$token.'/'.$method, 'method' => 'POST', 'json' => $payload, 'destination' => 'telegram:'.$recipient];
    }
}
