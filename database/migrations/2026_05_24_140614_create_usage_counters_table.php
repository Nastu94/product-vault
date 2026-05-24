<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('usage_counters', function (Blueprint $table) {
            $table->id();

            // Team/workspace a cui appartiene il contatore.
            $table->foreignId('team_id')
                ->constrained()
                ->cascadeOnDelete();

            // Utente opzionale a cui attribuire l'utilizzo.
            // Nullable perché alcuni limiti sono a livello workspace, non utente.
            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Limite del piano collegato, se applicabile.
            // Nullable perché alcuni contatori possono essere tecnici o interni.
            $table->foreignId('plan_limit_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Chiave tecnica del contatore.
            // Esempi: documents_uploaded, products_created, storage_mb_used, ocr_runs.
            $table->string('counter_key');

            // Valore usato nel periodo corrente.
            $table->unsignedBigInteger('used_value')->default(0);

            // Periodo di riferimento.
            // Per limiti senza reset può restare nullable.
            $table->date('period_starts_at')->nullable();
            $table->date('period_ends_at')->nullable();

            // Metadati opzionali.
            // Esempi: ultimo documento contato, origine aggiornamento, dettagli tecnici.
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['team_id', 'counter_key'], 'uc_team_key_idx');
            $table->index(['user_id', 'counter_key'], 'uc_user_key_idx');
            $table->index(['plan_limit_id', 'counter_key'], 'uc_limit_key_idx');

            $table->unique([
                'team_id',
                'user_id',
                'counter_key',
                'period_starts_at',
                'period_ends_at',
            ], 'uc_scope_period_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('usage_counters');
    }
};
