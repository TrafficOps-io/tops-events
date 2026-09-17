<?php

namespace TrafficOps\ModelEvents\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use TrafficOps\ModelEvents\Traits\IncomingEvents;
use TrafficOps\ModelEvents\Traits\OutgoingEvents;

class TestOwner extends Model
{
    use IncomingEvents, OutgoingEvents;

    protected $table = 'test_owners';

    protected $guarded = [];

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;
}
