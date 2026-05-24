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
        Schema::create('data_deletion_requests', function (Blueprint $table) {
            $table->id();

            // Team/workspace a cui si riferisce la richiesta.
            // Nullable perché alcune richieste potrebbero riguardare solo l'utente.
            $table->foreignId('team_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Utente che ha richiesto la cancellazione/esportazione.
            $table->foreignId('requested_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Tipo di richiesta.
            // Esempi: account_deletion, document_deletion, product_deletion, data_export.
            $table->string('request_type');

            // Stato della richiesta.
            // Esempi: pending, approved, processing, completed, rejected, cancelled.
            $table->string('status')->default('pending');

            // Risorsa specifica collegata alla richiesta, se presente.
            // Esempi: Document, Product, Team.
            $table->nullableMorphs('deletable');

            // Motivo opzionale indicato dall'utente o dal sistema.
            $table->text('reason')->nullable();

            // Note interne opzionali.
            $table->text('internal_notes')->nullable();

            // Metadati tecnici opzionali.
            // Esempi: numero documenti coinvolti, storage stimato, file export generato.
            $table->json('metadata')->nullable();

            // Date operative.
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['team_id', 'status'], 'ddr_team_status_idx');
            $table->index(['requested_by_user_id', 'status'], 'ddr_user_status_idx');
            $table->index(['request_type', 'status'], 'ddr_type_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('data_deletion_requests');
    }
};
