<?php

namespace App\Console\Commands\ProductVault;

use App\Models\Product;
use App\Models\ProductCase;
use App\Models\ProductCaseEvent;
use App\Models\User;
use App\Services\ProductCases\ProductCaseCreator;
use App\Services\ProductCases\ProductCaseDocumentSelector;
use App\Services\ProductCases\ProductCaseStatusTransitionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class TestProductCaseLifecycleEventsCommand
    extends Command
{
    /**
     * @var string
     */
    protected $signature =
        'product-vault:test-product-case-lifecycle-events';

    /**
     * @var string
     */
    protected $description =
        'Verifica con rollback la timeline append-only delle pratiche prodotto.';

    public function handle(
        ProductCaseCreator $creator,
        ProductCaseDocumentSelector $documentSelector,
        ProductCaseStatusTransitionService $transitionService
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

        $caseDocumentLinksBefore =
            DB::table(
                'product_case_documents'
            )->count();

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
                ->with([
                    'team',
                    'documents',
                    'warranties',
                ])
                ->whereNotNull(
                    'team_id'
                )
                ->whereHas(
                    'documents'
                )
                ->whereHas(
                    'warranties',
                    fn ($query) => $query
                        ->whereNotNull(
                            'starts_at'
                        )
                        ->whereNotNull(
                            'ends_at'
                        )
                )
                ->orderBy('id')
                ->first();

            if (
                $product === null
                || $product->team === null
                || $product
                    ->documents
                    ->isEmpty()
            ) {
                throw new RuntimeException(
                    'Nessun prodotto con team, documenti e garanzia completa utilizzabile per il test.'
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

            $document =
                $product
                    ->documents
                    ->first();

            if ($document === null) {
                throw new RuntimeException(
                    'Documento prodotto non disponibile.'
                );
            }

            /*
             |--------------------------------------------------------------------------
             | Apertura pratica
             |--------------------------------------------------------------------------
             */

            $productCase =
                $creator->create(
                    product:
                        $product,

                    openedBy:
                        $user,

                    attributes: [
                        'title' =>
                            'Timeline pratica prodotto',

                        'description' =>
                            'Pratica completa usata per verificare gli eventi lifecycle.',

                        'occurred_on' =>
                            today()
                                ->toDateString(),

                        'usability_status' =>
                            ProductCase
                                ::USABILITY_UNUSABLE,

                        'accidental_damage_declared' =>
                            false,
                    ],
                );

            $createdCaseId =
                (int) $productCase->id;

            $openingEvents =
                $productCase
                    ->events()
                    ->where(
                        'event_type',
                        ProductCaseEvent
                            ::TYPE_CASE_OPENED
                    )
                    ->get();

            $assertSame(
                'opening',
                'one opening event recorded',
                1,
                $openingEvents->count()
            );

            $openingEvent =
                $openingEvents->first();

            if (
                ! $openingEvent
                    instanceof ProductCaseEvent
            ) {
                throw new RuntimeException(
                    'Evento di apertura non disponibile.'
                );
            }

            $assertSame(
                'opening',
                'opening event type',
                ProductCaseEvent
                    ::TYPE_CASE_OPENED,
                $openingEvent->event_type
            );

            $assertSame(
                'opening',
                'opening event title',
                'Pratica aperta',
                $openingEvent->title
            );

            $assertSame(
                'opening',
                'opening event source',
                'product_case_creator',
                $openingEvent->source
            );

            $assertSame(
                'opening',
                'opening actor stored',
                (int) $user->id,
                (int) $openingEvent
                    ->actor_user_id
            );

            $assertSame(
                'opening',
                'initial status stored',
                ProductCase::STATUS_DRAFT,
                data_get(
                    $openingEvent->metadata,
                    'initial_status'
                )
            );

            $assertSame(
                'opening',
                'recorder version stored',
                'product_case_event_recorder_v1',
                data_get(
                    $openingEvent->metadata,
                    'recorder'
                )
            );

            $assertSame(
                'opening',
                'event timestamp matches opening',
                $productCase
                    ->opened_at
                    ?->toDateTimeString(),
                $openingEvent
                    ->occurred_at
                    ?->toDateTimeString()
            );

            $assertSame(
                'relations',
                'case relation contains event',
                true,
                $productCase
                    ->events()
                    ->whereKey(
                        $openingEvent->id
                    )
                    ->exists()
            );

            $assertSame(
                'relations',
                'event inverse relation contains case',
                true,
                $openingEvent
                    ->productCase
                    ?->is(
                        $productCase
                    ) === true
            );

            /*
             |--------------------------------------------------------------------------
             | Transizione non valida
             |--------------------------------------------------------------------------
             */

            $statusEventsBeforeInvalid =
                ProductCaseEvent::query()
                    ->where(
                        'product_case_id',
                        $productCase->id
                    )
                    ->where(
                        'event_type',
                        ProductCaseEvent
                            ::TYPE_STATUS_CHANGED
                    )
                    ->count();

            $invalidTransitionMessage =
                null;

            try {
                $transitionService
                    ->transition(
                        productCase:
                            $productCase,

                        performedBy:
                            $user,

                        targetStatus:
                            ProductCase
                                ::STATUS_CONTACTED,
                    );
            } catch (
                RuntimeException $exception
            ) {
                $invalidTransitionMessage =
                    $exception->getMessage();
            }

            $assertSame(
                'invalid_transition',
                'invalid transition rejected',
                'Transizione pratica non consentita: draft -> contacted.',
                $invalidTransitionMessage
            );

            $assertSame(
                'invalid_transition',
                'invalid transition creates no event',
                $statusEventsBeforeInvalid,
                ProductCaseEvent::query()
                    ->where(
                        'product_case_id',
                        $productCase->id
                    )
                    ->where(
                        'event_type',
                        ProductCaseEvent
                            ::TYPE_STATUS_CHANGED
                    )
                    ->count()
            );

            /*
             |--------------------------------------------------------------------------
             | Transizioni valide
             |--------------------------------------------------------------------------
             */

            $documentSelector->select(
                productCase:
                    $productCase,

                document:
                    $document,

                selectedBy:
                    $user,
            );

            $productCase =
                $transitionService
                    ->transition(
                        productCase:
                            $productCase,

                        performedBy:
                            $user,

                        targetStatus:
                            ProductCase
                                ::STATUS_READY_TO_CONTACT,
                    );

            $readyEvent =
                ProductCaseEvent::query()
                    ->where(
                        'product_case_id',
                        $productCase->id
                    )
                    ->where(
                        'event_type',
                        ProductCaseEvent
                            ::TYPE_STATUS_CHANGED
                    )
                    ->latest('id')
                    ->first();

            if (
                ! $readyEvent
                    instanceof ProductCaseEvent
            ) {
                throw new RuntimeException(
                    'Evento ready_to_contact non disponibile.'
                );
            }

            $assertSame(
                'status_transition',
                'ready event from status',
                ProductCase::STATUS_DRAFT,
                data_get(
                    $readyEvent->metadata,
                    'from_status'
                )
            );

            $assertSame(
                'status_transition',
                'ready event to status',
                ProductCase
                    ::STATUS_READY_TO_CONTACT,
                data_get(
                    $readyEvent->metadata,
                    'to_status'
                )
            );

            $assertSame(
                'status_transition',
                'ready event actor',
                (int) $user->id,
                (int) $readyEvent
                    ->actor_user_id
            );

            $productCase =
                $transitionService
                    ->transition(
                        productCase:
                            $productCase,

                        performedBy:
                            $user,

                        targetStatus:
                            ProductCase
                                ::STATUS_DRAFT,
                    );

            $statusEvents =
                ProductCaseEvent::query()
                    ->where(
                        'product_case_id',
                        $productCase->id
                    )
                    ->where(
                        'event_type',
                        ProductCaseEvent
                            ::TYPE_STATUS_CHANGED
                    )
                    ->orderBy('id')
                    ->get();

            $assertSame(
                'status_transition',
                'two status events recorded',
                2,
                $statusEvents->count()
            );

            $assertSame(
                'status_transition',
                'return to draft recorded',
                ProductCase::STATUS_DRAFT,
                data_get(
                    $statusEvents
                        ->last()
                        ?->metadata,
                    'to_status'
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Append-only
             |--------------------------------------------------------------------------
             */

            $immutableEvent =
                $statusEvents->last();

            if (
                ! $immutableEvent
                    instanceof ProductCaseEvent
            ) {
                throw new RuntimeException(
                    'Evento da proteggere non disponibile.'
                );
            }

            $originalTitle =
                $immutableEvent->title;

            $updateMessage =
                null;

            try {
                $immutableEvent
                    ->forceFill([
                        'title' =>
                            'Titolo alterato',
                    ]);

                $immutableEvent->save();
            } catch (
                RuntimeException $exception
            ) {
                $updateMessage =
                    $exception->getMessage();
            }

            $assertSame(
                'append_only',
                'event update rejected',
                'Gli eventi della pratica non possono essere modificati.',
                $updateMessage
            );

            $immutableEvent->refresh();

            $assertSame(
                'append_only',
                'event title preserved',
                $originalTitle,
                $immutableEvent->title
            );

            $deleteMessage =
                null;

            try {
                $immutableEvent->delete();
            } catch (
                RuntimeException $exception
            ) {
                $deleteMessage =
                    $exception->getMessage();
            }

            $assertSame(
                'append_only',
                'event deletion rejected',
                'Gli eventi della pratica non possono essere eliminati singolarmente.',
                $deleteMessage
            );

            $assertSame(
                'append_only',
                'event remains persisted',
                true,
                ProductCaseEvent::query()
                    ->whereKey(
                        $immutableEvent->id
                    )
                    ->exists()
            );

            /*
             |--------------------------------------------------------------------------
             | Isolamento team
             |--------------------------------------------------------------------------
             */

            $otherTeamId =
                DB::table('teams')
                    ->insertGetId([
                        'user_id' =>
                            $user->id,

                        'name' =>
                            'Product Case Events '
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

            $statusEventsBeforeCrossTeam =
                ProductCaseEvent::query()
                    ->where(
                        'product_case_id',
                        $productCase->id
                    )
                    ->where(
                        'event_type',
                        ProductCaseEvent
                            ::TYPE_STATUS_CHANGED
                    )
                    ->count();

            $crossTeamMessage =
                null;

            try {
                $transitionService
                    ->transition(
                        productCase:
                            $productCase,

                        performedBy:
                            $user,

                        targetStatus:
                            ProductCase
                                ::STATUS_CANCELLED,
                    );
            } catch (
                RuntimeException $exception
            ) {
                $crossTeamMessage =
                    $exception->getMessage();
            }

            $assertSame(
                'team_isolation',
                'cross-team transition rejected',
                'L’utente non può modificare una pratica appartenente a un altro team.',
                $crossTeamMessage
            );

            $assertSame(
                'team_isolation',
                'cross-team attempt creates no event',
                $statusEventsBeforeCrossTeam,
                ProductCaseEvent::query()
                    ->where(
                        'product_case_id',
                        $productCase->id
                    )
                    ->where(
                        'event_type',
                        ProductCaseEvent
                            ::TYPE_STATUS_CHANGED
                    )
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
             | Transizione terminale valida
             |--------------------------------------------------------------------------
             */

            $productCase =
                $transitionService
                    ->transition(
                        productCase:
                            $productCase,

                        performedBy:
                            $user,

                        targetStatus:
                            ProductCase
                                ::STATUS_CANCELLED,
                    );

            $cancelledEvent =
                ProductCaseEvent::query()
                    ->where(
                        'product_case_id',
                        $productCase->id
                    )
                    ->where(
                        'event_type',
                        ProductCaseEvent
                            ::TYPE_STATUS_CHANGED
                    )
                    ->latest('id')
                    ->first();

            if (
                ! $cancelledEvent
                    instanceof ProductCaseEvent
            ) {
                throw new RuntimeException(
                    'Evento cancelled non disponibile.'
                );
            }

            $assertSame(
                'terminal_transition',
                'cancelled transition recorded',
                ProductCase
                    ::STATUS_CANCELLED,
                data_get(
                    $cancelledEvent->metadata,
                    'to_status'
                )
            );

            $assertSame(
                'terminal_transition',
                'three status events recorded',
                3,
                ProductCaseEvent::query()
                    ->where(
                        'product_case_id',
                        $productCase->id
                    )
                    ->where(
                        'event_type',
                        ProductCaseEvent
                            ::TYPE_STATUS_CHANGED
                    )
                    ->count()
            );

            $assertSame(
                'terminal_transition',
                'cancel timestamp matches event',
                $productCase
                    ->cancelled_at
                    ?->toDateTimeString(),
                $cancelledEvent
                    ->occurred_at
                    ?->toDateTimeString()
            );
        } catch (Throwable $exception) {
            $rows[] = [
                'runtime',
                'lifecycle event workflow completed',
                'FAIL',
            ];

            $failures[] = [
                'scenario' =>
                    'runtime',

                'assertion' =>
                    'lifecycle event workflow completed',

                'expected' =>
                    'no exception',

                'actual' =>
                    $exception::class
                    . ': '
                    . $exception
                        ->getMessage(),
            ];
        } finally {
            DB::rollBack();
        }

        /*
         |--------------------------------------------------------------------------
         | Verifica rollback
         |--------------------------------------------------------------------------
         */

        $assertSame(
            'rollback',
            'case count restored',
            $casesBefore,
            ProductCase::query()->count()
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

        $assertSame(
            'rollback',
            'case document links restored',
            $caseDocumentLinksBefore,
            DB::table(
                'product_case_documents'
            )->count()
        );

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
            'Product case lifecycle event checks passed.'
        );

        return self::SUCCESS;
    }
}