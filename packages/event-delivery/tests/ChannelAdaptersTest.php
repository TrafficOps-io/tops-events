<?php

namespace TrafficOps\EventDelivery\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use TrafficOps\EventDelivery\Channels\DiscordAdapter;
use TrafficOps\EventDelivery\Channels\DiscordWebhookAdapter;
use TrafficOps\EventDelivery\Channels\SlackAdapter;
use TrafficOps\EventDelivery\Channels\SlackWebhookAdapter;
use TrafficOps\EventDelivery\Channels\TelegramAdapter;
use TrafficOps\EventDelivery\Channels\WebhookAdapter;
use TrafficOps\EventDelivery\DTO\DeliveryTarget;
use TrafficOps\EventDelivery\Exceptions\PreparationFailed;
use TrafficOps\EventDelivery\Support\DnsResolver;
use TrafficOps\EventDelivery\Support\PublicUrlPolicy;

class ChannelAdaptersTest extends TestCase
{
    public function test_webhook_uses_the_outgoing_event_id_as_a_stable_idempotency_key(): void
    {
        $request = $this->build(new WebhookAdapter, [
            'connection' => ['url' => 'https://example.com/capture'],
            'method' => 'POST',
            'headers' => ['X-Campaign' => 'one'],
            'query' => [],
            'body' => ['amount' => 100],
        ], '01K5EVENT00000000000000000');

        $this->assertSame('01K5EVENT00000000000000000', $request['headers']['Idempotency-Key']);
        $this->assertSame(['amount' => 100], $request['json']);
    }

    #[DataProvider('invalidProviderUrls')]
    public function test_provider_webhooks_reject_urls_outside_the_exact_provider_shape(object $adapter, string $url): void
    {
        $this->expectException(PreparationFailed::class);
        $this->build($adapter, ['connection' => ['url' => $url], 'message' => 'hello'], 'event-id');
    }

    public static function invalidProviderUrls(): array
    {
        return [
            [new SlackWebhookAdapter, 'http://hooks.slack.com/services/T/B/secret'],
            [new SlackWebhookAdapter, 'https://hooks.slack.com.evil.test/services/T/B/secret'],
            [new SlackWebhookAdapter, 'https://hooks.slack.com/services/T/B/secret/extra'],
            [new DiscordWebhookAdapter, 'https://discord.com/api/webhooks/123/secret/messages/456'],
            [new DiscordWebhookAdapter, 'https://discord.com@127.0.0.1/api/webhooks/123/secret'],
            [new DiscordWebhookAdapter, 'https://discord.com:8080/api/webhooks/123/secret'],
        ];
    }

    #[DataProvider('botRecipients')]
    public function test_bot_recipient_defaults_and_overrides(object $adapter, string $default, string $override, string $field): void
    {
        $target = ['connection' => ['token' => 'secret', 'recipient' => $default], 'message' => 'Hello'];
        $request = $this->build($adapter, $target, 'event');
        if ($field === 'url') {
            $this->assertStringContainsString('/channels/'.$default.'/messages', $request['url']);
        } else {
            $this->assertSame($default, $request['json'][$field]);
        }
        $request = $this->build($adapter, [...$target, 'recipient' => $override], 'event');
        if ($field === 'url') {
            $this->assertStringContainsString('/channels/'.$override.'/messages', $request['url']);
        } else {
            $this->assertSame($override, $request['json'][$field]);
        }
    }

    public static function botRecipients(): array
    {
        return [
            [new TelegramAdapter, '-100123', '@override', 'chat_id'],
            [new SlackAdapter, 'C123', 'C456', 'channel'],
            [new DiscordAdapter, '123', '456', 'url'],
        ];
    }

    #[DataProvider('telegramMedia')]
    public function test_telegram_media_uses_caption_parse_mode_and_correct_method(string $type, string $mode): void
    {
        $request = $this->build(new TelegramAdapter, [
            'connection' => ['token' => 'secret', 'recipient' => '-100123'], 'message' => 'Hello',
            'parse_mode' => $mode, 'media_url' => 'https://example.com/file', 'media_type' => $type,
        ], 'event');
        $this->assertStringEndsWith('/send'.ucfirst($type), $request['url']);
        $this->assertSame(['chat_id' => '-100123', $type => 'https://example.com/file', 'caption' => 'Hello', 'parse_mode' => $mode], $request['json']);
    }

    public static function telegramMedia(): array
    {
        $cases = [];
        foreach (['photo', 'video', 'animation', 'audio', 'document'] as $type) {
            foreach (['', 'HTML', 'MarkdownV2'] as $mode) {
                $cases[] = [$type, $mode];
            }
        }

        return $cases;
    }

    #[DataProvider('imageConnections')]
    public function test_slack_and_discord_attach_images_without_changing_the_message(object $adapter, array $connection, string $provider): void
    {
        $request = $this->build($adapter, [
            'connection' => $connection, 'message' => 'Hello', 'media_url' => 'https://example.com/image.jpg',
        ], 'event');
        $this->assertSame('Hello', $request['json'][$provider === 'slack' ? 'text' : 'content']);
        $this->assertSame('https://example.com/image.jpg', $provider === 'slack'
            ? $request['json']['attachments'][0]['image_url'] : $request['json']['embeds'][0]['image']['url']);
    }

    public static function imageConnections(): array
    {
        return [
            [new SlackAdapter, ['token' => 'secret', 'recipient' => 'C123'], 'slack'],
            [new DiscordAdapter, ['token' => 'secret', 'recipient' => '123'], 'discord'],
            [new SlackWebhookAdapter, ['url' => 'https://hooks.slack.com/services/T/B/secret'], 'slack'],
            [new DiscordWebhookAdapter, ['url' => 'https://discord.com/api/webhooks/123/secret'], 'discord'],
        ];
    }

    #[DataProvider('invalidMedia')]
    public function test_invalid_media_and_telegram_options_are_rejected(array $values): void
    {
        $this->expectException(PreparationFailed::class);
        $this->build(new TelegramAdapter, [
            'connection' => ['token' => 'secret', 'recipient' => '-100123'], 'message' => 'Hello', ...$values,
        ], 'event');
    }

    public static function invalidMedia(): array
    {
        return [
            [['media_url' => 'javascript:alert(1)']], [['media_url' => 'file:///etc/passwd']],
            [['media_url' => 'https://user:password@example.com/file']], [['media_url' => ['invalid']]],
            [['media_url' => 'https://example.com/file', 'media_type' => 'unknown']],
            [['media_url' => 'https://example.com/file', 'message' => str_repeat('x', 1025)]],
            [['parse_mode' => 'unknown']], [['recipient' => '']],
        ];
    }

    public function test_telegram_without_media_keeps_the_text_message_limit_and_endpoint(): void
    {
        $message = str_repeat('x', 4096);
        $request = $this->build(new TelegramAdapter, ['connection' => ['token' => 'secret'], 'recipient' => '@chat', 'message' => $message], 'event');
        $this->assertStringEndsWith('/sendMessage', $request['url']);
        $this->assertSame($message, $request['json']['text']);
        $this->assertArrayNotHasKey('caption', $request['json']);
    }

    public function test_package_source_has_no_application_namespace_dependency(): void
    {
        $source = '';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../src'));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $source .= file_get_contents($file->getPathname());
            }
        }

        $this->assertStringNotContainsString('App\\', $source);
        $this->assertCount(6, (require __DIR__.'/../config/event-delivery.php')['adapters']);
    }

    public function test_url_policy_rejects_any_private_dns_answer_and_returns_a_public_pin(): void
    {
        $private = new PublicUrlPolicy(new class extends DnsResolver
        {
            public function resolve(string $host): array
            {
                return ['93.184.216.34', '127.0.0.1'];
            }
        });
        try {
            $private->resolve('https://example.com/hook');
            $this->fail('A mixed public/private DNS answer must be rejected.');
        } catch (PreparationFailed) {
            $this->addToAssertionCount(1);
        }

        $public = new PublicUrlPolicy(new class extends DnsResolver
        {
            public function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
        $this->assertSame(['host' => 'example.com', 'port' => 443, 'ip' => '93.184.216.34'], $public->resolve('https://example.com/hook'));
    }

    private function build(object $adapter, array $target, string $eventId): array
    {
        return (new ReflectionMethod($adapter, 'build'))->invoke($adapter, DeliveryTarget::fromArray(['type' => 'test', ...$target]), $eventId);
    }
}
