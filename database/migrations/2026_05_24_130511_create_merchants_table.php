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
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();

            // Team Jetstream proprietario del merchant, se creato manualmente dall'utente.
            // Nullable perché in futuro potremo avere merchant globali condivisi.
            $table->foreignId('team_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Paese del merchant, utile per regole future e normalizzazione.
            $table->foreignId('country_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Nome principale del venditore.
            $table->string('name');

            // Nome normalizzato per ricerca, deduplica e matching.
            // Esempio: "MediaWorld Roma" -> "mediaworld roma".
            $table->string('normalized_name')->index();

            // Dati opzionali utili in parsing fatture/scontrini.
            $table->string('vat_number')->nullable()->index();
            $table->string('website')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();

            // Indirizzo testuale libero per MVP.
            // Se servirà, più avanti potremo normalizzarlo meglio.
            $table->text('address')->nullable();

            // Indica se il merchant è stato verificato da fonte affidabile o manualmente.
            $table->boolean('is_verified')->default(false);

            // Permette di disattivare il merchant senza eliminarlo.
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
        Schema::dropIfExists('merchants');
    }
};
