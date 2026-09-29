# Event Delivery

Headless Laravel package for outbound Telegram, Slack, Slack incoming webhook, Discord, Discord incoming webhook, and generic webhook delivery.

Applications pass an immutable, already-rendered `DeliveryTarget` to a channel adapter. The package validates provider settings, resolves DNS before preparation and again immediately before transport, pins the selected public address, disables redirects and proxies, and returns a normalized `DeliveryResult`. Generic webhooks use the outgoing event ID as a stable `Idempotency-Key`.

The package supplies a polymorphic `Connection` base model with encrypted configuration and a `DeliveryGuard` contract for application ownership and lifecycle checks. When the guard returns a rejection reason, `DeliverEvent` finishes the delivery as `OutgoingEventStatus::Skipped` with `last_error` set to the reason: terminal, not a failure, and never routed as `delivery_failed`. The previous `metadata.disposition = skipped` / `metadata.skip_reason` mirror is deprecated and will be removed in the next release; read `status` and `last_error` instead.

The skip is signalled by `TrafficOps\ModelEvents\Exceptions\SkippedDelivery`, a `PermanentDeliveryFailure`. `TrafficOps\EventDelivery\Exceptions\SkippedDelivery` is now a deprecated `class_alias` of that class (registered by `EventDeliveryServiceProvider`) and will be removed in the next release. It no longer extends `PreparationFailed`, so a `catch (PreparationFailed $e)` block does not see skips any more; catch `SkippedDelivery` or `PermanentDeliveryFailure` instead. Template rendering, event dictionaries, recipient selection, and application models remain in the consuming application. Web Push is intentionally excluded until its subscription storage and invalidation behavior have a package contract.

Bot adapters use the explicit `recipient` or fall back to `connection.recipient` when it is absent. Optional `media_url` accepts an HTTP(S) URL: Telegram selects `sendPhoto`, `sendVideo`, `sendAnimation`, `sendAudio` or `sendDocument` using `media_type` (default `photo`) and sends the message as `caption`; Slack/Discord bot and webhook adapters attach an image. Telegram captions are limited to 1024 characters; regular text retains its 4096-character limit. The caller renders macros before preparation. Provider servers fetch media directly; this package does not download files.

Provider contracts: [Telegram media methods](https://core.telegram.org/bots/api#sendphoto), [Slack image attachments](https://docs.slack.dev/legacy/legacy-messaging/legacy-secondary-message-attachments/), and [Discord message embeds](https://docs.discord.com/developers/resources/message#embed-object).

Run the checks from the repository root:

```sh
composer check
```
