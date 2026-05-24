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
        Schema::create('product_event_types', function (Blueprint $table) {
            $table->id();

            // Codice tecnico usato nel codice applicativo.
            // Esempi: purchase, repair, service, warranty_update, manual_added, document_added, sold, disposed, unknown.
            $table->string('code')->unique();

            // Nome leggibile mostrabile nella UI o nel backoffice.
            $table->string('name');

            // Descrizione opzionale del tipo evento.
            $table->text('description')->nullable();

            // Permette di disattivare un tipo senza eliminarlo dal database.
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_event_types');
    }
};
