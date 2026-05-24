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
        Schema::create('documents', function (Blueprint $table) {
            $table->id();

            // Team Jetstream usato come workspace/account proprietario del documento.
            $table->foreignId('team_id')
                ->constrained()
                ->cascadeOnDelete();

            // Utente che ha caricato il documento.
            $table->foreignId('uploaded_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Tipologia iniziale del documento.
            // Per ora usiamo una stringa semplice; più avanti potremo normalizzarla in document_types.
            $table->string('document_type')->default('unknown');

            // Stato del documento nella pipeline.
            $table->string('status')->default('uploaded');

            // Metadati base del file originale.
            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();

            // Dati eventualmente estratti dal documento.
            $table->date('purchase_date')->nullable();
            $table->decimal('total_amount', 10, 2)->nullable();
            $table->string('currency', 3)->default('EUR');

            // Punteggi di affidabilità.
            $table->unsignedTinyInteger('document_confidence_score')->nullable();
            $table->unsignedTinyInteger('product_reliability_score')->nullable();

            // Testo grezzo estratto da PDF/OCR.
            // Per ora lo teniamo qui; più avanti potremo spostarlo in document_text_extractions.
            $table->longText('raw_text')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
