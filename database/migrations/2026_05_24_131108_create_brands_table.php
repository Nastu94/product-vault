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
        Schema::create('brands', function (Blueprint $table) {
            $table->id();

            // Team Jetstream proprietario del brand, se creato manualmente dall'utente.
            // Nullable perché in futuro potremo avere brand globali condivisi.
            $table->foreignId('team_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Nome principale del brand.
            // Esempi: Apple, Samsung, Sony, Bosch.
            $table->string('name');

            // Nome normalizzato per ricerca, deduplica e matching.
            // Esempio: "Apple Inc." -> "apple inc".
            $table->string('normalized_name')->index();

            // Sito ufficiale o riferimento opzionale.
            $table->string('website')->nullable();

            // Indica se il brand è stato verificato da fonte affidabile o manualmente.
            $table->boolean('is_verified')->default(false);

            // Permette di disattivare il brand senza eliminarlo.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['team_id', 'normalized_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
