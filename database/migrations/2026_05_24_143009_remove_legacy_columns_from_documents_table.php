<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rimuove colonne testuali provvisorie ormai sostituite da lookup normalizzate.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn([
                'document_type',
                'currency',
            ]);
        });
    }

    /**
     * Ripristina le colonne legacy in caso di rollback.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('document_type')
                ->default('unknown')
                ->after('uploaded_by_user_id');

            $table->string('currency', 3)
                ->default('EUR')
                ->after('total_amount');
        });
    }
};