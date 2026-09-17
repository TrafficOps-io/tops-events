<?php

namespace TrafficOps\ModelEvents\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use TrafficOps\ModelEvents\Support\Payloads;

abstract class EventModel extends Model
{
    use HasUlids;

    protected $guarded = [];

    public function rawPayload(string $field = 'payload'): ?string
    {
        if (! in_array($field, ['payload', 'details', 'response'], true)) {
            throw new InvalidArgumentException("Unknown payload field [$field].");
        }

        return $this->getAttributes()[$field] ?? null;
    }

    public function decodedPayload(string $field = 'payload'): mixed
    {
        $raw = $this->rawPayload($field);

        return $raw === null ? null : app(Payloads::class)->codec($this->getAttribute($field.'_format'))->decode($raw);
    }
}
