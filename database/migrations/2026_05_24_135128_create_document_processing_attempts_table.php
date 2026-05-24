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
        Schema::create('document_processing_attempts', function (Blueprint $table) {
            $table->id();

            // Documento a cui appartiene questo tentativo/step di processing.
            $table->foreignId('document_id')
                ->constrained()
                ->cascadeOnDelete();

            // Nome dello step della pipeline.
            // Esempi: upload, text_extraction, classification, parsing, product_draft, scoring.
            $table->string('step');

            // Stato dello step.
            // Esempi: pending, running, completed, failed, skipped.
            $table->string('status')->default('pending');

            // Motore o handler usato per questo step.
            // Esempi: ProcessDocumentJob, PdfToTextExtractor, ReceiptParser.
            $table->string('handler')->nullable();

            // Numero progressivo del tentativo per quello step.
            $table->unsignedInteger('attempt_number')->default(1);

            // Messaggio leggibile in caso di errore.
            $table->text('error_message')->nullable();

            // Eccezione tecnica opzionale.
            $table->string('exception_class')->nullable();

            // Metadati tecnici opzionali.
            // Esempi: durata, memoria, file path, engine, pagine PDF, output parziale.
            $table->json('metadata')->nullable();

            // Timestamp dello step.
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['document_id', 'step'], 'dpa_document_step_idx');
            $table->index(['document_id', 'status'], 'dpa_document_status_idx');
            $table->index(['step', 'status'], 'dpa_step_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_processing_attempts');
    }
};
