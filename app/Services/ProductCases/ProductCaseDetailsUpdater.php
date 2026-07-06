<?php

namespace App\Services\ProductCases;

use App\Models\ProductCase;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;

final class ProductCaseDetailsUpdater
{
    public const VERSION =
        'product_case_details_updater_v1';

    /**
     * Ordine stabile dei campi modificabili.
     *
     * @var list<string>
     */
    private const EDITABLE_FIELDS = [
        'title',
        'description',
        'occurred_on',
        'usability_status',
        'accidental_damage_declared',
        'accidental_damage_notes',
    ];

    public function __construct(
        private readonly ProductCaseEventRecorder $eventRecorder
    ) {
    }

    /**
     * Aggiorna i dati iniziali della pratica.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(
        ProductCase $productCase,
        User $updatedBy,
        array $attributes
    ): ProductCase {
        $productCaseId =
            $productCase->getKey();

        $userId =
            $updatedBy->getKey();

        if ($productCaseId === null) {
            throw new RuntimeException(
                'La pratica deve essere persistita prima di modificarne i dati.'
            );
        }

        if ($userId === null) {
            throw new RuntimeException(
                'L’utente deve essere persistito prima di modificare la pratica.'
            );
        }

        $input =
            $this->normalizeInput(
                $attributes
            );

        $validated = Validator::make(
            $input,
            [
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
                    'required',
                    'string',
                    Rule::in(
                        ProductCase::USABILITY_STATUSES
                    ),
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
            ]
        )->validate();

        $nextState =
            $this->stateFromValidated(
                $validated
            );

        return DB::transaction(function () use (
            $productCaseId,
            $userId,
            $nextState
        ): ProductCase {
            $productCase = ProductCase::query()
                ->with('team')
                ->lockForUpdate()
                ->find(
                    $productCaseId
                );

            if ($productCase === null) {
                throw new RuntimeException(
                    'La pratica non è più disponibile.'
                );
            }

            $updatedBy = User::query()
                ->lockForUpdate()
                ->find(
                    $userId
                );

            if ($updatedBy === null) {
                throw new RuntimeException(
                    'L’utente non è più disponibile.'
                );
            }

            $this->ensureUserCanManageCase(
                productCase:
                    $productCase,

                user:
                    $updatedBy,
            );

            $this->ensureCaseIsEditable(
                $productCase
            );

            $previousState =
                $this->stateFromCase(
                    $productCase
                );

            $changedFields = [];

            foreach (
                self::EDITABLE_FIELDS
                as $field
            ) {
                if (
                    $previousState[$field]
                    !== $nextState[$field]
                ) {
                    $changedFields[] =
                        $field;
                }
            }

            /*
             * Stesso stato normalizzato: nessun save, timestamp o evento.
             */
            if ($changedFields === []) {
                return $productCase->refresh();
            }

            $previousSnapshot =
                $this->eventSnapshot(
                    $previousState
                );

            /*
             * original_description, status, bozza, outcome e metadata
             * non fanno parte dell’aggiornamento.
             */
            $productCase->forceFill([
                'title' =>
                    $nextState['title'],

                'description' =>
                    $nextState['description'],

                'occurred_on' =>
                    $nextState['occurred_on'],

                'usability_status' =>
                    $nextState[
                        'usability_status'
                    ],

                'accidental_damage_declared' =>
                    $nextState[
                        'accidental_damage_declared'
                    ],

                'accidental_damage_notes' =>
                    $nextState[
                        'accidental_damage_notes'
                    ],
            ]);

            $productCase->save();

            $now = now();

            $this->eventRecorder
                ->recordCaseDetailsUpdated(
                    productCase:
                        $productCase,

                    actor:
                        $updatedBy,

                    changedFields:
                        $changedFields,

                    previousSnapshot:
                        $previousSnapshot,

                    currentSnapshot:
                        $this->eventSnapshot(
                            $nextState
                        ),

                    updaterVersion:
                        self::VERSION,

                    occurredAt:
                        $now,
                );

            return $productCase->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalizeInput(
        array $attributes
    ): array {
        $input = Arr::only(
            $attributes,
            self::EDITABLE_FIELDS
        );

        foreach ([
            'title',
            'description',
            'usability_status',
        ] as $field) {
            if (
                array_key_exists(
                    $field,
                    $input
                )
                && is_string(
                    $input[$field]
                )
            ) {
                $input[$field] =
                    trim(
                        $input[$field]
                    );
            }
        }

        foreach ([
            'occurred_on',
            'accidental_damage_notes',
        ] as $field) {
            if (
                ! array_key_exists(
                    $field,
                    $input
                )
                || ! is_string(
                    $input[$field]
                )
            ) {
                continue;
            }

            $input[$field] =
                trim(
                    $input[$field]
                );

            if ($input[$field] === '') {
                $input[$field] =
                    null;
            }
        }

        if (
            array_key_exists(
                'accidental_damage_declared',
                $input
            )
        ) {
            $input[
                'accidental_damage_declared'
            ] = $this->normalizeNullableBoolean(
                $input[
                    'accidental_damage_declared'
                ]
            );
        }

        return $input;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function stateFromValidated(
        array $validated
    ): array {
        $accidentalDamageDeclared =
            array_key_exists(
                'accidental_damage_declared',
                $validated
            )
                ? $validated[
                    'accidental_damage_declared'
                ]
                : null;

        $accidentalDamageNotes =
            $accidentalDamageDeclared === true
                ? (
                    $validated[
                        'accidental_damage_notes'
                    ] ?? null
                )
                : null;

        return [
            'title' =>
                $validated['title'],

            'description' =>
                $validated['description'],

            'occurred_on' =>
                isset(
                    $validated['occurred_on']
                )
                    ? Carbon::parse(
                        $validated[
                            'occurred_on'
                        ]
                    )->toDateString()
                    : null,

            'usability_status' =>
                $validated[
                    'usability_status'
                ],

            'accidental_damage_declared' =>
                $accidentalDamageDeclared,

            'accidental_damage_notes' =>
                $accidentalDamageNotes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stateFromCase(
        ProductCase $productCase
    ): array {
        return [
            'title' =>
                $productCase->title,

            'description' =>
                $productCase->description,

            'occurred_on' =>
                $productCase
                    ->occurred_on
                    ?->toDateString(),

            'usability_status' =>
                $productCase
                    ->usability_status,

            'accidental_damage_declared' =>
                $productCase
                    ->accidental_damage_declared,

            'accidental_damage_notes' =>
                $productCase
                    ->accidental_damage_notes,
        ];
    }

    /**
     * Snapshot senza testi in chiaro.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function eventSnapshot(
        array $state
    ): array {
        $notes =
            $state[
                'accidental_damage_notes'
            ];

        return [
            'title_sha256' =>
                hash(
                    'sha256',
                    $state['title']
                ),

            'description_sha256' =>
                hash(
                    'sha256',
                    $state['description']
                ),

            'occurred_on' =>
                $state['occurred_on'],

            'usability_status' =>
                $state[
                    'usability_status'
                ],

            'accidental_damage_declared' =>
                $state[
                    'accidental_damage_declared'
                ],

            'accidental_damage_notes_sha256' =>
                is_string($notes)
                    ? hash(
                        'sha256',
                        $notes
                    )
                    : null,
        ];
    }

    private function ensureUserCanManageCase(
        ProductCase $productCase,
        User $user
    ): void {
        if (
            $productCase->team_id === null
            || $productCase->team === null
        ) {
            throw new RuntimeException(
                'La pratica non appartiene a un team valido.'
            );
        }

        if (
            (int) $user->current_team_id
                !== (int) $productCase->team_id
            || ! $user->belongsToTeam(
                $productCase->team
            )
        ) {
            throw new RuntimeException(
                'L’utente non può modificare una pratica appartenente a un altro team.'
            );
        }
    }

    private function ensureCaseIsEditable(
        ProductCase $productCase
    ): void {
        if (
            ! in_array(
                $productCase->status,
                ProductCase::STATUSES,
                true
            )
        ) {
            throw new RuntimeException(
                'Lo stato corrente della pratica non è valido.'
            );
        }

        if (
            $productCase->status
                !== ProductCase::STATUS_DRAFT
        ) {
            throw new RuntimeException(
                'I dati iniziali possono essere modificati soltanto mentre la pratica è in bozza.'
            );
        }
    }

    private function normalizeNullableBoolean(
        mixed $value
    ): mixed {
        return match (true) {
            $value === null,
            $value === '' =>
                null,

            $value === true,
            $value === 1,
            $value === '1' =>
                true,

            $value === false,
            $value === 0,
            $value === '0' =>
                false,

            default =>
                $value,
        };
    }
}