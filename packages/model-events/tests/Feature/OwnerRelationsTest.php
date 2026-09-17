<?php

namespace TrafficOps\ModelEvents\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use TrafficOps\ModelEvents\DTO\IncomingEventData;
use TrafficOps\ModelEvents\DTO\OutgoingEventData;
use TrafficOps\ModelEvents\DTO\Payload;
use TrafficOps\ModelEvents\Enums\IncomingEventStatus;
use TrafficOps\ModelEvents\Tests\TestCase;
use TrafficOps\ModelEvents\Traits\IncomingEvents;
use TrafficOps\ModelEvents\Traits\OutgoingEvents;

class OwnerRelationsTest extends TestCase
{
    public function test_integer_owners_support_counts_existence_filters_and_eager_loading(): void
    {
        $owner = NumericJournalOwner::create();
        $incoming = $owner->logIncomingEvent(new IncomingEventData('received', Payload::json([]), IncomingEventStatus::Ok));
        $owner->logOutgoingEvent(new OutgoingEventData('send', Payload::json([]), 'target', $incoming));
        NumericJournalOwner::create();
        $result = NumericJournalOwner::withCount(['incomingEvents', 'outgoingEvents'])->whereHas('incomingEvents')->with(['incomingEvents', 'outgoingEvents'])->sole();
        $this->assertSame(1, $result->incoming_events_count);
        $this->assertSame(1, $result->outgoing_events_count);
        $this->assertCount(1, $result->incomingEvents);
        $this->assertCount(1, $result->outgoingEvents);
        $this->assertSame(1, NumericJournalOwner::whereDoesntHave('incomingEvents')->count());
    }
}

class NumericJournalOwner extends Model
{
    use IncomingEvents, OutgoingEvents;

    protected $table = 'integer_owners';

    public $timestamps = false;

    protected $guarded = [];
}
