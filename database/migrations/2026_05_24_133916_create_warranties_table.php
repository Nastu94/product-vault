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
        Schema::create('warranties', function (Blueprint $table) {
            $table->id();

            // Prodotto a cui appartiene la garanzia.
            $table->foreignId('product_id')
                ->constrained()
                ->cascadeOnDelete();

            // Tipo garanzia: legal, commercial, extended, repair_extension, unknown.
            $table->foreignId('warranty_type_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Documento da cui è stata ricavata la garanzia, se disponibile.
            // Esempio: scontrino, fattura, certificato garanzia.
            $table->foreignId('source_document_id')
                ->nullable()
                ->constrained('documents')
                ->nullOnDelete();

            // Date della garanzia.
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();

            // Durata in mesi, utile per calcoli e regole.
            $table->unsignedSmallInteger('duration_months')->nullable();

            // Fonte del dato.
            // Esempi: calculated, manual, document_text, merchant, user.
            $table->string('source')->default('manual');

            // Punteggio di affidabilità della garanzia.
            $table->unsignedTinyInteger('confidence_score')->nullable();

            // Note manuali o tecniche.
            $table->text('notes')->nullable();

            // Metadati tecnici opzionali.
            // Esempi: regola usata, paese, categoria, testo originale.
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['product_id', 'warranty_type_id'], 'warranty_product_type_idx');
            $table->index(['product_id', 'ends_at'], 'warranty_product_ends_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warranties');
    }
};
