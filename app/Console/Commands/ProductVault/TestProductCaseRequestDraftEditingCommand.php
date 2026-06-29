<?php

namespace App\Console\Commands\ProductVault;

use App\Models\Product;
use App\Models\ProductCase;
use App\Models\User;
use App\Services\ProductCases\ProductCaseCreator;
use App\Services\ProductCases\ProductCaseDocumentSelector;
use App\Services\ProductCases\ProductCaseRequestDraftEditor;
use App\Services\ProductCases\ProductCaseStatusTransitionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class TestProductCaseRequestDraftEditingCommand extends Command
{
    /**
     * @var string
     */
    protected $signature =
        'product-vault:test-product-case-request-draft-editing';

    /**
     * @var string
     */
    protected $description =
        'Verifica con rollback la modifica manuale controllata della bozza.';

    public function handle(
        ProductCaseCreator $creator,
        ProductCaseDocumentSelector $documentSelector,
        ProductCaseRequestDraftEditor $editor,
        ProductCaseStatusTransitionService $transitionService
    ): int {
        $rows = [];
        $failures = [];

        $createdCaseId = null;

        $casesBefore =
            ProductCase::query()->count();

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
            $passed = $expected === $actual;

            $rows[] = [
                $scenario,
                $assertion,
                $passed ? 'OK' : 'FAIL',
            ];

            if (! $passed) {
                $failures[] = [
                    'scenario' => $scenario,
                    'assertion' => $assertion,
                    'expected' => $expected,
                    'actual' => $actual,
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
                ->whereNotNull('team_id')
                ->whereHas('documents')
                ->whereHas(
                    'warranties',
                    fn ($query) => $query
                        ->whereNotNull('starts_at')
                        ->whereNotNull('ends_at')
                )
                ->orderBy('id')
                ->first();

            if (
                $product === null
                || $product->team === null
                || $product->documents->isEmpty()
            ) {
                throw new RuntimeException(
                    'Nessun prodotto con team, documenti e garanzia completa utilizzabile per il test.'
                );
            }

            $user = User::query()
                ->find(
                    $product->team->user_id
                );

            if ($user === null) {
                throw new RuntimeException(
                    'Nessun utente utilizzabile per il test.'
                );
            }

            User::query()
                ->whereKey($user->id)
                ->update([
                    'current_team_id' =>
                        $product->team_id,
                ]);

            $user->refresh();

            $document =
                $product->documents->first();

            if ($document === null) {
                throw new RuntimeException(
                    'Documento prodotto non disponibile.'
                );
            }

            $productCase = $creator->create(
                product: $product,
                openedBy: $user,
                attributes: [
                    'title' =>
                        'Test modifica manuale bozza',

                    'description' =>
                        'Pratica completa per verificare la modifica manuale.',

                    'occurred_on' =>
                        today()->toDateString(),

                    'usability_status' =>
                        ProductCase::USABILITY_UNUSABLE,

                    'accidental_damage_declared' =>
                        false,
                ],
            );

            $createdCaseId =
                (int) $productCase->id;

            $documentSelector->select(
                productCase: $productCase,
                document: $document,
                selectedBy: $user,
            );

            /*
             |--------------------------------------------------------------------------
             | Protezione mass assignment
             |--------------------------------------------------------------------------
             */

            $productCase->fill([
                'request_draft' =>
                    'Tentativo di bypass.',
            ])->save();

            $productCase->refresh();

            $assertSame(
                'mass_assignment',
                'direct fill does not modify draft',
                null,
                $productCase->request_draft
            );

            /*
             |--------------------------------------------------------------------------
             | Validazione
             |--------------------------------------------------------------------------
             */

            $blankRejected = false;

            try {
                $editor->saveManualDraft(
                    productCase: $productCase,
                    editedBy: $user,
                    draft: " \r\n ",
                );
            } catch (ValidationException $exception) {
                $blankRejected =
                    array_key_exists(
                        'request_draft',
                        $exception->errors()
                    );
            }

            $assertSame(
                'validation',
                'blank draft rejected',
                true,
                $blankRejected
            );

            $productCase->refresh();

            $assertSame(
                'validation',
                'blank draft changes nothing',
                null,
                $productCase->request_draft
            );

            /*
             |--------------------------------------------------------------------------
             | Prima modifica manuale
             |--------------------------------------------------------------------------
             */

            $productCase =
                $editor->saveManualDraft(
                    productCase: $productCase,
                    editedBy: $user,
                    draft:
                        "  Prima riga.\r\n\r\nSeconda riga.  ",
                );

            $expectedFirstDraft =
                "Prima riga.\n\nSeconda riga.";

            $firstHash =
                hash(
                    'sha256',
                    $expectedFirstDraft
                );

            $assertSame(
                'manual_edit',
                'line endings normalized',
                $expectedFirstDraft,
                $productCase->request_draft
            );

            $assertSame(
                'manual_edit',
                'current source is manual',
                ProductCase
                    ::REQUEST_DRAFT_SOURCE_MANUAL,
                data_get(
                    $productCase->metadata,
                    ProductCase
                        ::REQUEST_DRAFT_CURRENT_METADATA_KEY
                        . '.source'
                )
            );

            $assertSame(
                'manual_edit',
                'current hash stored',
                $firstHash,
                data_get(
                    $productCase->metadata,
                    ProductCase
                        ::REQUEST_DRAFT_CURRENT_METADATA_KEY
                        . '.sha256'
                )
            );

            $assertSame(
                'manual_edit',
                'editor provenance stored',
                (int) $user->id,
                (int) data_get(
                    $productCase->metadata,
                    ProductCaseRequestDraftEditor
                        ::METADATA_KEY
                        . '.edited_by_user_id'
                )
            );

            $assertSame(
                'manual_edit',
                'first edit count',
                1,
                data_get(
                    $productCase->metadata,
                    ProductCaseRequestDraftEditor
                        ::METADATA_KEY
                        . '.edit_count'
                )
            );

            $assertSame(
                'manual_edit',
                'previous source was empty',
                'empty',
                data_get(
                    $productCase->metadata,
                    ProductCaseRequestDraftEditor
                        ::METADATA_KEY
                        . '.previous_source'
                )
            );

            $assertSame(
                'manual_edit',
                'manual draft has no generation timestamp',
                null,
                $productCase
                    ->request_draft_generated_at
            );

            /*
             |--------------------------------------------------------------------------
             | Retry idempotente
             |--------------------------------------------------------------------------
             */

            $metadataBeforeRetry =
                $productCase->metadata;

            $updatedAtBeforeRetry =
                $productCase
                    ->updated_at
                    ?->toISOString();

            $productCase =
                $editor->saveManualDraft(
                    productCase: $productCase,
                    editedBy: $user,
                    draft:
                        "\r\nPrima riga.\r\n\r\nSeconda riga.\r\n",
                );

            $assertSame(
                'idempotency',
                'same normalized draft preserved',
                $expectedFirstDraft,
                $productCase->request_draft
            );

            $assertSame(
                'idempotency',
                'metadata preserved',
                $metadataBeforeRetry,
                $productCase->metadata
            );

            $assertSame(
                'idempotency',
                'updated timestamp preserved',
                $updatedAtBeforeRetry,
                $productCase
                    ->updated_at
                    ?->toISOString()
            );

            /*
             |--------------------------------------------------------------------------
             | Seconda modifica manuale
             |--------------------------------------------------------------------------
             */

            $secondDraft =
                "Prima riga aggiornata.\nSeconda riga.";

            $productCase =
                $editor->saveManualDraft(
                    productCase: $productCase,
                    editedBy: $user,
                    draft: $secondDraft,
                );

            $assertSame(
                'manual_edit',
                'second draft stored',
                $secondDraft,
                $productCase->request_draft
            );

            $assertSame(
                'manual_edit',
                'second edit count',
                2,
                data_get(
                    $productCase->metadata,
                    ProductCaseRequestDraftEditor
                        ::METADATA_KEY
                        . '.edit_count'
                )
            );

            $assertSame(
                'manual_edit',
                'previous source was manual',
                ProductCase
                    ::REQUEST_DRAFT_SOURCE_MANUAL,
                data_get(
                    $productCase->metadata,
                    ProductCaseRequestDraftEditor
                        ::METADATA_KEY
                        . '.previous_source'
                )
            );

            $assertSame(
                'manual_edit',
                'previous hash stored',
                $firstHash,
                data_get(
                    $productCase->metadata,
                    ProductCaseRequestDraftEditor
                        ::METADATA_KEY
                        . '.previous_sha256'
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Editing in ready_to_contact
             |--------------------------------------------------------------------------
             */

            $productCase =
                $transitionService->transition(
                    productCase: $productCase,
                    performedBy: $user,
                    targetStatus:
                        ProductCase::STATUS_READY_TO_CONTACT,
                );

            $assertSame(
                'workflow',
                'case moved to ready',
                ProductCase::STATUS_READY_TO_CONTACT,
                $productCase->status
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
                            'Product Case Draft Edit '
                            . Str::uuid(),

                        'personal_team' =>
                            false,

                        'created_at' =>
                            now(),

                        'updated_at' =>
                            now(),
                    ]);

            User::query()
                ->whereKey($user->id)
                ->update([
                    'current_team_id' =>
                        $otherTeamId,
                ]);

            $user->refresh();

            $crossTeamMessage = null;

            try {
                $editor->saveManualDraft(
                    productCase: $productCase,
                    editedBy: $user,
                    draft:
                        'Tentativo da altro workspace.',
                );
            } catch (RuntimeException $exception) {
                $crossTeamMessage =
                    $exception->getMessage();
            }

            $assertSame(
                'team_isolation',
                'cross-team edit rejected',
                'L’utente non può modificare la bozza di una pratica appartenente a un altro team.',
                $crossTeamMessage
            );

            User::query()
                ->whereKey($user->id)
                ->update([
                    'current_team_id' =>
                        $product->team_id,
                ]);

            $user->refresh();

            $readyDraft =
                'Bozza modificata mentre la pratica è pronta al contatto.';

            $productCase =
                $editor->saveManualDraft(
                    productCase: $productCase,
                    editedBy: $user,
                    draft: $readyDraft,
                );

            $assertSame(
                'workflow',
                'editing in ready state allowed',
                $readyDraft,
                $productCase->request_draft
            );

            /*
             |--------------------------------------------------------------------------
             | Blocco dopo contacted
             |--------------------------------------------------------------------------
             */

            $productCase =
                $transitionService->transition(
                    productCase: $productCase,
                    performedBy: $user,
                    targetStatus:
                        ProductCase::STATUS_CONTACTED,
                );

            $draftBeforeContactEdit =
                $productCase->request_draft;

            $contactedEditMessage = null;

            try {
                $editor->saveManualDraft(
                    productCase: $productCase,
                    editedBy: $user,
                    draft:
                        'Tentativo successivo al contatto.',
                );
            } catch (RuntimeException $exception) {
                $contactedEditMessage =
                    $exception->getMessage();
            }

            $assertSame(
                'terminal_editing',
                'edit after contact rejected',
                'La bozza può essere modificata soltanto prima che il contatto venga registrato.',
                $contactedEditMessage
            );

            $productCase->refresh();

            $assertSame(
                'terminal_editing',
                'draft preserved after rejected edit',
                $draftBeforeContactEdit,
                $productCase->request_draft
            );
        } catch (Throwable $exception) {
            $rows[] = [
                'runtime',
                'manual draft editing completed',
                'FAIL',
            ];

            $failures[] = [
                'scenario' => 'runtime',
                'assertion' =>
                    'manual draft editing completed',
                'expected' =>
                    'no exception',
                'actual' =>
                    $exception::class
                    . ': '
                    . $exception->getMessage(),
            ];
        } finally {
            DB::rollBack();
        }

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
                        $failure['expected'],
                        true
                    )
                );

                $this->line(
                    'Actual: '
                    . var_export(
                        $failure['actual'],
                        true
                    )
                );
            }

            return self::FAILURE;
        }

        $this->info(
            'Product case request draft editing checks passed.'
        );

        return self::SUCCESS;
    }
}