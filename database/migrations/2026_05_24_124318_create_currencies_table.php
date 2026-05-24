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
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();

            // Codice ISO della valuta.
            // Esempi: EUR, USD, GBP.
            $table->string('code', 3)->unique();

            // Nome leggibile della valuta.
            // Esempi: Euro, US Dollar, British Pound.
            $table->string('name');

            // Simbolo mostrabile nella UI.
            // Esempi: €, $, £.
            $table->string('symbol', 10)->nullable();

            // Numero di decimali usati normalmente dalla valuta.
            $table->unsignedTinyInteger('decimal_places')->default(2);

            // Permette di disattivare una valuta senza eliminarla.
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
