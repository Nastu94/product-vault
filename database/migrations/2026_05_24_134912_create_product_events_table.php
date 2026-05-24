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
        Schema::create('product_events', function (Blueprint $table) {
            $table->id();

            // Prodotto a cui appartiene l'evento.
            $table->foreignId('product_id')
                ->constrained()
                ->cascadeOnDelete();

            // Tipo evento: purchase, repair, service, document_added, ecc.
            $table->foreignId('product_event_type_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Documento collegato all'evento, se presente.
            // Esempio: documento di riparazione, manuale, scontrino.
            $table->foreignId('document_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Utente che ha creato o confermato l'evento.
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Titolo leggibile dell'evento.
            $table->string('title');

            // Descrizione opzionale dell'evento.
            $table->text('description')->nullable();

            // Data reale dell'evento, se nota.
            $table->date('event_date')->nullable();

            // Fonte dell'evento.
            // Esempi: manual, document_parser, warranty_rule, system.
            $table->string('source')->default('manual');

            // Punteggio di affidabilità dell'evento.
            $table->unsignedTinyInteger('confidence_score')->nullable();

            // Metadati tecnici opzionali.
            // Esempi: parser usato, testo originale, rule_id, valori estratti.
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['product_id', 'event_date'], 'pe_product_date_idx');
            $table->index(['product_id', 'product_event_type_id'], 'pe_product_type_idx');
            $table->index(['document_id', 'product_event_type_id'], 'pe_document_type_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_events');
    }
};
