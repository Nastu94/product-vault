<?php

namespace App\Console\Commands\ProductVault;

use App\Exceptions\ProductCases\ProductCaseRequestDraftProtectedException;
use App\Models\Product;
use App\Models\ProductCase;
use App\Models\ProductCaseEvent;
use App\Models\User;
use App\Services\ProductCases\ProductCaseCreator;
use App\Services\ProductCases\ProductCaseDocumentSelector;
use App\Services\ProductCases\ProductCaseRequestDraftEditor;
use App\Services\ProductCases\ProductCaseRequestDraftGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class TestProductCaseRequestDraftEventsCommand
    extends Command
{
    /**
     * @var string
     */
    protected $signature =
        'product-vault:test-product-case-request-draft-events';

    /**
     * @var string
     */
    protected $description =
        'Verifica con rollback gli eventi lifecycle della bozza di richiesta.';

    public function handle(
        ProductCaseCreator $creator,
        ProductCaseDocumentSelector $documentSelector,
        ProductCaseRequestDraftGenerator $generator,
        ProductCaseRequestDraftEditor $editor
    ): int {
        $rows = [];
        $failures = [];

        $createdCaseId = null;

        $casesBefore =
            ProductCase::query()->count();

        $eventsBefore =
            ProductCaseEvent::query()->count();

        $linksBefore =
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

            $productCase =
                $creator->create(
                    product:
                        $product,

                    openedBy:
                        $user,

                    attributes: [
                        'title' =>
                            'Eventi bozza richiesta',

                        'description' =>
                            'Pratica completa usata per verificare la timeline della bozza.',

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

            $documentSelector->select(
                productCase:
                    $productCase,

                document:
                    $document,

                selectedBy:
                    $user,
            );

            $eventCount = function (
                string $eventType
            ) use ($productCase): int {
                return ProductCaseEvent::query()
                    ->where(
                        'product_case_id',
                        $productCase->id
                    )
                    ->where(
                        'event_type',
                        $eventType
                    )
                    ->count();
            };

            $draftEventCount = function () use (
                $productCase
            ): int {
                return ProductCaseEvent::query()
                    ->where(
                        'product_case_id',
                        $productCase->id
                    )
                    ->whereIn(
                        'event_type',
                        [
                            ProductCaseEvent
                                ::TYPE_REQUEST_DRAFT_GENERATED,

                            ProductCaseEvent
                                ::TYPE_REQUEST_DRAFT_REGENERATED,

                            ProductCaseEvent
                                ::TYPE_REQUEST_DRAFT_EDITED,
                        ]
                    )
                    ->count();
            };

            /*
             |--------------------------------------------------------------------------
             | Prima generazione automatica
             |--------------------------------------------------------------------------
             */

            $productCase =
                $generator->generate(
                    productCase:
                        $productCase,

                    generatedBy:
                        $user,
                );

            $initialHash = hash(
                'sha256',
                $productCase->request_draft
            );

            $assertSame(
                'initial_generation',
                'one generation event',
                1,
                $eventCount(
                    ProductCaseEvent
                        ::TYPE_REQUEST_DRAFT_GENERATED
                )
            );

            $assertSame(
                'initial_generation',
                'no regeneration event',
                0,
                $eventCount(
                    ProductCaseEvent
                        ::TYPE_REQUEST_DRAFT_REGENERATED
                )
            );

            $generationEvent =
                ProductCaseEvent::query()
                    ->where(
                        'product_case_id',
                        $productCase->id
                    )
                    ->where(
                        'event_type',
                        ProductCaseEvent
                            ::TYPE_REQUEST_DRAFT_GENERATED
                    )
                    ->first();

            if (
                ! $generationEvent
                    instanceof ProductCaseEvent
            ) {
                throw new RuntimeException(
                    'Evento di generazione bozza non disponibile.'
                );
            }

            $assertSame(
                'initial_generation',
                'generation actor stored',
                (int) $user->id,
                (int) $generationEvent
                    ->actor_user_id
            );

            $assertSame(
                'initial_generation',
                'previous source is empty',
                'empty',
                data_get(
                    $generationEvent->metadata,
                    'previous_source'
                )
            );

            $assertSame(
                'initial_generation',
                'previous hash is null',
                null,
                data_get(
                    $generationEvent->metadata,
                    'previous_sha256'
                )
            );

            $assertSame(
                'initial_generation',
                'new hash stored',
                $initialHash,
                data_get(
                    $generationEvent->metadata,
                    'new_sha256'
                )
            );

            $assertSame(
                'initial_generation',
                'source fingerprint stored',
                data_get(
                    $productCase->metadata,
                    'request_draft_generation.source_fingerprint'
                ),
                data_get(
                    $generationEvent->metadata,
                    'source_fingerprint'
                )
            );

            $assertSame(
                'initial_generation',
                'generation timestamp matches event',
                $productCase
                    ->request_draft_generated_at
                    ?->toDateTimeString(),
                $generationEvent
                    ->occurred_at
                    ?->toDateTimeString()
            );

            /*
             |--------------------------------------------------------------------------
             | Retry idempotente della generazione
             |--------------------------------------------------------------------------
             */

            $draftEventsBeforeRetry =
                $draftEventCount();

            $generatedAtBeforeRetry =
                $productCase
                    ->request_draft_generated_at
                    ?->toISOString();

            $productCase =
                $generator->generate(
                    productCase:
                        $productCase,

                    generatedBy:
                        $user,
                );

            $assertSame(
                'generation_idempotency',
                'retry creates no event',
                $draftEventsBeforeRetry,
                $draftEventCount()
            );

            $assertSame(
                'generation_idempotency',
                'generation timestamp preserved',
                $generatedAtBeforeRetry,
                $productCase
                    ->request_draft_generated_at
                    ?->toISOString()
            );

            /*
             |--------------------------------------------------------------------------
             | Rigenerazione dopo modifica delle sorgenti
             |--------------------------------------------------------------------------
             */

            $productCase->fill([
                'description' =>
                    'La descrizione è cambiata e richiede una nuova bozza.',
            ])->save();

            $productCase =
                $generator->generate(
                    productCase:
                        $productCase,

                    generatedBy:
                        $user,
                );

            $regeneratedHash = hash(
                'sha256',
                $productCase->request_draft
            );

            $assertSame(
                'regeneration',
                'one regeneration event',
                1,
                $eventCount(
                    ProductCaseEvent
                        ::TYPE_REQUEST_DRAFT_REGENERATED
                )
            );

            $regenerationEvent =
                ProductCaseEvent::query()
                    ->where(
                        'product_case_id',
                        $productCase->id
                    )
                    ->where(
                        'event_type',
                        ProductCaseEvent
                            ::TYPE_REQUEST_DRAFT_REGENERATED
                    )
                    ->first();

            if (
                ! $regenerationEvent
                    instanceof ProductCaseEvent
            ) {
                throw new RuntimeException(
                    'Evento di rigenerazione bozza non disponibile.'
                );
            }

            $assertSame(
                'regeneration',
                'previous source was generated',
                ProductCase
                    ::REQUEST_DRAFT_SOURCE_GENERATED,
                data_get(
                    $regenerationEvent->metadata,
                    'previous_source'
                )
            );

            $assertSame(
                'regeneration',
                'previous generated hash stored',
                $initialHash,
                data_get(
                    $regenerationEvent->metadata,
                    'previous_sha256'
                )
            );

            $assertSame(
                'regeneration',
                'regenerated hash stored',
                $regeneratedHash,
                data_get(
                    $regenerationEvent->metadata,
                    'new_sha256'
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Prima modifica manuale
             |--------------------------------------------------------------------------
             */

            $manualDraft =
                "Bozza personalizzata.\nCon informazioni aggiuntive.";

            $productCase =
                $editor->saveManualDraft(
                    productCase:
                        $productCase,

                    editedBy:
                        $user,

                    draft:
                        $manualDraft,
                );

            $manualHash = hash(
                'sha256',
                $manualDraft
            );

            $assertSame(
                'manual_edit',
                'one manual event',
                1,
                $eventCount(
                    ProductCaseEvent
                        ::TYPE_REQUEST_DRAFT_EDITED
                )
            );

            $manualEvent =
                ProductCaseEvent::query()
                    ->where(
                        'product_case_id',
                        $productCase->id
                    )
                    ->where(
                        'event_type',
                        ProductCaseEvent
                            ::TYPE_REQUEST_DRAFT_EDITED
                    )
                    ->orderBy('id')
                    ->first();

            if (
                ! $manualEvent
                    instanceof ProductCaseEvent
            ) {
                throw new RuntimeException(
                    'Evento di modifica manuale non disponibile.'
                );
            }

            $assertSame(
                'manual_edit',
                'manual previous source generated',
                ProductCase
                    ::REQUEST_DRAFT_SOURCE_GENERATED,
                data_get(
                    $manualEvent->metadata,
                    'previous_source'
                )
            );

            $assertSame(
                'manual_edit',
                'manual previous hash stored',
                $regeneratedHash,
                data_get(
                    $manualEvent->metadata,
                    'previous_sha256'
                )
            );

            $assertSame(
                'manual_edit',
                'manual new hash stored',
                $manualHash,
                data_get(
                    $manualEvent->metadata,
                    'new_sha256'
                )
            );

            $assertSame(
                'manual_edit',
                'manual current source stored',
                ProductCase
                    ::REQUEST_DRAFT_SOURCE_MANUAL,
                data_get(
                    $manualEvent->metadata,
                    'current_source'
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Retry idempotente della modifica manuale
             |--------------------------------------------------------------------------
             */

            $manualEventsBeforeRetry =
                $eventCount(
                    ProductCaseEvent
                        ::TYPE_REQUEST_DRAFT_EDITED
                );

            $productCase =
                $editor->saveManualDraft(
                    productCase:
                        $productCase,

                    editedBy:
                        $user,

                    draft:
                        "\r\n"
                        . $manualDraft
                        . "\r\n",
                );

            $assertSame(
                'manual_idempotency',
                'manual retry creates no event',
                $manualEventsBeforeRetry,
                $eventCount(
                    ProductCaseEvent
                        ::TYPE_REQUEST_DRAFT_EDITED
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Seconda modifica manuale
             |--------------------------------------------------------------------------
             */

            $secondManualDraft =
                'Seconda versione manuale della bozza.';

            $productCase =
                $editor->saveManualDraft(
                    productCase:
                        $productCase,

                    editedBy:
                        $user,

                    draft:
                        $secondManualDraft,
                );

            $secondManualHash = hash(
                'sha256',
                $secondManualDraft
            );

            $assertSame(
                'manual_edit',
                'two manual events',
                2,
                $eventCount(
                    ProductCaseEvent
                        ::TYPE_REQUEST_DRAFT_EDITED
                )
            );

            $secondManualEvent =
                ProductCaseEvent::query()
                    ->where(
                        'product_case_id',
                        $productCase->id
                    )
                    ->where(
                        'event_type',
                        ProductCaseEvent
                            ::TYPE_REQUEST_DRAFT_EDITED
                    )
                    ->orderByDesc('id')
                    ->first();

            if (
                ! $secondManualEvent
                    instanceof ProductCaseEvent
            ) {
                throw new RuntimeException(
                    'Secondo evento manuale non disponibile.'
                );
            }

            $assertSame(
                'manual_edit',
                'second previous source manual',
                ProductCase
                    ::REQUEST_DRAFT_SOURCE_MANUAL,
                data_get(
                    $secondManualEvent->metadata,
                    'previous_source'
                )
            );

            $assertSame(
                'manual_edit',
                'second previous hash stored',
                $manualHash,
                data_get(
                    $secondManualEvent->metadata,
                    'previous_sha256'
                )
            );

            $assertSame(
                'manual_edit',
                'second new hash stored',
                $secondManualHash,
                data_get(
                    $secondManualEvent->metadata,
                    'new_sha256'
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Generazione rifiutata dopo modifica manuale
             |--------------------------------------------------------------------------
             */

            $eventsBeforeProtectedGeneration =
                $draftEventCount();

            $draftBeforeProtectedGeneration =
                $productCase->request_draft;

            $protectedGenerationRejected =
                false;

            try {
                $generator->generate(
                    productCase:
                        $productCase,

                    generatedBy:
                        $user,
                );
            } catch (
                ProductCaseRequestDraftProtectedException
            ) {
                $protectedGenerationRejected =
                    true;
            }

            $assertSame(
                'rejected_generation',
                'manual draft protects generation',
                true,
                $protectedGenerationRejected
            );

            $assertSame(
                'rejected_generation',
                'rejected generation creates no event',
                $eventsBeforeProtectedGeneration,
                $draftEventCount()
            );

            $productCase->refresh();

            $assertSame(
                'rejected_generation',
                'manual draft preserved',
                $draftBeforeProtectedGeneration,
                $productCase->request_draft
            );

            /*
             |--------------------------------------------------------------------------
             | Modifica non valida rifiutata
             |--------------------------------------------------------------------------
             */

            $eventsBeforeInvalidEdit =
                $draftEventCount();

            $blankRejected = false;

            try {
                $editor->saveManualDraft(
                    productCase:
                        $productCase,

                    editedBy:
                        $user,

                    draft:
                        " \r\n ",
                );
            } catch (
                ValidationException
            ) {
                $blankRejected = true;
            }

            $assertSame(
                'rejected_edit',
                'blank edit rejected',
                true,
                $blankRejected
            );

            $assertSame(
                'rejected_edit',
                'rejected edit creates no event',
                $eventsBeforeInvalidEdit,
                $draftEventCount()
            );

            /*
             |--------------------------------------------------------------------------
             | Ordine degli eventi della bozza
             |--------------------------------------------------------------------------
             */

            $draftEventTypes =
                ProductCaseEvent::query()
                    ->where(
                        'product_case_id',
                        $productCase->id
                    )
                    ->whereIn(
                        'event_type',
                        [
                            ProductCaseEvent
                                ::TYPE_REQUEST_DRAFT_GENERATED,

                            ProductCaseEvent
                                ::TYPE_REQUEST_DRAFT_REGENERATED,

                            ProductCaseEvent
                                ::TYPE_REQUEST_DRAFT_EDITED,
                        ]
                    )
                    ->orderBy('id')
                    ->pluck(
                        'event_type'
                    )
                    ->all();

            $assertSame(
                'timeline',
                'draft event order',
                [
                    ProductCaseEvent
                        ::TYPE_REQUEST_DRAFT_GENERATED,

                    ProductCaseEvent
                        ::TYPE_REQUEST_DRAFT_REGENERATED,

                    ProductCaseEvent
                        ::TYPE_REQUEST_DRAFT_EDITED,

                    ProductCaseEvent
                        ::TYPE_REQUEST_DRAFT_EDITED,
                ],
                $draftEventTypes
            );
        } catch (Throwable $exception) {
            $rows[] = [
                'runtime',
                'request draft event workflow completed',
                'FAIL',
            ];

            $failures[] = [
                'scenario' =>
                    'runtime',

                'assertion' =>
                    'request draft event workflow completed',

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
         | Rollback
         |--------------------------------------------------------------------------
         */

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
            'document links restored',
            $linksBefore,
            DB::table(
                'product_case_documents'
            )->count()
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
            'Product case request draft event checks passed.'
        );

        return self::SUCCESS;
    }
}