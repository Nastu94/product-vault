<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Aggiunge lo stato di revisione manuale dei candidati prodotto.
     */
    public function up(): void
    {
        Schema::table('product_identification_candidates', function (Blueprint $table) {
            $table->string('review_status')
                ->default('pending')
                ->after('is_selected')
                ->index();

            $table->string('ignored_reason')
                ->nullable()
                ->after('review_status');

            $table->text('ignored_note')
                ->nullable()
                ->after('ignored_reason');

            $table->foreignId('reviewed_by_user_id')
                ->nullable()
                ->after('ignored_note')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('reviewed_at')
                ->nullable()
                ->after('reviewed_by_user_id');

            $table->index(
                ['document_id', 'review_status'],
                'pic_doc_review_status_idx'
            );
        });

        DB::table('product_identification_candidates')
            ->whereNotNull('product_id')
            ->update([
                'review_status' => 'confirmed',
                'is_selected' => true,
                'reviewed_at' => now(),
            ]);

        /*
        |--------------------------------------------------------------------------
        | Backfill dati esistenti
        |--------------------------------------------------------------------------
        |
        | I candidati già collegati a un prodotto prima di questa migration
        | devono essere considerati confermati, non pendenti.
        |
        */
        DB::table('product_identification_candidates')
            ->whereNull('product_id')
            ->whereNull('review_status')
            ->update([
                'review_status' => 'pending',
            ]);
    }

    /**
     * Rimuove lo stato di revisione manuale dei candidati prodotto.
     */
    public function down(): void
    {
        Schema::table('product_identification_candidates', function (Blueprint $table) {
            $table->dropIndex('pic_doc_review_status_idx');
            $table->dropForeign(['reviewed_by_user_id']);

            $table->dropColumn([
                'review_status',
                'ignored_reason',
                'ignored_note',
                'reviewed_by_user_id',
                'reviewed_at',
            ]);
        });
    }
};