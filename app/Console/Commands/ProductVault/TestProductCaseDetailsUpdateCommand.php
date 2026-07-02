<?php

namespace App\Console\Commands\ProductVault;

use App\Models\Product;
use App\Models\ProductCase;
use App\Models\ProductCaseEvent;
use App\Models\User;
use App\Services\ProductCases\ProductCaseCreator;
use App\Services\ProductCases\ProductCaseDetailsUpdater;
use App\Services\ProductCases\ProductCaseStatusTransitionService;
use App\Services\ProductCases\ProductCaseTimelineResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

final class TestProductCaseDetailsUpdateCommand
    extends Command
{
    protected $signature =
        'product-vault:test-product-case-details-update';

    protected $description =
        'Verifica con rollback la modifica controllata dei dati iniziali della pratica.';

    public function handle(
        ProductCaseCreator $creator,
        ProductCaseDetailsUpdater $updater,
        ProductCaseStatusTransitionService $transitionService,
        ProductCaseTimelineResolver $timelineResolver
    ): int {
        $rows = [];
        $failures = [];

        $createdCaseId = null;

        $casesBefore =
            ProductCase::query()->count();

        $eventsBefore =
            ProductCaseEvent::query()->count();

        $teamsBefore =
            DB::table('teams')->count();

        $permissionRegistrar =
            app(
                PermissionRegistrar::class
            );

        $assertSame = function (
            string $scenario,
            string $assertion,
            mixed $expected,
            mixed $actual
        ) use (&$rows, &$failures): void {
            $passed =
                $expected === $actual;

            $rows[] = [
                $scenario,
                $assertion,
                $passed ? 'OK' : 'FAIL',
            ];

            if (! $passed) {
                $failures[] = [
                    'scenario' =>
                        $scenario,

                    'assertion' =>
                        $assertion,

                    'expected' =>
                        $expected,

                    'actual' =>
                        $actual,
                ];
            }
        };

        DB::beginTransaction();

        try {
            $product = Product::query()
                ->with('team')
                ->whereNotNull(
                    'team_id'
                )
                ->orderBy('id')
                ->first();

            if (
                $product === null
                || $product->team === null
            ) {
                throw new RuntimeException(
                    'Nessun prodotto con team utilizzabile per il test.'
                );
            }

            $user = User::query()
                ->find(
                    $product
                        ->team
                        ->user_id
                );

            if ($user === null) {
                throw new RuntimeException(
                    'Nessun utente utilizzabile per il test.'
                );
            }

            User::query()
                ->whereKey(
                    $user->id
                )
                ->update([
                    'current_team_id' =>
                        $product->team_id,
                ]);

            $user->refresh();

            $permissionRegistrar
                ->setPermissionsTeamId(
                    $product->team_id
                );

            $user->unsetRelation('roles');
            $user->unsetRelation('permissions');

            $originalDescription =
                'Descrizione originale della pratica.';

            $productCase =
                $creator->create(
                    product:
                        $product,

                    openedBy:
                        $user,

                    attributes: [
                        'title' =>
                            'Problema iniziale',

                        'description' =>
                            $originalDescription,

                        'occurred_on' =>
                            today()
                                ->toDateString(),

                        'usability_status' =>
                            ProductCase
                                ::USABILITY_UNKNOWN,

                        'accidental_damage_declared' =>
                            null,
                    ],
                );

            $createdCaseId =
                (int) $productCase->id;

            $metadataBefore =
                $productCase->metadata;

            $draftBefore =
                $productCase
                    ->request_draft;

            $eventsBeforeUpdate =
                ProductCaseEvent::query()
                    ->count();

            $updatedCase =
                $updater->update(
                    productCase:
                        $productCase,

                    updatedBy:
                        $user,

                    attributes: [
                        'title' =>
                            '  Problema aggiornato  ',

                        'description' =>
                            '  Il prodotto funziona soltanto in parte.  ',

                        'occurred_on' =>
                            today()
                                ->subDay()
                                ->toDateString(),

                        'usability_status' =>
                            ProductCase
                                ::USABILITY_PARTIALLY_USABLE,

                        'accidental_damage_declared' =>
                            true,

                        'accidental_damage_notes' =>
                            '  Possibile urto laterale.  ',
                    ],
                );

            $assertSame(
                'update',
                'title normalized',
                'Problema aggiornato',
                $updatedCase->title
            );

            $assertSame(
                'update',
                'description normalized',
                'Il prodotto funziona soltanto in parte.',
                $updatedCase->description
            );

            $assertSame(
                'update',
                'original description preserved',
                $originalDescription,
                $updatedCase
                    ->original_description
            );

            $assertSame(
                'update',
                'occurrence date updated',
                today()
                    ->subDay()
                    ->toDateString(),
                $updatedCase
                    ->occurred_on
                    ?->toDateString()
            );

            $assertSame(
                'update',
                'usability updated',
                ProductCase
                    ::USABILITY_PARTIALLY_USABLE,
                $updatedCase
                    ->usability_status
            );

            $assertSame(
                'update',
                'damage declaration updated',
                true,
                $updatedCase
                    ->accidental_damage_declared
            );

            $assertSame(
                'update',
                'damage notes normalized',
                'Possibile urto laterale.',
                $updatedCase
                    ->accidental_damage_notes
            );

            $assertSame(
                'protection',
                'status remains draft',
                ProductCase::STATUS_DRAFT,
                $updatedCase->status
            );

            $assertSame(
                'protection',
                'metadata unchanged',
                $metadataBefore,
                $updatedCase->metadata
            );

            $assertSame(
                'protection',
                'request draft unchanged',
                $draftBefore,
                $updatedCase
                    ->request_draft
            );

            $assertSame(
                'event',
                'one update event created',
                $eventsBeforeUpdate + 1,
                ProductCaseEvent::query()
                    ->count()
            );

            $updateEvent =
                $updatedCase
                    ->events()
                    ->where(
                        'event_type',
                        ProductCaseEvent
                            ::TYPE_CASE_DETAILS_UPDATED
                    )
                    ->first();

            $assertSame(
                'event',
                'update event available',
                true,
                $updateEvent !== null
            );

            $assertSame(
                'event',
                'actor stored',
                (int) $user->id,
                (int) $updateEvent
                    ?->actor_user_id
            );

            $assertSame(
                'event',
                'stable changed fields',
                [
                    'title',
                    'description',
                    'occurred_on',
                    'usability_status',
                    'accidental_damage_declared',
                    'accidental_damage_notes',
                ],
                data_get(
                    $updateEvent?->metadata,
                    'changed_fields'
                )
            );

            $encodedMetadata =
                json_encode(
                    $updateEvent?->metadata
                );

            $assertSame(
                'privacy',
                'description not stored in event',
                false,
                is_string($encodedMetadata)
                    && str_contains(
                        $encodedMetadata,
                        'Il prodotto funziona'
                    )
            );

            $assertSame(
                'privacy',
                'damage notes not stored in event',
                false,
                is_string($encodedMetadata)
                    && str_contains(
                        $encodedMetadata,
                        'Possibile urto'
                    )
            );

            /*
             |--------------------------------------------------------------------------
             | Timeline normalizzata
             |--------------------------------------------------------------------------
             */

            $timeline =
                $timelineResolver->resolve(
                    $updatedCase
                );

            $timelineEvent =
                collect(
                    $timeline['events']
                )
                    ->where(
                        'type',
                        ProductCaseEvent
                            ::TYPE_CASE_DETAILS_UPDATED
                    )
                    ->last();

            $assertSame(
                'timeline',
                'event recognized',
                true,
                data_get(
                    $timelineEvent,
                    'is_known_type'
                )
            );

            $assertSame(
                'timeline',
                'event categorized as workflow',
                'workflow',
                data_get(
                    $timelineEvent,
                    'category'
                )
            );

            $assertSame(
                'timeline',
                'changed labels exposed',
                [
                    'Titolo',
                    'Descrizione',
                    'Data del problema',
                    'Utilizzabilità',
                    'Danno accidentale',
                    'Note sul danno',
                ],
                data_get(
                    $timelineEvent,
                    'details.changed_field_labels'
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Idempotenza
             |--------------------------------------------------------------------------
             */

            $timestampBeforeNoOp =
                $updatedCase
                    ->updated_at
                    ?->toISOString();

            $eventsBeforeNoOp =
                ProductCaseEvent::query()
                    ->count();

            $noOpCase =
                $updater->update(
                    productCase:
                        $updatedCase,

                    updatedBy:
                        $user,

                    attributes: [
                        'title' =>
                            ' Problema aggiornato ',

                        'description' =>
                            ' Il prodotto funziona soltanto in parte. ',

                        'occurred_on' =>
                            today()
                                ->subDay()
                                ->toDateString(),

                        'usability_status' =>
                            ProductCase
                                ::USABILITY_PARTIALLY_USABLE,

                        'accidental_damage_declared' =>
                            '1',

                        'accidental_damage_notes' =>
                            ' Possibile urto laterale. ',
                    ],
                );

            $assertSame(
                'idempotence',
                'same data creates no event',
                $eventsBeforeNoOp,
                ProductCaseEvent::query()
                    ->count()
            );

            $assertSame(
                'idempotence',
                'same data preserves timestamp',
                $timestampBeforeNoOp,
                $noOpCase
                    ->updated_at
                    ?->toISOString()
            );

            /*
             |--------------------------------------------------------------------------
             | Validazione
             |--------------------------------------------------------------------------
             */

            $eventsBeforeInvalid =
                ProductCaseEvent::query()
                    ->count();

            $invalidRejected =
                false;

            try {
                $updater->update(
                    productCase:
                        $updatedCase,

                    updatedBy:
                        $user,

                    attributes: [
                        'title' =>
                            'Titolo valido',

                        'description' =>
                            'Descrizione valida',

                        'occurred_on' =>
                            today()
                                ->addDay()
                                ->toDateString(),

                        'usability_status' =>
                            ProductCase
                                ::USABILITY_USABLE,

                        'accidental_damage_declared' =>
                            false,
                    ],
                );
            } catch (
                ValidationException
            ) {
                $invalidRejected =
                    true;
            }

            $assertSame(
                'validation',
                'future date rejected',
                true,
                $invalidRejected
            );

            $assertSame(
                'validation',
                'invalid update creates no event',
                $eventsBeforeInvalid,
                ProductCaseEvent::query()
                    ->count()
            );

            /*
             |--------------------------------------------------------------------------
             | Isolamento workspace
             |--------------------------------------------------------------------------
             */

            $otherTeamId =
                DB::table('teams')
                    ->insertGetId([
                        'user_id' =>
                            $user->id,

                        'name' =>
                            'Product Case Update '
                            . Str::uuid(),

                        'personal_team' =>
                            false,

                        'created_at' =>
                            now(),

                        'updated_at' =>
                            now(),
                    ]);

            User::query()
                ->whereKey(
                    $user->id
                )
                ->update([
                    'current_team_id' =>
                        $otherTeamId,
                ]);

            $user->refresh();

            $eventsBeforeCrossTeam =
                ProductCaseEvent::query()
                    ->count();

            $crossTeamRejected =
                false;

            try {
                $updater->update(
                    productCase:
                        $updatedCase,

                    updatedBy:
                        $user,

                    attributes: [
                        'title' =>
                            'Tentativo esterno',

                        'description' =>
                            $updatedCase
                                ->description,

                        'occurred_on' =>
                            $updatedCase
                                ->occurred_on
                                ?->toDateString(),

                        'usability_status' =>
                            $updatedCase
                                ->usability_status,

                        'accidental_damage_declared' =>
                            true,

                        'accidental_damage_notes' =>
                            $updatedCase
                                ->accidental_damage_notes,
                    ],
                );
            } catch (
                RuntimeException
            ) {
                $crossTeamRejected =
                    true;
            }

            $assertSame(
                'authorization',
                'cross-team update rejected',
                true,
                $crossTeamRejected
            );

            $assertSame(
                'authorization',
                'cross-team attempt creates no event',
                $eventsBeforeCrossTeam,
                ProductCaseEvent::query()
                    ->count()
            );

            User::query()
                ->whereKey(
                    $user->id
                )
                ->update([
                    'current_team_id' =>
                        $product->team_id,
                ]);

            $user->refresh();

            /*
             |--------------------------------------------------------------------------
             | Stato non modificabile
             |--------------------------------------------------------------------------
             */

            $cancelledCase =
                $transitionService
                    ->transition(
                        productCase:
                            $updatedCase->fresh(),

                        performedBy:
                            $user,

                        targetStatus:
                            ProductCase
                                ::STATUS_CANCELLED,
                    );

            $eventsBeforeTerminalAttempt =
                ProductCaseEvent::query()
                    ->count();

            $terminalRejected =
                false;

            try {
                $updater->update(
                    productCase:
                        $cancelledCase,

                    updatedBy:
                        $user,

                    attributes: [
                        'title' =>
                            'Modifica non ammessa',

                        'description' =>
                            $cancelledCase
                                ->description,

                        'occurred_on' =>
                            $cancelledCase
                                ->occurred_on
                                ?->toDateString(),

                        'usability_status' =>
                            $cancelledCase
                                ->usability_status,

                        'accidental_damage_declared' =>
                            true,

                        'accidental_damage_notes' =>
                            $cancelledCase
                                ->accidental_damage_notes,
                    ],
                );
            } catch (
                RuntimeException
            ) {
                $terminalRejected =
                    true;
            }

            $assertSame(
                'state',
                'non-draft update rejected',
                true,
                $terminalRejected
            );

            $assertSame(
                'state',
                'rejected update creates no event',
                $eventsBeforeTerminalAttempt,
                ProductCaseEvent::query()
                    ->count()
            );
        } catch (Throwable $exception) {
            $rows[] = [
                'runtime',
                'details update workflow completed',
                'FAIL',
            ];

            $failures[] = [
                'scenario' =>
                    'runtime',

                'assertion' =>
                    'details update workflow completed',

                'expected' =>
                    'no exception',

                'actual' =>
                    $exception::class
                    . ': '
                    . $exception
                        ->getMessage(),
            ];
        } finally {
            $permissionRegistrar
                ->setPermissionsTeamId(
                    null
                );

            DB::rollBack();
        }

        $assertSame(
            'rollback',
            'case count restored',
            $casesBefore,
            ProductCase::query()->count()
        );

        $assertSame(
            'rollback',
            'event count restored',
            $eventsBefore,
            ProductCaseEvent::query()->count()
        );

        $assertSame(
            'rollback',
            'team count restored',
            $teamsBefore,
            DB::table('teams')->count()
        );

        if ($createdCaseId !== null) {
            $assertSame(
                'rollback',
                'temporary case removed',
                false,
                ProductCase::query()
                    ->whereKey(
                        $createdCaseId
                    )
                    ->exists()
            );
        }

        $this->table(
            [
                'Scenario',
                'Assertion',
                'Status',
            ],
            $rows
        );

        if ($failures !== []) {
            foreach (
                $failures as $failure
            ) {
                $this->error(
                    $failure['scenario']
                    . ' / '
                    . $failure['assertion']
                );

                $this->line(
                    'Expected: '
                    . var_export(
                        $failure[
                            'expected'
                        ],
                        true
                    )
                );

                $this->line(
                    'Actual: '
                    . var_export(
                        $failure[
                            'actual'
                        ],
                        true
                    )
                );
            }

            return self::FAILURE;
        }

        $this->info(
            'Product case details update checks passed.'
        );

        return self::SUCCESS;
    }
}