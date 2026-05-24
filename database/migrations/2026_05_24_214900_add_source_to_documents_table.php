<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Aggiunge la sorgente del documento.
     *
     * Esempi MVP:
     * - manual_upload: file caricato manualmente dall'utente
     *
     * Esempi futuri:
     * - email_import
     * - api_import
     * - merchant_import
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            if (! Schema::hasColumn('documents', 'source')) {
                $table
                    ->string('source')
                    ->nullable()
                    ->after('status');
            }
        });
    }

    /**
     * Rimuove la colonna source in caso di rollback.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            if (Schema::hasColumn('documents', 'source')) {
                $table->dropColumn('source');
            }
        });
    }
};