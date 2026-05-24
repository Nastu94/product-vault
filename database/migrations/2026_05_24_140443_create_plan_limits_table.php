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
        Schema::create('plan_limits', function (Blueprint $table) {
            $table->id();

            // Piano a cui appartiene il limite.
            $table->foreignId('plan_id')
                ->constrained()
                ->cascadeOnDelete();

            // Chiave tecnica del limite.
            // Esempi: max_documents, max_products, max_storage_mb, max_ocr_per_month.
            $table->string('limit_key');

            // Valore numerico del limite.
            // Nullable può indicare limite illimitato.
            $table->unsignedInteger('limit_value')->nullable();

            // Periodo di reset del limite.
            // Esempi: none, daily, monthly, yearly.
            $table->string('reset_period')->default('none');

            // Descrizione leggibile del limite.
            $table->text('description')->nullable();

            // Metadati opzionali per configurazioni future.
            $table->json('metadata')->nullable();

            // Permette di disattivare un limite senza eliminarlo.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['plan_id', 'limit_key'], 'pl_plan_key_unique');
            $table->index(['limit_key', 'is_active'], 'pl_key_active_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plan_limits');
    }
};
