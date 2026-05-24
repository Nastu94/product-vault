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
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();

            // Team/workspace a cui appartiene la preferenza.
            $table->foreignId('team_id')
                ->constrained()
                ->cascadeOnDelete();

            // Utente proprietario della preferenza.
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            // Chiave tecnica della notifica.
            // Esempi: document_processed, document_low_confidence, warranty_expiring.
            $table->string('notification_key');

            // Canale della notifica.
            // Esempi: database, email, sms, push.
            $table->string('channel')->default('database');

            // Indica se la notifica è abilitata.
            $table->boolean('is_enabled')->default(true);

            // Configurazioni opzionali della notifica.
            // Esempi: giorni prima della scadenza, fascia oraria, soglie score.
            $table->json('settings')->nullable();

            $table->timestamps();

            // Evita preferenze duplicate per stesso utente/team/notifica/canale.
            $table->unique([
                'team_id',
                'user_id',
                'notification_key',
                'channel',
            ], 'np_team_user_key_channel_unique');

            $table->index(['team_id', 'notification_key'], 'np_team_key_idx');
            $table->index(['user_id', 'notification_key'], 'np_user_key_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
