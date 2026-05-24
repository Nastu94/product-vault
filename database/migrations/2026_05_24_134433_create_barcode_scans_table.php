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
        Schema::create('barcode_scans', function (Blueprint $table) {
            $table->id();

            // Prodotto collegato al barcode, se già esiste.
            // Nullable perché un barcode può essere letto prima della creazione del prodotto.
            $table->foreignId('product_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Documento da cui è stato ricavato il barcode, se presente.
            $table->foreignId('document_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Riga documento collegata al barcode, se il barcode è associato a una riga specifica.
            $table->foreignId('document_line_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Utente che ha eseguito o confermato la scansione.
            $table->foreignId('scanned_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Tipo di barcode.
            // Esempi: ean13, ean8, upc, qr, code128, unknown.
            $table->string('barcode_type')->nullable();

            // Valore letto dal barcode.
            $table->string('barcode_value')->index();

            // Fonte della lettura.
            // Esempi: document_ocr, image_scan, user_manual, mobile_camera, parser.
            $table->string('source')->default('user_manual');

            // Punteggio di affidabilità della lettura.
            $table->unsignedTinyInteger('confidence_score')->nullable();

            // Indica se questo barcode è stato confermato dall'utente.
            $table->boolean('is_confirmed')->default(false);

            // Metadati tecnici opzionali.
            // Esempi: engine usato, coordinate immagine, pagina PDF, raw result.
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['product_id', 'barcode_value'], 'bs_product_value_idx');
            $table->index(['document_id', 'barcode_value'], 'bs_document_value_idx');
            $table->index(['document_line_id', 'barcode_value'], 'bs_line_value_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('barcode_scans');
    }
};
