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
        Schema::create('document_text_extractions', function (Blueprint $table) {
            $table->id();

            // Documento a cui appartiene questo tentativo di estrazione testo.
            $table->foreignId('document_id')
                ->constrained()
                ->cascadeOnDelete();

            // Motore o strategia usata per l'estrazione.
            // Esempi: pdf_to_text, smalot_pdfparser, tesseract_ocr, manual.
            $table->string('engine');

            // Stato del tentativo.
            // Esempi: pending, running, completed, failed.
            $table->string('status')->default('pending');

            // Testo grezzo estratto dal documento.
            $table->longText('raw_text')->nullable();

            // Punteggio opzionale di confidenza del tentativo.
            $table->unsignedTinyInteger('confidence_score')->nullable();

            // Eventuali metadati tecnici del tentativo.
            // Esempi: numero pagine, lingua OCR, durata, parametri usati.
            $table->json('metadata')->nullable();

            // Errore leggibile in caso di fallimento.
            $table->text('error_message')->nullable();

            // Timestamp tecnici del processo.
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['document_id', 'engine']);
            $table->index(['document_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_text_extractions');
    }
};
