<?php

namespace App\Services\ProductCases;

use App\Exceptions\ProductCases\ProductCaseNotReadyException;
use App\Models\ProductCase;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ProductCaseStatusTransitionService
{
    /**
     * @param  ProductCaseReadinessResolver  $readinessResolver
     */
    public function __construct(
        private readonly ProductCaseReadinessResolver $readinessResolver
    ) {
    }

    /**
     * Transizioni ammesse per ogni stato.
     *
     * Closed e cancelled sono terminali nel primo vertical slice.
     *
     * @var array<string, array<int, string>>
     */
    private const TRANSITIONS = [
        ProductCase::STATUS_DRAFT => [
            ProductCase::STATUS_READY_TO_CONTACT,
            ProductCase::STATUS_CANCELLED,
        ],

        ProductCase::STATUS_READY_TO_CONTACT => [
            ProductCase::STATUS_DRAFT,
            ProductCase::STATUS_CONTACTED,
            ProductCase::STATUS_CANCELLED,
        ],

        ProductCase::STATUS_CONTACTED => [
            ProductCase::STATUS_RESOLVED,
            ProductCase::STATUS_CANCELLED,
        ],

        ProductCase::STATUS_RESOLVED => [
            ProductCase::STATUS_CLOSED,
        ],

        ProductCase::STATUS_CLOSED => [],

        ProductCase::STATUS_CANCELLED => [],
    ];

    /**
     * Restituisce gli stati raggiungibili dallo stato corrente.
     *
     * @return array<int, string>
     */
    public function allowedTargets(
        ProductCase|string $source
    ): array {
        $status = $source instanceof ProductCase
            ? $source->status
            : $source;

        if (
            ! is_string($status)
            || ! in_array(
                $status,
                ProductCase::STATUSES,
                true
            )
        ) {
            throw new RuntimeException(
                'Lo stato corrente della pratica non è valido.'
            );
        }

        return self::TRANSITIONS[$status];
    }

    /**
     * Indica se una transizione è consentita.
     */
    public function canTransition(
        ProductCase|string $source,
        string $targetStatus
    ): bool {
        if (
            ! in_array(
                $targetStatus,
                ProductCase::STATUSES,
                true
            )
        ) {
            return false;
        }

        return in_array(
            $targetStatus,
            $this->allowedTargets($source),
            true
        );
    }

    /**
     * Applica una transizione controllata alla pratica.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function transition(
        ProductCase $productCase,
        User $performedBy,
        string $targetStatus,
        array $attributes = []
    ): ProductCase {
        $productCaseId = $productCase->getKey();
        $userId = $performedBy->getKey();

        if ($productCaseId === null) {
            throw new RuntimeException(
                'La pratica deve essere persistita prima di modificarne lo stato.'
            );
        }

        if ($userId === null) {
            throw new RuntimeException(
                'L’utente deve essere persistito prima di modificare la pratica.'
            );
        }

        if (
            ! in_array(
                $targetStatus,
                ProductCase::STATUSES,
                true
            )
        ) {
            throw new RuntimeException(
                'Lo stato di destinazione della pratica non è valido.'
            );
        }

        return DB::transaction(function () use (
            $productCaseId,
            $userId,
            $targetStatus,
            $attributes
        ): ProductCase {
            /*
             * Il lock serializza modifiche concorrenti sulla stessa pratica.
             */
            $productCase = ProductCase::query()
                ->with('team')
                ->lockForUpdate()
                ->find($productCaseId);

            if ($productCase === null) {
                throw new RuntimeException(
                    'La pratica non è più disponibile.'
                );
            }

            $performedBy = User::query()
                ->lockForUpdate()
                ->find($userId);

            if ($performedBy === null) {
                throw new RuntimeException(
                    'L’utente non è più disponibile.'
                );
            }

            if (
                $productCase->team_id === null
                || $productCase->team === null
            ) {
                throw new RuntimeException(
                    'La pratica non appartiene a un team valido.'
                );
            }

            /*
             * La policy controlla il permesso specifico.
             *
             * Il service conserva comunque un guardrail indipendente,
             * impedendo transizioni su pratiche di un workspace differente.
             */
            if (
                (int) $performedBy->current_team_id
                    !== (int) $productCase->team_id
                || ! $performedBy->belongsToTeam(
                    $productCase->team
                )
            ) {
                throw new RuntimeException(
                    'L’utente non può modificare una pratica appartenente a un altro team.'
                );
            }

            $currentStatus = $productCase->status;

            if (
                ! is_string($currentStatus)
                || ! in_array(
                    $currentStatus,
                    ProductCase::STATUSES,
                    true
                )
            ) {
                throw new RuntimeException(
                    'Lo stato corrente della pratica non è valido.'
                );
            }

            if (
                ! $this->canTransition(
                    $currentStatus,
                    $targetStatus
                )
            ) {
                throw new RuntimeException(
                    'Transizione pratica non consentita: '
                    . $currentStatus
                    . ' -> '
                    . $targetStatus
                    . '.'
                );
            }

            /*
             * Un record già marcato come resolved deve avere un esito valido
             * prima di poter essere definitivamente chiuso.
             */
            if (
                $targetStatus === ProductCase::STATUS_CLOSED
                && ! in_array(
                    $productCase->outcome,
                    ProductCase::OUTCOMES,
                    true
                )
            ) {
                throw new RuntimeException(
                    'La pratica risolta non contiene un esito valido.'
                );
            }

            $validated = $this->validateTransitionAttributes(
                targetStatus: $targetStatus,
                attributes: $attributes,
            );

            /*
             * La matrice delle transizioni resta strutturale.
             *
             * La readiness viene invece calcolata sui dati correnti della
             * pratica nei due passaggi che precedono il contatto effettivo:
             *
             * - draft -> ready_to_contact;
             * - ready_to_contact -> contacted.
             */
            if (
                $this->requiresReadinessCheck(
                    currentStatus: $currentStatus,
                    targetStatus: $targetStatus,
                )
            ) {
                $readiness =
                    $this->readinessResolver->resolve(
                        $productCase
                    );

                if (
                    (
                        $readiness[
                            'is_ready_to_contact'
                        ] ?? false
                    ) !== true
                ) {
                    throw new ProductCaseNotReadyException(
                        $readiness
                    );
                }
            }

            $now = now();

            $values = [
                'status' => $targetStatus,
            ];

            switch ($targetStatus) {
                case ProductCase::STATUS_CONTACTED:
                    $values['contacted_at'] = $now;
                    break;

                case ProductCase::STATUS_RESOLVED:
                    $values['outcome'] =
                        $validated['outcome'];

                    $values['resolution_notes'] =
                        $validated['resolution_notes']
                        ?? null;

                    $values['resolved_at'] = $now;
                    break;

                case ProductCase::STATUS_CLOSED:
                    $values['closed_at'] = $now;
                    break;

                case ProductCase::STATUS_CANCELLED:
                    $values['cancelled_at'] = $now;
                    break;
            }

            /*
             * Stato, esito e timestamp sono protetti dal mass assignment
             * e vengono scritti esclusivamente dal service.
             */
            $productCase->forceFill($values);
            $productCase->save();

            return $productCase->refresh();
        });
    }

    /**
     * Stabilisce se la transizione richiede una readiness aggiornata.
     */
    private function requiresReadinessCheck(
        string $currentStatus,
        string $targetStatus
    ): bool {
        return (
            $currentStatus === ProductCase::STATUS_DRAFT
            && $targetStatus
                === ProductCase::STATUS_READY_TO_CONTACT
        ) || (
            $currentStatus
                === ProductCase::STATUS_READY_TO_CONTACT
            && $targetStatus
                === ProductCase::STATUS_CONTACTED
        );
    }

    /**
     * Valida gli attributi specifici della transizione.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validateTransitionAttributes(
        string $targetStatus,
        array $attributes
    ): array {
        /*
         * Outcome e note di risoluzione possono essere registrati soltanto
         * durante la transizione verso resolved.
         */
        if (
            $targetStatus
                !== ProductCase::STATUS_RESOLVED
        ) {
            $errors = [];

            if (array_key_exists('outcome', $attributes)) {
                $errors['outcome'] =
                    'L’esito può essere registrato soltanto quando la pratica viene risolta.';
            }

            if (
                array_key_exists(
                    'resolution_notes',
                    $attributes
                )
            ) {
                $errors['resolution_notes'] =
                    'Le note di risoluzione possono essere registrate soltanto quando la pratica viene risolta.';
            }

            if ($errors !== []) {
                throw ValidationException::withMessages(
                    $errors
                );
            }

            return [];
        }

        $input = Arr::only($attributes, [
            'outcome',
            'resolution_notes',
        ]);

        foreach ([
            'outcome',
            'resolution_notes',
        ] as $field) {
            if (
                array_key_exists($field, $input)
                && is_string($input[$field])
            ) {
                $input[$field] =
                    trim($input[$field]);
            }
        }

        if (
            array_key_exists(
                'resolution_notes',
                $input
            )
            && $input['resolution_notes'] === ''
        ) {
            $input['resolution_notes'] = null;
        }

        return Validator::make($input, [
            'outcome' => [
                'required',
                'string',
                Rule::in(ProductCase::OUTCOMES),
            ],
            'resolution_notes' => [
                'nullable',
                'string',
                'max:20000',
            ],
        ])->validate();
    }
}