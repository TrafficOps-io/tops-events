<?php

namespace TrafficOps\ModelEvents\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use TrafficOps\ModelEvents\Contracts\PayloadCodec;
use TrafficOps\ModelEvents\DTO\IncomingEventData;
use TrafficOps\ModelEvents\DTO\OutgoingEventData;
use TrafficOps\ModelEvents\DTO\Payload;
use TrafficOps\ModelEvents\Enums\IncomingEventStatus;
use TrafficOps\ModelEvents\Models\IncomingEvent;
use TrafficOps\ModelEvents\Models\OutgoingEvent;
use TrafficOps\ModelEvents\Support\Payloads;
use TrafficOps\ModelEvents\Tests\Fixtures\TestOwner;
use TrafficOps\ModelEvents\Tests\TestCase;
use TrafficOps\ModelEvents\Traits\IncomingEvents;
use TrafficOps\ModelEvents\Traits\OutgoingEvents;

class JournalTest extends TestCase
{
    public function test_only_explicit_calls_log_and_statuses_preserve_invalid_payload(): void
    {
        $owner = $this->owner();
        $owner->update(['name' => 'renamed']);
        $this->assertSame(0, IncomingEvent::query()->count());
        $this->assertSame(0, OutgoingEvent::query()->count());
        foreach (IncomingEventStatus::cases() as $status) {
            $event = $owner->logIncomingEvent(new IncomingEventData(
                'received', Payload::json(['quantity' => 'invalid']), $status,
                details: Payload::json(['quantity' => ['Must be an integer.']]),
            ));
            $this->assertSame($status, $event->status);
            $this->assertSame(['quantity' => 'invalid'], $event->decodedPayload());
            $this->assertSame(['quantity' => ['Must be an integer.']], $event->decodedPayload('details'));
            $this->assertSame('project', $event->owner_type);
            $this->assertTrue($event->owner->is($owner));
            $this->assertSame(1, $owner->incomingEvents()->withStatus($status)->count());
            foreach (['recipient-a', 'recipient-b'] as $destination) {
                $out = $owner->logOutgoingEvent(new OutgoingEventData('notify', Payload::text('bad input'), $destination, $event));
                $this->assertTrue($out->incomingEvent->is($event));
            }
            $this->assertSame(2, $event->outgoingEvents()->count());
        }
        $owner->delete();
        $this->assertSame(3, IncomingEvent::query()->count());
        $this->assertSame(6, OutgoingEvent::query()->count());
    }

    public function test_incoming_status_has_no_default(): void
    {
        $this->expectException(\ArgumentCountError::class);
        new IncomingEventData('received', Payload::text('raw'));
    }

    public function test_custom_codec_and_raw_text_are_independent_of_details(): void
    {
        // Register a literal dotted identifier rather than a nested config key.
        config(['model-events.codecs' => [...config('model-events.codecs'), 'reverse.v1' => ReverseCodec::class]]);
        $event = $this->owner()->logIncomingEvent(new IncomingEventData(
            'raw', new Payload('reverse.v1', 'abc'), IncomingEventStatus::Ok, Payload::text("line one\nline two"),
        ));
        $this->assertSame('cba', $event->rawPayload());
        $this->assertSame('abc', $event->fresh()->decodedPayload());
        $this->assertSame("line one\nline two", $event->decodedPayload('details'));
    }

    #[DataProvider('invalidPayloads')]
    public function test_codec_errors_do_not_create_partial_records(Payload $payload, string $exception): void
    {
        try {
            $this->owner()->logIncomingEvent(new IncomingEventData('raw', $payload, IncomingEventStatus::Error));
            $this->fail('An invalid payload must be rejected.');
        } catch (\Throwable $error) {
            $this->assertInstanceOf($exception, $error);
        }
        $this->assertSame(0, IncomingEvent::query()->count());
    }

    public static function invalidPayloads(): array
    {
        return [
            [new Payload('unknown', 'raw'), InvalidArgumentException::class],
            [new Payload('text', ['wrong']), InvalidArgumentException::class],
            [Payload::json(NAN), JsonException::class],
        ];
    }

    public function test_saved_owner_and_same_source_owner_are_required(): void
    {
        $owner = $this->owner();
        $source = $owner->logIncomingEvent(new IncomingEventData('in', Payload::json(null), IncomingEventStatus::Ok));
        foreach ([new TestOwner(['id' => 'unsaved']), $this->owner('other')] as $invalidOwner) {
            try {
                $invalidOwner->logOutgoingEvent(new OutgoingEventData('out', Payload::text('x'), 'y', $source));
                $this->fail('Invalid owner was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertSame(0, OutgoingEvent::query()->count());
            }
        }
        $this->assertSame('null', $source->rawPayload());
        $this->assertNull($source->decodedPayload());
        $this->assertNull($source->rawPayload('details'));
    }

    public function test_integer_uuid_ulid_keys_and_independent_traits(): void
    {
        $integer = IntegerIncomingOwner::query()->create();
        $event = $integer->logIncomingEvent(new IncomingEventData('in', Payload::text(''), IncomingEventStatus::Ok));
        $this->assertTrue($event->owner->is($integer));
        $this->assertFalse(method_exists($integer, 'outgoingEvents'));
        foreach ([(string) Str::uuid(), (string) Str::ulid()] as $id) {
            $owner = OnlyOutgoingOwner::query()->create(['id' => $id]);
            $out = $owner->logOutgoingEvent(new OutgoingEventData('out', Payload::text('x'), 'recipient'));
            $this->assertTrue($out->owner->is($owner));
            $this->assertFalse(method_exists($owner, 'incomingEvents'));
        }
    }

    public function test_json_decode_errors_are_explicit(): void
    {
        $this->expectException(JsonException::class);
        app(Payloads::class)->codec('json')->decode('{broken');
    }
}

class ReverseCodec implements PayloadCodec
{
    public function encode(mixed $value): string
    {
        return strrev($value);
    }

    public function decode(string $value): mixed
    {
        return strrev($value);
    }
}

class IntegerIncomingOwner extends Model
{
    use IncomingEvents;

    protected $table = 'integer_owners';

    public $timestamps = false;

    protected $guarded = [];
}

class OnlyOutgoingOwner extends Model
{
    use OutgoingEvents;

    protected $table = 'test_owners';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
