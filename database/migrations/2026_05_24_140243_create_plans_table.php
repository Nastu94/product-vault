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
        Schema::create('plans', function (Blueprint $table) {
            $table->id();

            // Codice tecnico del piano.
            // Esempi: free, personal, family, shop, business.
            $table->string('code')->unique();

            // Nome leggibile del piano.
            $table->string('name');

            // Descrizione opzionale del piano.
            $table->text('description')->nullable();

            // Prezzo mensile indicativo in centesimi.
            // Per MVP può restare 0.
            $table->unsignedInteger('monthly_price_cents')->default(0);

            // Valuta del prezzo.
            $table->foreignId('currency_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Indica se il piano è visibile/selezionabile.
            $table->boolean('is_active')->default(true);

            // Ordinamento nella UI futura.
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['is_active', 'sort_order'], 'plans_active_sort_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
