# tops-events

Event catalogs, an Eloquent journal of incoming events and deliveries, and
outbound channel adapters, shared by webhooks-gateway (hookroute) and pwapps.
The package records and delivers; deciding what to deliver where (routing) is
the consuming application's job.

## Language

### Events

**Owner**:
The application model (a campaign, a gateway) that owns incoming events,
deliveries and connections. One concept across the catalog, journal and
delivery modules.
_Avoid_: tenant, account, authorized owner

**Event definition**:
A catalog entry: an event name, its kind and the payload fields known for it.
Used for editor suggestions and validation, never persisted by the package.
_Avoid_: event (unqualified), schema

**Custom event**:
An event received from outside the platform.

**System event**:
An event emitted by the platform itself, such as `delivery_failed`. System
event names are reserved: an incoming event carrying one is rejected.
_Avoid_: internal event, platform event

**Incoming event**:
An event received by an owner, journaled as it arrived together with the
outcome of validation.
_Avoid_: inbound event, request

**Field**:
A payload path with a source (body, query, headers) and a type, discovered from
payloads or configured by the owner.
_Avoid_: attribute, property

**Route**:
An application-owned rule that decides which incoming events produce deliveries
to which targets. Not defined by this package.
_Avoid_: rule, routing rule (in package code)

### Deliveries

**Delivery**:
One message to one recipient, produced from an incoming event by a route.
Implemented by `OutgoingEvent`, but spoken of as a delivery.
_Avoid_: outgoing event, message, notification

**Channel**:
A way of delivering: telegram, slack, discord, webhook.
_Avoid_: adapter, provider, type

**Connection**:
A stored credential for a channel, owned by an owner.
_Avoid_: integration, account

**Recipient**:
The address inside a channel: a chat id, a channel id, a webhook URL.
_Avoid_: destination, channel (for Slack/Discord channels)

**Target**:
The snapshot of channel, connection, recipient and content frozen when a
delivery is created. Later changes to the connection do not affect it.
_Avoid_: destination

**Attempt**:
One recorded try to prepare and send a delivery. Queue retries that never reach
sending (lock busy, not yet due) are not attempts and do not consume the
attempt budget. A run allows three attempts.

**Succeeded** (delivery status):
The provider accepted the delivery. Whether the recipient received it is
unknown.
_Avoid_: delivered, sent

**Failed** (delivery status):
Terminal for automatic processing. Only a manual retry reopens a failed
delivery.

**Skipped** (delivery status):
The delivery was rejected by a guard before sending. Terminal and not a
failure: it raises no failure alerts.
_Avoid_: failed with disposition

**Retry**:
A manual re-run of a failed delivery with a fresh attempt budget. It is the same
delivery, so it carries the same idempotency key. A succeeded delivery cannot be
retried.
_Avoid_: resend, re-queue

**Retention**:
How long finished journal entries are kept: 30 days after completion by default,
set by the application. A delivery still unfinished at the end of its retention
is failed as expired, so an incoming event never outlives its deliveries.
