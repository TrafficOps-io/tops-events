<?php

namespace TrafficOps\ModelEvents\Codecs;

use Illuminate\Contracts\Encryption\Encrypter;
use TrafficOps\ModelEvents\Contracts\PayloadCodec;
use TrafficOps\ModelEvents\DTO\Payload;

final class EncryptedJsonCodec implements PayloadCodec
{
    public function __construct(private Encrypter $encrypter) {}

    public function encode(mixed $value): string
    {
        return $this->encrypter->encryptString(json_encode($this->pack($value), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION));
    }

    public function decode(string $value): mixed
    {
        return $this->unpack(json_decode($this->encrypter->decryptString($value), true, 512, JSON_THROW_ON_ERROR));
    }

    public static function payload(mixed $value): Payload
    {
        return new Payload('encrypted-json.v1', $value);
    }

    private function pack(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            return ['__encrypted_json_v1' => 'object', 'value' => array_map($this->pack(...), get_object_vars($value))];
        }
        if (is_array($value)) {
            return [
                '__encrypted_json_v1' => array_is_list($value) ? 'list' : 'map',
                'value' => array_map($this->pack(...), $value),
            ];
        }

        return $value;
    }

    private function unpack(mixed $value): mixed
    {
        if (! is_array($value) || ! isset($value['__encrypted_json_v1'], $value['value']) || ! is_array($value['value'])) {
            return $value;
        }
        $unpacked = array_map($this->unpack(...), $value['value']);

        return $value['__encrypted_json_v1'] === 'object' ? (object) $unpacked : $unpacked;
    }
}
