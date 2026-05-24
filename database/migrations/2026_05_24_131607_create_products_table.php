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
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            // Team Jetstream usato come workspace/account proprietario del prodotto.
            $table->foreignId('team_id')
                ->constrained()
                ->cascadeOnDelete();

            // Utente che ha creato la scheda prodotto.
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Categoria prodotto.
            $table->foreignId('category_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Brand normalizzato del prodotto.
            $table->foreignId('brand_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Venditore presso cui è stato acquistato il prodotto.
            $table->foreignId('merchant_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Stato di identificazione del prodotto: unknown, partial, probable, user_confirmed, ecc.
            $table->foreignId('identification_status_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Valuta del prezzo di acquisto.
            $table->foreignId('currency_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Nome principale del prodotto.
            $table->string('name');

            // Modello commerciale o tecnico.
            $table->string('model')->nullable();

            // Numero seriale, se disponibile.
            $table->string('serial_number')->nullable()->index();

            // Codice EAN/GTIN/barcode principale, se disponibile.
            $table->string('ean_code')->nullable()->index();

            // Data di acquisto ricavata dal documento o inserita manualmente.
            $table->date('purchase_date')->nullable();

            // Prezzo di acquisto.
            $table->decimal('purchase_price', 10, 2)->nullable();

            // Punteggio di affidabilità dell'identificazione prodotto.
            $table->unsignedTinyInteger('reliability_score')->nullable();

            // Note manuali dell'utente.
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'name']);
            $table->index(['team_id', 'purchase_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
