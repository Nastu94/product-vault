<?php

namespace App\Services\ProductCases;

use App\Models\Product;
use App\Models\ProductCase;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;

class ProductCaseCreator
{
    /**
     * @param  ProductCaseEventRecorder  $eventRecorder
     */
    public function __construct(
        private readonly ProductCaseEventRecorder $eventRecorder
    ) {
    }

    /**
     * Crea una nuova pratica operativa per un prodotto.
     *
     * Proprietà, utente, stato e descrizione originale vengono determinati
     * esclusivamente dal service e non dai dati ricevuti dalla UI.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(
        Product $product,
        User $openedBy,
        array $attributes
    ): ProductCase {
        $productId = $product->getKey();
        $userId = $openedBy->getKey();

        if ($productId === null) {
            throw new RuntimeException(
                'Il prodotto deve essere persistito prima di aprire una pratica.'
            );
        }

        if ($userId === null) {
            throw new RuntimeException(
                'L’utente deve essere persistito prima di aprire una pratica.'
            );
        }

        /*
         * Consideriamo esclusivamente i campi di contenuto ammessi.
         *
         * Eventuali team_id, product_id, status, original_description,
         * outcome, metadata o date operative ricevuti dal chiamante vengono
         * intenzionalmente ignorati.
         */
        $input = $this->normalizeInput($attributes);

        $validated = Validator::make($input, [
            'title' => [
                'required',
                'string',
                'max:255',
            ],
            'description' => [
                'required',
                'string',
                'max:20000',
            ],
            'occurred_on' => [
                'nullable',
                'date',
                'before_or_equal:today',
            ],
            'usability_status' => [
                'sometimes',
                'required',
                'string',
                Rule::in(ProductCase::USABILITY_STATUSES),
            ],
            'accidental_damage_declared' => [
                'nullable',
                'boolean',
            ],
            'accidental_damage_notes' => [
                'nullable',
                'string',
                'max:10000',
            ],
        ])->validate();

        return DB::transaction(function () use (
            $productId,
            $userId,
            $validated
        ): ProductCase {
            /*
             * Il prodotto viene ricaricato dentro la transazione.
             *
             * Non utilizziamo direttamente l'istanza ricevuta perché potrebbe
             * essere obsoleta o essere stata modificata dopo il caricamento.
             */
            $product = Product::query()
                ->with('team')
                ->lockForUpdate()
                ->find($productId);

            if ($product === null) {
                throw new RuntimeException(
                    'Il prodotto non è più disponibile.'
                );
            }

            $openedBy = User::query()
                ->lockForUpdate()
                ->find($userId);

            if ($openedBy === null) {
                throw new RuntimeException(
                    'L’utente non è più disponibile.'
                );
            }

            if (
                $product->team_id === null
                || $product->team === null
            ) {
                throw new RuntimeException(
                    'Il prodotto non appartiene a un team valido.'
                );
            }

            /*
             * La policy controllerà i permessi specifici.
             *
             * Il service mantiene comunque un guardrail indipendente:
             * l'utente deve appartenere al team del prodotto e deve averlo
             * selezionato come workspace corrente.
             */
            if (
                (int) $openedBy->current_team_id
                    !== (int) $product->team_id
                || ! $openedBy->belongsToTeam($product->team)
            ) {
                throw new RuntimeException(
                    'L’utente non può aprire una pratica per il team del prodotto.'
                );
            }

            $productCase = new ProductCase();

            /*
             * forceFill è intenzionale.
             *
             * Questi sono campi protetti dal mass assignment e vengono
             * valorizzati soltanto dopo i controlli del service.
             */
            $productCase->forceFill([
                'team_id' => $product->team_id,
                'product_id' => $product->id,
                'opened_by_user_id' => $openedBy->id,
                'status' => ProductCase::STATUS_DRAFT,
                'title' => $validated['title'],
                'original_description' =>
                    $validated['description'],
                'description' => $validated['description'],
                'occurred_on' =>
                    $validated['occurred_on'] ?? null,
                'usability_status' =>
                    $validated['usability_status']
                    ?? ProductCase::USABILITY_UNKNOWN,
                'accidental_damage_declared' =>
                    array_key_exists(
                        'accidental_damage_declared',
                        $validated
                    )
                        ? $validated['accidental_damage_declared']
                        : null,
                'accidental_damage_notes' =>
                    $validated['accidental_damage_notes'] ?? null,
                'opened_at' => now(),
            ]);

            $productCase->save();

            /*
             * L'evento fa parte della stessa transazione.
             *
             * Un errore nella registrazione impedisce di creare una pratica
             * priva del relativo evento di apertura.
             */
            $this->eventRecorder
                ->recordCaseOpened(
                    productCase:
                        $productCase,
                    actor:
                        $openedBy,
                );

            return $productCase->refresh();
        });
    }

    /**
     * Mantiene soltanto i campi ammessi e normalizza gli spazi esterni.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalizeInput(array $attributes): array
    {
        $input = Arr::only($attributes, [
            'title',
            'description',
            'occurred_on',
            'usability_status',
            'accidental_damage_declared',
            'accidental_damage_notes',
        ]);

        foreach ([
            'title',
            'description',
            'usability_status',
        ] as $field) {
            if (
                array_key_exists($field, $input)
                && is_string($input[$field])
            ) {
                $input[$field] = trim($input[$field]);
            }
        }

        foreach ([
            'occurred_on',
            'accidental_damage_notes',
        ] as $field) {
            if (
                ! array_key_exists($field, $input)
                || ! is_string($input[$field])
            ) {
                continue;
            }

            $input[$field] = trim($input[$field]);

            if ($input[$field] === '') {
                $input[$field] = null;
            }
        }

        return $input;
    }
}
