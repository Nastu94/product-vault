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
        Schema::create('document_classifications', function (Blueprint $table) {
            $table->id();

            // Documento a cui appartiene questa classificazione.
            $table->foreignId('document_id')
                ->constrained()
                ->cascadeOnDelete();

            // Tipo documento proposto dalla classificazione.
            // Esempi: receipt, invoice, manual, warranty_certificate, unknown.
            $table->foreignId('document_type_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Nome del classificatore o strategia usata.
            // Esempi: rule_based_v1, manual_user, ai_future.
            $table->string('classifier')->default('rule_based_v1');

            // Motivo leggibile della classificazione.
            // Esempio: "Trovate parole chiave: totale, bancomat, resto".
            $table->text('reason')->nullable();

            // Punteggio di confidenza della classificazione.
            $table->unsignedTinyInteger('confidence_score')->nullable();

            // Indica se questa è la classificazione scelta per il documento.
            $table->boolean('is_selected')->default(false);

            // Metadati tecnici opzionali del classificatore.
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['document_id', 'document_type_id']);
            $table->index(['document_id', 'is_selected']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_classifications');
    }
};
