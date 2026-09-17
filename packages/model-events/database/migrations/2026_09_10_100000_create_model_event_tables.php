<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_incoming_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('owner_type');
            $table->string('owner_id');
            $table->string('name');
            $table->string('status');
            $table->longText('payload');
            $table->string('payload_format');
            $table->longText('details')->nullable();
            $table->string('details_format')->nullable();
            $table->json('metadata');
            $table->timestampTz('received_at');
            $table->timestampsTz();
            $table->index(['owner_type', 'owner_id', 'status', 'received_at'], 'me_incoming_owner_status_time');
            $table->index('received_at');
        });

        Schema::create('model_outgoing_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('owner_type');
            $table->string('owner_id');
            $table->foreignUlid('incoming_event_id')->nullable()->constrained('model_incoming_events')->restrictOnDelete();
            $table->string('name');
            $table->string('status')->default('pending');
            $table->longText('payload');
            $table->string('payload_format');
            $table->text('destination');
            $table->json('metadata');
            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->ulid('active_attempt_id')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();
            $table->index(['owner_type', 'owner_id', 'created_at'], 'me_outgoing_owner_time');
            $table->index(['status', 'completed_at']);
            $table->index('incoming_event_id');
        });

        Schema::create('model_outgoing_event_attempts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('outgoing_event_id')->constrained('model_outgoing_events')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('status');
            $table->longText('payload')->nullable();
            $table->string('payload_format')->nullable();
            $table->text('destination')->nullable();
            $table->json('metadata')->nullable();
            $table->longText('response')->nullable();
            $table->string('response_format')->nullable();
            $table->json('response_metadata')->nullable();
            $table->text('error')->nullable();
            $table->text('exception_class')->nullable();
            $table->boolean('retryable')->default(false);
            $table->timestampTz('started_at');
            $table->timestampTz('prepared_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['outgoing_event_id', 'number'], 'me_attempt_event_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_outgoing_event_attempts');
        Schema::dropIfExists('model_outgoing_events');
        Schema::dropIfExists('model_incoming_events');
    }
};
