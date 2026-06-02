<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crea il dataset interno di feedback per Product Understanding.
     */
    public function up(): void
    {
        Schema::create('product_understanding_feedback', function (Blueprint $table) {
            $table->id();

            $table->foreignId('team_id')->nullable();
            $table->foreignId('document_id')->nullable();
            $table->foreignId('document_line_id')->nullable();
            $table->foreignId('candidate_id')->nullable();
            $table->foreignId('product_id')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable();

            $table->string('review_status')->index(); // confirmed | ignored
            $table->string('ignored_reason')->nullable();
            $table->text('ignored_note')->nullable();

            $table->string('candidate_name')->nullable();
            $table->string('candidate_model')->nullable();
            $table->string('candidate_serial_number')->nullable();
            $table->string('candidate_ean_code')->nullable();
            $table->decimal('candidate_price', 10, 2)->nullable();

            $table->string('final_product_name')->nullable();

            $table->text('line_description')->nullable();
            $table->string('normalized_line_description', 512)->nullable();
            $table->string('raw_text_hash', 64)->nullable();

            $table->string('analyzer_version')->nullable();
            $table->string('analyzer_line_type')->nullable();
            $table->string('analyzer_suggested_category')->nullable();
            $table->unsignedTinyInteger('registerable_score')->nullable();
            $table->unsignedTinyInteger('non_product_score')->nullable();

            $table->json('signals')->nullable();
            $table->json('negative_signals')->nullable();
            $table->json('warnings')->nullable();
            $table->json('score_breakdown')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique('candidate_id', 'puf_candidate_unique');
            $table->index(['team_id', 'review_status'], 'puf_team_status_idx');
            $table->index(['document_id', 'review_status'], 'puf_doc_status_idx');
            $table->index('raw_text_hash', 'puf_raw_hash_idx');

            $table->foreign('team_id', 'puf_team_fk')
                ->references('id')
                ->on('teams')
                ->nullOnDelete();

            $table->foreign('document_id', 'puf_document_fk')
                ->references('id')
                ->on('documents')
                ->nullOnDelete();

            $table->foreign('document_line_id', 'puf_line_fk')
                ->references('id')
                ->on('document_lines')
                ->nullOnDelete();

            $table->foreign('candidate_id', 'puf_candidate_fk')
                ->references('id')
                ->on('product_identification_candidates')
                ->nullOnDelete();

            $table->foreign('product_id', 'puf_product_fk')
                ->references('id')
                ->on('products')
                ->nullOnDelete();

            $table->foreign('reviewed_by_user_id', 'puf_user_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    /**
     * Elimina il dataset interno di feedback.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_understanding_feedback');
    }
};