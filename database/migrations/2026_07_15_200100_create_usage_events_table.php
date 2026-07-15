<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->string('event_key');
            $table->unsignedBigInteger('quantity')->default(1);
            $table->nullableMorphs('subject');
            $table->string('idempotency_key');
            $table->timestamp('occurred_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['team_id', 'event_key', 'idempotency_key'],
                'ue_team_event_idempotency_unique'
            );
            $table->index(
                ['team_id', 'event_key', 'occurred_at'],
                'ue_team_event_occurred_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_events');
    }
};
