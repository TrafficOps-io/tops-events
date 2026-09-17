<?php

namespace TrafficOps\ModelEvents\Tests\Feature;

use TrafficOps\ModelEvents\Codecs\EncryptedJsonCodec;
use TrafficOps\ModelEvents\Support\Payloads;
use TrafficOps\ModelEvents\Tests\TestCase;

class EncryptedJsonCodecTest extends TestCase
{
    public function test_encrypted_json_round_trip_preserves_empty_object_and_list_types(): void
    {
        $value = ['object' => new \stdClass, 'list' => [], 'nested' => (object) ['value' => 1]];
        $encoded = app(Payloads::class)->attributes('payload', EncryptedJsonCodec::payload($value));
        $decoded = app(Payloads::class)->codec('encrypted-json.v1')->decode($encoded['payload']);

        $this->assertInstanceOf(\stdClass::class, $decoded['object']);
        $this->assertSame([], $decoded['list']);
        $this->assertEquals((object) ['value' => 1], $decoded['nested']);
        $this->assertStringNotContainsString('nested', $encoded['payload']);
    }
}
