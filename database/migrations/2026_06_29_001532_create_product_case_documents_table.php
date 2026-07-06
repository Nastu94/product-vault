<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crea la selezione dei documenti usati nella singola pratica.
     *
     * Questa tabella non sostituisce product_documents:
     *
     * - product_documents collega stabilmente documenti e prodotto;
     * - product_case_documents seleziona un sottoinsieme di quei documenti
     *   come evidenze della pratica.
     */
    public function up(): void
    {
        Schema::create(
            'product_case_documents',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId('product_case_id')
                    ->constrained()
                    ->cascadeOnDelete();

                $table->foreignId('document_id')
                    ->constrained()
                    ->cascadeOnDelete();

                /*
                 * L'utente che ha selezionato il documento viene conservato
                 * come provenance operativa.
                 */
                $table->foreignId('selected_by_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                /*
                 * Nota contestuale opzionale relativa all'uso del documento
                 * nella pratica. Non modifica il documento originale.
                 */
                $table->text('notes')
                    ->nullable();

                $table->timestamps();

                /*
                 * Lo stesso documento può comparire una sola volta
                 * nella stessa pratica.
                 */
                $table->unique(
                    [
                        'product_case_id',
                        'document_id',
                    ],
                    'pc_document_unique'
                );
            }
        );
    }

    /**
     * Elimina la selezione dei documenti delle pratiche.
     */
    public function down(): void
    {
        Schema::dropIfExists(
            'product_case_documents'
        );
    }
};