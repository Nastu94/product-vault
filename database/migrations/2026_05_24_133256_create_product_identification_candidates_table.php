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
        Schema::create('product_identification_candidates', function (Blueprint $table) {
            $table->id();

            // Documento da cui nasce il candidato prodotto.
            $table->foreignId('document_id')
                ->constrained()
                ->cascadeOnDelete();

            // Riga specifica da cui è stato estratto il candidato, se disponibile.
            $table->foreignId('document_line_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Eventuale prodotto già creato o collegato dopo la conferma.
            $table->foreignId('product_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Brand candidato, se rilevato.
            $table->foreignId('brand_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Categoria candidata, se rilevata.
            $table->foreignId('category_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Nome prodotto candidato.
            $table->string('name');

            // Modello candidato.
            $table->string('model')->nullable();

            // Numero seriale candidato.
            $table->string('serial_number')->nullable()->index();

            // Codice EAN/barcode candidato.
            $table->string('ean_code')->nullable()->index();

            // Prezzo candidato ricavato dalla riga o dal documento.
            $table->decimal('price', 10, 2)->nullable();

            // Fonte del candidato.
            // Esempi: parser, ocr, barcode, user, manual.
            $table->string('source')->default('parser');

            // Punteggio di affidabilità del candidato.
            $table->unsignedTinyInteger('confidence_score')->nullable();

            // Indica se questo candidato è stato selezionato dall'utente o dal sistema.
            $table->boolean('is_selected')->default(false);

            // Metadati tecnici opzionali.
            // Esempi: regola parser usata, testo originale, matching brand/categoria.
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['document_id', 'is_selected'], 'pic_doc_selected_idx');
            $table->index(['document_line_id', 'confidence_score'], 'pic_line_conf_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_identification_candidates');
    }
};
