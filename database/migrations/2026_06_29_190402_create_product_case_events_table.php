<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crea la timeline operativa append-only delle pratiche prodotto.
     */
    public function up(): void
    {
        Schema::create(
            'product_case_events',
            function (Blueprint $table): void {
                $table->id();

                /*
                |--------------------------------------------------------------
                | Pratica e autore
                |--------------------------------------------------------------
                */

                $table->foreignId('product_case_id')
                    ->constrained()
                    ->cascadeOnDelete();

                $table->foreignId('actor_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                /*
                |--------------------------------------------------------------
                | Evento
                |--------------------------------------------------------------
                */

                $table->string(
                    'event_type',
                    80
                );

                $table->string('title');

                $table->text(
                    'description'
                )->nullable();

                $table->string(
                    'source',
                    100
                );

                /*
                 * Momento operativo dell'evento.
                 *
                 * È distinto da created_at perché, in futuro, alcuni eventi
                 * potranno essere registrati dopo il momento reale.
                 */
                $table->timestamp(
                    'occurred_at'
                );

                /*
                 * Payload tecnico e snapshot dei valori coinvolti.
                 *
                 * I dati business principali restano nelle rispettive tabelle.
                 */
                $table->json(
                    'metadata'
                )->nullable();

                $table->timestamps();

                $table->index(
                    [
                        'product_case_id',
                        'occurred_at',
                    ],
                    'pce_case_occurred_idx'
                );

                $table->index(
                    [
                        'product_case_id',
                        'event_type',
                    ],
                    'pce_case_type_idx'
                );

                $table->index(
                    [
                        'actor_user_id',
                        'occurred_at',
                    ],
                    'pce_actor_occurred_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'product_case_events'
        );
    }
};