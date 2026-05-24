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
        Schema::create('merchant_aliases', function (Blueprint $table) {
            $table->id();

            // Merchant normalizzato a cui appartiene questo alias.
            $table->foreignId('merchant_id')
                ->constrained()
                ->cascadeOnDelete();

            // Alias originale trovato nel documento, OCR o inserito manualmente.
            // Esempi: "MEDIAWORLD", "Media World Roma", "MEDIWRLD".
            $table->string('alias');

            // Versione normalizzata dell'alias per ricerca e matching.
            // Esempio: "Media World Roma" -> "media world roma".
            $table->string('normalized_alias')->index();

            // Fonte dell'alias.
            // Esempi: ocr, parser, user, import, admin.
            $table->string('source')->nullable();

            // Punteggio opzionale di affidabilità dell'associazione alias -> merchant.
            $table->unsignedTinyInteger('confidence_score')->nullable();

            // Permette di disattivare un alias senza eliminarlo.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Evita duplicati dello stesso alias normalizzato per lo stesso merchant.
            $table->unique(['merchant_id', 'normalized_alias']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_aliases');
    }
};
