---
status: accepted
---

# Deliveries are at-least-once; duplicates in messengers are accepted

A delivery attempt interrupted by a crash is retried, and only the webhook
channel carries an idempotency key (the delivery id). Telegram, Slack and
Discord therefore may receive the same message twice. We accept this rather
than build exactly-once machinery: the cost of a duplicate chat message is low,
while losing a notification is not, and the provider APIs offer no idempotency
primitive to lean on. A manual retry is the same delivery and keeps the same
idempotency key, so a deduplicating webhook receiver treats it as the same
message.
