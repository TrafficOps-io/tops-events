<?php

namespace TrafficOps\ModelEvents\Tests;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use TrafficOps\ModelEvents\DTO\OutgoingEventData;
use TrafficOps\ModelEvents\DTO\Payload;
use TrafficOps\ModelEvents\ModelEventsServiceProvider;
use TrafficOps\ModelEvents\Models\OutgoingEvent;
use TrafficOps\ModelEvents\Tests\Fixtures\TestOwner;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ModelEventsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('m', 32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', getenv('MODEL_EVENTS_DB') === 'pgsql' ? [
            'driver' => 'pgsql', 'host' => getenv('MODEL_EVENTS_PG_HOST') ?: '127.0.0.1',
            'port' => getenv('MODEL_EVENTS_PG_PORT') ?: '5432',
            'database' => getenv('MODEL_EVENTS_PG_DATABASE') ?: 'webhooks_gateway_test',
            'username' => getenv('MODEL_EVENTS_PG_USER') ?: 'webhooks_gateway',
            'password' => getenv('MODEL_EVENTS_PG_PASSWORD') ?: 'local-development-only',
            'search_path' => 'model_events_test_'.getmypid(), 'prefix' => '',
        ] : ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true, 'prefix' => '']);
        $app['config']->set('queue.connections.database', [
            'driver' => 'database', 'connection' => 'testing', 'table' => 'jobs',
            'queue' => 'default', 'retry_after' => 150, 'after_commit' => false,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE SCHEMA "model_events_test_'.getmypid().'"');
        }
        $this->artisan('migrate', ['--database' => 'testing'])->run();
        Schema::create('test_owners', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name')->default('project');
        });
        Schema::create('integer_owners', function (Blueprint $table) {
            $table->id();
        });
        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }
        Relation::morphMap(['project' => TestOwner::class], false);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->app) && DB::getDriverName() === 'pgsql') {
                DB::statement('DROP SCHEMA IF EXISTS "model_events_test_'.getmypid().'" CASCADE');
            }
            Relation::morphMap([], false);
            $this->travelBack();
        } finally {
            parent::tearDown();
        }
    }

    protected function owner(string $id = 'project-1'): TestOwner
    {
        return TestOwner::query()->create(['id' => $id]);
    }

    protected function outgoing(): OutgoingEvent
    {
        return $this->owner()->logOutgoingEvent(new OutgoingEventData('notify', Payload::json(['original' => true]), 'recipient'));
    }
}
