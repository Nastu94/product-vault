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
        Schema::create('identification_statuses', function (Blueprint $table) {
            $table->id();

            // Codice tecnico usato nel codice applicativo.
            // Esempi: unknown, partial, probable, user_confirmed, merchant_verified.
            $table->string('code')->unique();

            // Nome leggibile mostrabile nella UI o nel backoffice.
            $table->string('name');

            // Descrizione opzionale dello stato di identificazione.
            $table->text('description')->nullable();

            // Permette di disattivare uno stato senza eliminarlo dal database.
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('identification_statuses');
    }
};
