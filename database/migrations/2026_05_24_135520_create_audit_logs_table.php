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
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Team/workspace in cui è avvenuta l'azione.
            $table->foreignId('team_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Utente che ha eseguito l'azione.
            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Azione registrata.
            // Esempi: document.uploaded, document.viewed, product.confirmed.
            $table->string('action');

            // Risorsa collegata all'azione.
            // Esempi: Document, Product, Warranty.
            $table->nullableMorphs('auditable');

            // Dati tecnici della richiesta.
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // Metadati opzionali, evitando di salvare contenuto sensibile del documento.
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['team_id', 'action'], 'al_team_action_idx');
            $table->index(['user_id', 'action'], 'al_user_action_idx');
            $table->index(['action', 'created_at'], 'al_action_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
