<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crea una tabella aggregata globale per conoscenza prodotto.
     *
     * Non contiene dati personali o riferimenti diretti a documenti/team/utenti.
     */
    public function up(): void
    {
        Schema::create('product_understanding_global_facts', function (Blueprint $table) {
            $table->id();

            $table->string('fact_type')->index(); // ean
            $table->string('fact_key', 128);
            $table->string('fact_value', 128)->nullable();

            $table->string('canonical_name')->nullable();
            $table->string('suggested_category')->nullable();
            $table->string('suggested_line_type')->nullable();

            $table->unsignedInteger('seen_count')->default(0);
            $table->unsignedInteger('confirmed_count')->default(0);
            $table->unsignedInteger('ignored_count')->default(0);

            $table->decimal('global_registration_rate', 5, 2)->nullable();
            $table->unsignedTinyInteger('global_product_confidence_score')->default(0);

            $table->json('canonical_name_counts')->nullable();
            $table->json('category_counts')->nullable();
            $table->json('line_type_counts')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['fact_type', 'fact_key'], 'pugf_type_key_unique');
            $table->index(['fact_type', 'fact_value'], 'pugf_type_value_idx');
            $table->index('suggested_category', 'pugf_category_idx');
        });
    }

    /**
     * Rimuove la tabella aggregata globale.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_understanding_global_facts');
    }
};