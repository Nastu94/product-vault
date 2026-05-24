<?php

use App\Models\Currency;
use App\Models\DocumentType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Aggiunge riferimenti normalizzati alla tabella documents.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // Tipo documento normalizzato.
            $table->foreignId('document_type_id')
                ->nullable()
                ->after('uploaded_by_user_id')
                ->constrained()
                ->nullOnDelete();

            // Merchant/venditore associato al documento, se riconosciuto.
            $table->foreignId('merchant_id')
                ->nullable()
                ->after('document_type_id')
                ->constrained()
                ->nullOnDelete();

            // Valuta normalizzata.
            $table->foreignId('currency_id')
                ->nullable()
                ->after('total_amount')
                ->constrained()
                ->nullOnDelete();

            // Stato specifico dell'estrazione testo.
            $table->string('text_extraction_status')
                ->nullable()
                ->after('status');
        });

        $unknownDocumentType = DocumentType::query()
            ->where('code', 'unknown')
            ->first();

        $euroCurrency = Currency::query()
            ->where('code', 'EUR')
            ->first();

        if ($unknownDocumentType) {
            DB::table('documents')
                ->whereNull('document_type_id')
                ->update([
                    'document_type_id' => $unknownDocumentType->id,
                ]);
        }

        if ($euroCurrency) {
            DB::table('documents')
                ->whereNull('currency_id')
                ->update([
                    'currency_id' => $euroCurrency->id,
                ]);
        }
    }

    /**
     * Rimuove i riferimenti normalizzati dalla tabella documents.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['document_type_id']);
            $table->dropForeign(['merchant_id']);
            $table->dropForeign(['currency_id']);

            $table->dropColumn([
                'document_type_id',
                'merchant_id',
                'currency_id',
                'text_extraction_status',
            ]);
        });
    }
};