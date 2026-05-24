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
        Schema::create('product_documents', function (Blueprint $table) {
            $table->id();

            // Prodotto collegato al documento.
            $table->foreignId('product_id')
                ->constrained()
                ->cascadeOnDelete();

            // Documento collegato al prodotto.
            $table->foreignId('document_id')
                ->constrained()
                ->cascadeOnDelete();

            // Tipo di relazione tra prodotto e documento.
            // Esempi: purchase_proof, warranty_proof, manual, repair_history.
            $table->foreignId('relationship_type_id')
                ->nullable()
                ->constrained('document_relationship_types')
                ->nullOnDelete();

            // Utente che ha creato il collegamento.
            $table->foreignId('linked_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Note opzionali sul collegamento.
            $table->text('notes')->nullable();

            $table->timestamps();

            // Evita collegamenti duplicati identici.
            $table->unique([
                'product_id',
                'document_id',
                'relationship_type_id',
            ], 'product_document_relationship_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_documents');
    }
};
