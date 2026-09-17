<?php

namespace TrafficOps\ModelEvents\Tests\Feature;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use TrafficOps\ModelEvents\DTO\IncomingEventData;
use TrafficOps\ModelEvents\DTO\OutgoingEventData;
use TrafficOps\ModelEvents\DTO\Payload;
use TrafficOps\ModelEvents\Enums\IncomingEventStatus;
use TrafficOps\ModelEvents\Jobs\PruneModelEventsJob;
use TrafficOps\ModelEvents\ModelEventsServiceProvider;
use TrafficOps\ModelEvents\Models\IncomingEvent;
use TrafficOps\ModelEvents\Models\OutgoingEvent;
use TrafficOps\ModelEvents\Models\OutgoingEventAttempt;
use TrafficOps\ModelEvents\Support\EventLock;
use TrafficOps\ModelEvents\Support\ModelResolver;
use TrafficOps\ModelEvents\Tests\Fixtures\TestSendJob;
use TrafficOps\ModelEvents\Tests\TestCase;

class ExtensionsTest extends TestCase
{
    public function test_custom_models_are_used_by_traits_relations_jobs_and_cleanup(): void
    {
        config(['model-events.models' => [
            'incoming' => CustomIncoming::class, 'outgoing' => CustomOutgoing::class, 'attempt' => CustomAttempt::class,
        ]]);
        $owner = $this->owner();
        $incoming = $owner->logIncomingEvent(new IncomingEventData('in', Payload::text('raw'), IncomingEventStatus::Ok));
        $outgoing = $owner->logOutgoingEvent(new OutgoingEventData('out', Payload::text('raw'), 'target', $incoming));
        $this->assertInstanceOf(CustomIncoming::class, $incoming);
        $this->assertInstanceOf(CustomOutgoing::class, $outgoing);
        $this->assertInstanceOf(CustomIncoming::class, $outgoing->incomingEvent);
        $this->assertInstanceOf(CustomOutgoing::class, $incoming->outgoingEvents()->sole());
        $result = $owner->scheduleOutgoingEvent($outgoing, TestSendJob::class);
        $this->assertInstanceOf(CustomOutgoing::class, $result);
        $this->assertInstanceOf(CustomAttempt::class, $result->attempts()->sole());
        $this->assertInstanceOf(CustomOutgoing::class, $result->attempts()->sole()->outgoingEvent);
        $incoming->update(['received_at' => now()->subDays(40)]);
        $result->update(['completed_at' => now()->subDays(40)]);
        (new class extends PruneModelEventsJob {})->handle(app(EventLock::class));
        $this->assertSame(0, $owner->incomingEvents()->count());
        $this->assertSame(0, $owner->outgoingEvents()->count());
    }

    public function test_invalid_model_override_is_rejected(): void
    {
        config(['model-events.models.incoming' => OutgoingEvent::class]);
        $this->expectException(InvalidArgumentException::class);
        ModelResolver::make('incoming');
    }

    public function test_migrations_can_roll_back_and_reapply_and_assets_are_publishable(): void
    {
        $migration = require __DIR__.'/../../database/migrations/2026_09_10_100000_create_model_event_tables.php';
        $migration->down();
        $this->assertFalse(Schema::hasTable('model_incoming_events'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('model_outgoing_event_attempts'));
        $this->assertNotEmpty(ServiceProvider::pathsToPublish(ModelEventsServiceProvider::class, 'model-events-config'));
        $this->assertNotEmpty(ServiceProvider::pathsToPublish(ModelEventsServiceProvider::class, 'model-events-migrations'));
    }

    public function test_migration_autoload_can_be_disabled(): void
    {
        config(['model-events.migrations' => false]);
        $migrator = \Mockery::mock(Migrator::class);
        $migrator->shouldNotReceive('path');
        $this->app->instance('migrator', $migrator);
        (new ModelEventsServiceProvider($this->app))->boot();
        $this->assertTrue(true);
    }

    public function test_partial_config_keeps_defaults_and_builtin_codecs(): void
    {
        config(['model-events' => ['models' => ['incoming' => CustomIncoming::class], 'queue' => ['tries' => 8]]]);
        (new ModelEventsServiceProvider($this->app))->register();
        $this->assertSame(CustomIncoming::class, ModelResolver::class('incoming'));
        $this->assertSame(OutgoingEvent::class, ModelResolver::class('outgoing'));
        $this->assertSame(8, config('model-events.queue.tries'));
        $this->assertSame(60, config('model-events.queue.timeout'));
        $this->assertArrayHasKey('json', config('model-events.codecs'));
    }

    public function test_cross_connection_model_override_is_rejected(): void
    {
        config(['database.connections.other' => config('database.connections.testing')]);
        config(['model-events.models.attempt' => OtherConnectionAttempt::class]);
        $this->expectException(InvalidArgumentException::class);
        ModelResolver::make('outgoing');
    }
}

class CustomIncoming extends IncomingEvent {}

class CustomOutgoing extends OutgoingEvent {}

class CustomAttempt extends OutgoingEventAttempt {}

class OtherConnectionAttempt extends OutgoingEventAttempt
{
    protected $connection = 'other';
}
