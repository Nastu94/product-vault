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
        Schema::create('document_lines', function (Blueprint $table) {
            $table->id();

            // Documento da cui è stata estratta la riga.
            $table->foreignId('document_id')
                ->constrained()
                ->cascadeOnDelete();

            // Tipo di riga estratta.
            // Esempi: product, discount, tax, payment, total, merchant_info, unknown.
            $table->foreignId('document_line_type_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Numero progressivo della riga nel documento/testo estratto.
            $table->unsignedInteger('line_number');

            // Testo grezzo originale della riga.
            $table->text('raw_text');

            // Descrizione pulita, se il parser riesce a ricavarla.
            $table->string('description')->nullable();

            // Quantità, se presente.
            $table->decimal('quantity', 10, 3)->nullable();

            // Prezzo unitario, se presente.
            $table->decimal('unit_price', 10, 2)->nullable();

            // Prezzo totale della riga, se presente.
            $table->decimal('total_price', 10, 2)->nullable();

            // Punteggio di affidabilità della riga o della classificazione.
            $table->unsignedTinyInteger('confidence_score')->nullable();

            // Metadati tecnici opzionali.
            // Esempi: coordinate OCR, pagina PDF, regola parser usata.
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['document_id', 'line_number']);
            $table->index(['document_id', 'document_line_type_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_lines');
    }
};
