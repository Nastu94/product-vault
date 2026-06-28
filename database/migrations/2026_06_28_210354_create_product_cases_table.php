<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crea le pratiche operative collegate ai prodotti.
     *
     * Una pratica rappresenta un problema reale segnalato dall'utente.
     * Non sostituisce il prodotto, la garanzia, il documento o gli eventi
     * lifecycle, ma coordina queste informazioni in un workflow separato.
     */
    public function up(): void
    {
        Schema::create('product_cases', function (Blueprint $table) {
            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Proprietà e contesto
            |--------------------------------------------------------------------------
            |
            | Il team viene conservato direttamente per rendere esplicita
            | l'appartenenza della pratica al workspace e semplificare policy,
            | filtri e future dashboard.
            |
            | La logica applicativa dovrà garantire che team_id corrisponda
            | sempre al team_id del prodotto collegato.
            |
            */

            $table->foreignId('team_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('product_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('opened_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Stato della pratica
            |--------------------------------------------------------------------------
            |
            | Lo stato resta una stringa evolvibile anziché un enum database.
            | Le transizioni consentite verranno controllate da un service
            | dedicato in una micro-patch successiva.
            |
            | Stati inizialmente previsti:
            | draft, ready_to_contact, contacted, resolved, closed, cancelled.
            |
            */

            $table->string('status', 40)
                ->default('draft');

            $table->string('title');

            /*
            |--------------------------------------------------------------------------
            | Problema dichiarato dall'utente
            |--------------------------------------------------------------------------
            |
            | original_description conserva il testo iniziale e non dovrà essere
            | sovrascritto dalle modifiche successive.
            |
            | description rappresenta invece la descrizione corrente, che potrà
            | essere corretta o completata dall'utente.
            |
            */

            $table->text('original_description');
            $table->text('description');

            $table->date('occurred_on')
                ->nullable();

            /*
            | Valori previsti:
            | usable, partially_usable, unusable, unknown.
            */
            $table->string('usability_status', 40)
                ->default('unknown');

            /*
            | true  = l'utente dichiara un danno accidentale;
            | false = l'utente dichiara che non vi è stato;
            | null  = informazione non ancora fornita.
            */
            $table->boolean('accidental_damage_declared')
                ->nullable();

            $table->text('accidental_damage_notes')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Bozza della richiesta
            |--------------------------------------------------------------------------
            |
            | La bozza sarà generata in futuro da un builder deterministico e
            | resterà sempre modificabile. Nessuna comunicazione viene inviata
            | automaticamente.
            |
            */

            $table->longText('request_draft')
                ->nullable();

            $table->timestamp('request_draft_generated_at')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Esito
            |--------------------------------------------------------------------------
            |
            | outcome verrà valorizzato soltanto quando la pratica viene risolta.
            |
            | Esiti inizialmente previsti:
            | repaired, replaced, refunded, rejected, abandoned, other.
            |
            */

            $table->string('outcome', 40)
                ->nullable();

            $table->text('resolution_notes')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Date operative
            |--------------------------------------------------------------------------
            */

            $table->timestamp('opened_at')
                ->useCurrent();

            $table->timestamp('contacted_at')
                ->nullable();

            $table->timestamp('resolved_at')
                ->nullable();

            $table->timestamp('closed_at')
                ->nullable();

            $table->timestamp('cancelled_at')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Metadata tecnici
            |--------------------------------------------------------------------------
            |
            | I metadata potranno contenere provenance, versioni dei resolver
            | e snapshot tecnici. Non devono sostituire stato, esito o altri
            | dati business interrogabili.
            |
            */

            $table->json('metadata')
                ->nullable();

            $table->timestamps();
            $table->softDeletes();

            /*
            |--------------------------------------------------------------------------
            | Indici operativi
            |--------------------------------------------------------------------------
            */

            $table->index(
                ['team_id', 'status'],
                'pc_team_status_idx'
            );

            $table->index(
                ['product_id', 'status'],
                'pc_product_status_idx'
            );

            $table->index(
                ['team_id', 'product_id'],
                'pc_team_product_idx'
            );
        });
    }

    /**
     * Elimina la tabella delle pratiche.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_cases');
    }
};