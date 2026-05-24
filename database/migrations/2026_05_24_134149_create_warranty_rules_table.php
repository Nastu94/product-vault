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
        Schema::create('warranty_rules', function (Blueprint $table) {
            $table->id();

            // Team Jetstream proprietario della regola, se personalizzata.
            // Nullable perché possiamo avere regole globali valide per tutti.
            $table->foreignId('team_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Paese a cui si applica la regola.
            $table->foreignId('country_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Categoria prodotto a cui si applica la regola.
            // Nullable per regole generiche a livello paese.
            $table->foreignId('category_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Tipo garanzia: legal, commercial, extended, repair_extension, unknown.
            $table->foreignId('warranty_type_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Durata suggerita/calcolata in mesi.
            $table->unsignedSmallInteger('duration_months');

            // Tipo di regola.
            // Esempi: default, legal_estimate, merchant_rule, category_rule, manual_override.
            $table->string('rule_type')->default('default');

            // Fonte leggibile della regola.
            // Esempio: "Default MVP per mercato italiano".
            $table->text('source_note')->nullable();

            // Priorità in caso di più regole applicabili.
            // Più alto = più specifico/importante.
            $table->unsignedSmallInteger('priority')->default(0);

            // Permette di disattivare la regola senza eliminarla.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['team_id', 'country_id'], 'wr_team_country_idx');
            $table->index(['category_id', 'warranty_type_id'], 'wr_category_type_idx');
            $table->index(['is_active', 'priority'], 'wr_active_priority_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warranty_rules');
    }
};
