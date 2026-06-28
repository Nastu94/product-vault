<?php

namespace App\Console\Commands\ProductVault;

use App\Models\Document;
use App\Models\Product;
use App\Models\ProductCase;
use App\Models\User;
use App\Services\ProductCases\ProductCaseCreator;
use App\Services\ProductCases\ProductCaseDocumentSelector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TestProductCaseDocumentsCommand extends Command
{
    /**
     * Nome del comando.
     *
     * @var string
     */
    protected $signature =
        'product-vault:test-product-case-documents';

    /**
     * Descrizione del comando.
     *
     * @var string
     */
    protected $description =
        'Verifica con rollback la selezione dei documenti nelle pratiche prodotto.';

    /**
     * Esegue il test senza lasciare dati persistiti.
     */
    public function handle(
        ProductCaseCreator $creator,
        ProductCaseDocumentSelector $selector
    ): int {
        $rows = [];
        $failures = [];

        $createdCaseId = null;
        $createdDocumentId = null;
        $createdOtherTeamDocumentId = null;

        $casesBefore = ProductCase::query()->count();
        $documentsBefore = Document::query()->count();

        $linksBefore = DB::table(
            'product_case_documents'
        )->count();

        $teamsBefore = DB::table('teams')->count();

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
                ])
                ->whereNotNull('team_id')
                ->whereHas('documents')
                ->orderBy('id')
                ->first();

            if (
                $product === null
                || $product->team === null
                || $product->documents->isEmpty()
            ) {
                throw new RuntimeException(
                    'Nessun prodotto con documenti utilizzabile per il test.'
                );
            }

            $user = User::query()
                ->find($product->team->user_id);

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

            $productCase = $creator->create(
                product: $product,
                openedBy: $user,
                attributes: [
                    'title' =>
                        'Pratica documenti di test',
                    'description' =>
                        'Verifica della selezione controllata dei documenti.',
                ],
            );

            $createdCaseId = (int) $productCase->id;

            $document = $product->documents->first();

            if ($document === null) {
                throw new RuntimeException(
                    'Documento prodotto non disponibile.'
                );
            }

            /*
             |--------------------------------------------------------------------------
             | Prima selezione
             |--------------------------------------------------------------------------
             */

            $created = $selector->select(
                productCase: $productCase,
                document: $document,
                selectedBy: $user,
                notes:
                    '  Prova di acquisto principale.  ',
            );

            $assertSame(
                'selection',
                'new selection created',
                true,
                $created
            );

            $assertSame(
                'selection',
                'one link created',
                $linksBefore + 1,
                DB::table(
                    'product_case_documents'
                )->count()
            );

            $selectedDocument =
                $productCase->documents()
                    ->whereKey($document->id)
                    ->first();

            $assertSame(
                'selection',
                'case relation contains document',
                true,
                $selectedDocument !== null
            );

            $assertSame(
                'selection',
                'document inverse relation contains case',
                true,
                $document->productCases()
                    ->whereKey($productCase->id)
                    ->exists()
            );

            $assertSame(
                'selection',
                'selector provenance stored',
                (int) $user->id,
                (int) (
                    $selectedDocument?->pivot
                        ?->selected_by_user_id
                )
            );

            $assertSame(
                'selection',
                'notes normalized',
                'Prova di acquisto principale.',
                $selectedDocument?->pivot?->notes
            );

            /*
             |--------------------------------------------------------------------------
             | Retry idempotente
             |--------------------------------------------------------------------------
             */

            $retryCreated = $selector->select(
                productCase: $productCase,
                document: $document,
                selectedBy: $user,
                notes:
                    'Questa nota non deve sovrascrivere la prima.',
            );

            $assertSame(
                'idempotency',
                'retry returns false',
                false,
                $retryCreated
            );

            $assertSame(
                'idempotency',
                'link count unchanged',
                $linksBefore + 1,
                DB::table(
                    'product_case_documents'
                )->count()
            );

            $selectedDocument =
                $productCase->documents()
                    ->whereKey($document->id)
                    ->first();

            $assertSame(
                'idempotency',
                'original selector preserved',
                (int) $user->id,
                (int) (
                    $selectedDocument?->pivot
                        ?->selected_by_user_id
                )
            );

            $assertSame(
                'idempotency',
                'original notes preserved',
                'Prova di acquisto principale.',
                $selectedDocument?->pivot?->notes
            );

            /*
             |--------------------------------------------------------------------------
             | Documento dello stesso team ma non collegato al prodotto
             |--------------------------------------------------------------------------
             */

            $unlinkedDocument = Document::query()
                ->create([
                    'team_id' => $product->team_id,
                    'uploaded_by_user_id' => $user->id,
                    'status' => 'uploaded',
                    'text_extraction_status' =>
                        'pending',
                    'original_filename' =>
                        'product-case-unlinked-'
                        . Str::uuid()
                        . '.pdf',
                    'mime_type' =>
                        'application/pdf',
                    'file_size' => 0,
                ]);

            $createdDocumentId =
                (int) $unlinkedDocument->id;

            $unlinkedExceptionMessage = null;

            try {
                $selector->select(
                    productCase: $productCase,
                    document: $unlinkedDocument,
                    selectedBy: $user,
                );
            } catch (RuntimeException $exception) {
                $unlinkedExceptionMessage =
                    $exception->getMessage();
            }

            $assertSame(
                'product_scope',
                'unlinked document rejected',
                'Il documento non è collegato al prodotto della pratica.',
                $unlinkedExceptionMessage
            );

            $assertSame(
                'product_scope',
                'unlinked document creates no pivot',
                false,
                DB::table(
                    'product_case_documents'
                )
                    ->where(
                        'product_case_id',
                        $productCase->id
                    )
                    ->where(
                        'document_id',
                        $unlinkedDocument->id
                    )
                    ->exists()
            );

            /*
             |--------------------------------------------------------------------------
             | Protezione cross-team
             |--------------------------------------------------------------------------
             */

            $otherTeamId = DB::table('teams')
                ->insertGetId([
                    'user_id' => $user->id,
                    'name' =>
                        'Product Case Documents '
                        . Str::uuid(),
                    'personal_team' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            /*
             * L'utente opera ancora nel team corretto della pratica,
             * ma il documento appartiene a un workspace differente.
             *
             * In questo modo testiamo direttamente il guardrail sul documento,
             * senza far fallire prima il controllo sul workspace dell'utente.
             */
            $otherTeamDocument = Document::query()
                ->create([
                    'team_id' => $otherTeamId,
                    'uploaded_by_user_id' => $user->id,
                    'status' => 'uploaded',
                    'text_extraction_status' =>
                        'pending',
                    'original_filename' =>
                        'product-case-other-team-'
                        . Str::uuid()
                        . '.pdf',
                    'mime_type' =>
                        'application/pdf',
                    'file_size' => 0,
                ]);

            $createdOtherTeamDocumentId =
                (int) $otherTeamDocument->id;

            $foreignDocumentExceptionMessage = null;

            try {
                $selector->select(
                    productCase: $productCase,
                    document: $otherTeamDocument,
                    selectedBy: $user,
                );
            } catch (RuntimeException $exception) {
                $foreignDocumentExceptionMessage =
                    $exception->getMessage();
            }

            $assertSame(
                'team_isolation',
                'foreign team document rejected',
                'Il documento appartiene a un team diverso dalla pratica.',
                $foreignDocumentExceptionMessage
            );

            $assertSame(
                'team_isolation',
                'foreign team document creates no pivot',
                false,
                DB::table(
                    'product_case_documents'
                )
                    ->where(
                        'product_case_id',
                        $productCase->id
                    )
                    ->where(
                        'document_id',
                        $otherTeamDocument->id
                    )
                    ->exists()
            );

            User::query()
                ->whereKey($user->id)
                ->update([
                    'current_team_id' =>
                        $otherTeamId,
                ]);

            $user->refresh();

            $crossTeamSelectMessage = null;

            try {
                $selector->select(
                    productCase: $productCase,
                    document: $document,
                    selectedBy: $user,
                );
            } catch (RuntimeException $exception) {
                $crossTeamSelectMessage =
                    $exception->getMessage();
            }

            $assertSame(
                'team_isolation',
                'cross-team selection rejected',
                'L’utente non può gestire i documenti di una pratica appartenente a un altro team.',
                $crossTeamSelectMessage
            );

            $crossTeamDeselectMessage = null;

            try {
                $selector->deselect(
                    productCase: $productCase,
                    document: $document,
                    deselectedBy: $user,
                );
            } catch (RuntimeException $exception) {
                $crossTeamDeselectMessage =
                    $exception->getMessage();
            }

            $assertSame(
                'team_isolation',
                'cross-team deselection rejected',
                'L’utente non può gestire i documenti di una pratica appartenente a un altro team.',
                $crossTeamDeselectMessage
            );

            $assertSame(
                'team_isolation',
                'existing selection preserved',
                true,
                DB::table(
                    'product_case_documents'
                )
                    ->where(
                        'product_case_id',
                        $productCase->id
                    )
                    ->where(
                        'document_id',
                        $document->id
                    )
                    ->exists()
            );

            /*
             |--------------------------------------------------------------------------
             | Rimozione valida e idempotente
             |--------------------------------------------------------------------------
             */

            User::query()
                ->whereKey($user->id)
                ->update([
                    'current_team_id' =>
                        $product->team_id,
                ]);

            $user->refresh();

            $removed = $selector->deselect(
                productCase: $productCase,
                document: $document,
                deselectedBy: $user,
            );

            $assertSame(
                'deselection',
                'existing selection removed',
                true,
                $removed
            );

            $assertSame(
                'deselection',
                'link count restored inside transaction',
                $linksBefore,
                DB::table(
                    'product_case_documents'
                )->count()
            );

            $assertSame(
                'deselection',
                'case relation no longer contains document',
                false,
                $productCase->documents()
                    ->whereKey($document->id)
                    ->exists()
            );

            $assertSame(
                'deselection',
                'inverse relation no longer contains case',
                false,
                $document->productCases()
                    ->whereKey($productCase->id)
                    ->exists()
            );

            $retryRemoved = $selector->deselect(
                productCase: $productCase,
                document: $document,
                deselectedBy: $user,
            );

            $assertSame(
                'idempotency',
                'deselection retry returns false',
                false,
                $retryRemoved
            );

            $assertSame(
                'idempotency',
                'deselection retry changes nothing',
                $linksBefore,
                DB::table(
                    'product_case_documents'
                )->count()
            );
        } catch (Throwable $exception) {
            $rows[] = [
                'runtime',
                'document selection test completed',
                'FAIL',
            ];

            $failures[] = [
                'scenario' => 'runtime',
                'assertion' =>
                    'document selection test completed',
                'expected' => 'no exception',
                'actual' =>
                    $exception::class
                    . ': '
                    . $exception->getMessage(),
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
                'created case removed',
                false,
                ProductCase::query()
                    ->whereKey($createdCaseId)
                    ->exists()
            );
        }

        $assertSame(
            'rollback',
            'document count restored',
            $documentsBefore,
            Document::query()->count()
        );

        if ($createdDocumentId !== null) {
            $assertSame(
                'rollback',
                'temporary document removed',
                false,
                Document::query()
                    ->whereKey($createdDocumentId)
                    ->exists()
            );
        }

        if ($createdOtherTeamDocumentId !== null) {
            $assertSame(
                'rollback',
                'foreign team document removed',
                false,
                Document::query()
                    ->whereKey(
                        $createdOtherTeamDocumentId
                    )
                    ->exists()
            );
        }

        $assertSame(
            'rollback',
            'case document links restored',
            $linksBefore,
            DB::table(
                'product_case_documents'
            )->count()
        );

        $assertSame(
            'rollback',
            'team count restored',
            $teamsBefore,
            DB::table('teams')->count()
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
            foreach ($failures as $failure) {
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
            'Product case document checks passed.'
        );

        return self::SUCCESS;
    }
}