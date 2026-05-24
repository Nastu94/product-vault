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
        Schema::create('document_line_types', function (Blueprint $table) {
            $table->id();

            // Codice tecnico usato nel codice applicativo.
            // Esempi: product, discount, tax, payment, total, merchant_info, unknown.
            $table->string('code')->unique();

            // Nome leggibile mostrabile nella UI o nel backoffice.
            $table->string('name');

            // Descrizione opzionale del tipo di riga.
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
        Schema::dropIfExists('document_line_types');
    }
};
