<?php

namespace App\Console\Commands\ProductVault;

use App\Exceptions\ProductCases\ProductCaseRequestDraftProtectedException;
use App\Models\Product;
use App\Models\ProductCase;
use App\Models\User;
use App\Services\ProductCases\ProductCaseCreator;
use App\Services\ProductCases\ProductCaseDocumentSelector;
use App\Services\ProductCases\ProductCasePhotoManager;
use App\Services\ProductCases\ProductCaseRequestDraftBuilder;
use App\Services\ProductCases\ProductCaseRequestDraftGenerator;
use App\Services\ProductCases\ProductCaseStatusTransitionService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

class TestProductCaseRequestDraftCommand extends Command
{
    /**
     * @var string
     */
    protected $signature =
        'product-vault:test-product-case-request-draft';

    /**
     * @var string
     */
    protected $description =
        'Verifica con rollback la generazione controllata della bozza di richiesta.';

    public function handle(
        ProductCaseCreator $creator,
        ProductCaseDocumentSelector $documentSelector,
        ProductCasePhotoManager $photoManager,
        ProductCaseRequestDraftBuilder $builder,
        ProductCaseRequestDraftGenerator $generator,
        ProductCaseStatusTransitionService $transitionService
    ): int {
        $rows = [];
        $failures = [];

        $createdCaseIds = [];
        $mediaPaths = [];
        $temporaryPaths = [];

        $casesBefore =
            ProductCase::query()->count();

        $mediaBefore =
            Media::query()->count();

        $caseDocumentLinksBefore =
            DB::table(
                'product_case_documents'
            )->count();

        $teamsBefore =
            DB::table('teams')->count();

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

        $assertContains = function (
            string $scenario,
            string $assertion,
            string $needle,
            string $haystack
        ) use ($assertSame): void {
            $assertSame(
                $scenario,
                $assertion,
                true,
                str_contains(
                    $haystack,
                    $needle
                )
            );
        };

        /*
         * PNG RGB 1x1 valido senza dipendere da GD.
         */
        $makePngContent = function (): string {
            $chunk = function (
                string $type,
                string $data
            ): string {
                return pack(
                    'N',
                    strlen($data)
                )
                    . $type
                    . $data
                    . pack(
                        'N',
                        crc32($type . $data)
                    );
            };

            $header = pack(
                'NNCCCCC',
                1,
                1,
                8,
                2,
                0,
                0,
                0
            );

            $pixel =
                "\x00\x25\x75\xA5";

            return "\x89PNG\r\n\x1a\n"
                . $chunk(
                    'IHDR',
                    $header
                )
                . $chunk(
                    'IDAT',
                    gzcompress($pixel)
                )
                . $chunk(
                    'IEND',
                    ''
                );
        };

        $makeUpload = function (
            string $content
        ) use (&$temporaryPaths): UploadedFile {
            $path = tempnam(
                sys_get_temp_dir(),
                'pv-request-draft-'
            );

            if ($path === false) {
                throw new RuntimeException(
                    'Impossibile creare il file temporaneo.'
                );
            }

            if (
                file_put_contents(
                    $path,
                    $content
                ) === false
            ) {
                throw new RuntimeException(
                    'Impossibile scrivere il file temporaneo.'
                );
            }

            $temporaryPaths[] = $path;

            return new UploadedFile(
                path: $path,
                originalName:
                    'danno-prodotto.png',
                mimeType:
                    'image/png',
                error:
                    UPLOAD_ERR_OK,
                test:
                    true,
            );
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
                        'Schermo nero durante l’utilizzo',

                    'description' =>
                        'Il prodotto si accende ma lo schermo rimane completamente nero.',

                    'occurred_on' =>
                        today()->toDateString(),

                    'usability_status' =>
                        ProductCase::USABILITY_UNUSABLE,

                    'accidental_damage_declared' =>
                        false,
                ],
            );

            $createdCaseIds[] =
                (int) $productCase->id;

            $documentSelector->select(
                productCase: $productCase,
                document: $document,
                selectedBy: $user,
                notes:
                    'Prova di acquisto principale.',
            );

            $photo = $photoManager->addPhoto(
                productCase: $productCase,
                uploadedBy: $user,
                photo: $makeUpload(
                    $makePngContent()
                ),
            );

            $mediaPaths[] =
                $photo->getPath();

            /*
             |--------------------------------------------------------------------------
             | Builder deterministico
             |--------------------------------------------------------------------------
             */

            $firstBuild = $builder->build(
                $productCase->fresh()
            );

            $secondBuild = $builder->build(
                $productCase->fresh()
            );

            $assertSame(
                'builder',
                'contract version',
                ProductCaseRequestDraftBuilder::VERSION,
                $firstBuild['version']
            );

            $assertSame(
                'builder',
                'body is deterministic',
                $firstBuild['body'],
                $secondBuild['body']
            );

            $assertSame(
                'builder',
                'body hash is deterministic',
                $firstBuild['body_sha256'],
                $secondBuild['body_sha256']
            );

            $assertSame(
                'builder',
                'source fingerprint is deterministic',
                $firstBuild[
                    'source_fingerprint'
                ],
                $secondBuild[
                    'source_fingerprint'
                ]
            );

            $assertSame(
                'builder',
                'case is ready in snapshot',
                true,
                data_get(
                    $firstBuild,
                    'readiness.is_ready_to_contact'
                )
            );

            $assertContains(
                'builder',
                'product included',
                (string) $product->name,
                $firstBuild['body']
            );

            $documentLabel =
                $document->original_filename
                ?: 'Documento #'
                    . $document->id;

            $assertContains(
                'builder',
                'selected document included',
                $documentLabel,
                $firstBuild['body']
            );

            $assertContains(
                'builder',
                'photo count included',
                'Fotografie allegate: 1',
                $firstBuild['body']
            );

            $assertContains(
                'builder',
                'warranty section included',
                'GARANZIA',
                $firstBuild['body']
            );

            $productCase->refresh();

            $assertSame(
                'builder',
                'builder does not persist draft',
                null,
                $productCase->request_draft
            );

            $assertSame(
                'builder',
                'builder leaves model clean',
                false,
                $productCase->isDirty()
            );

            /*
             |--------------------------------------------------------------------------
             | Prima generazione
             |--------------------------------------------------------------------------
             */

            $productCase = $generator->generate(
                productCase: $productCase,
                generatedBy: $user,
            );

            $assertSame(
                'generation',
                'draft persisted',
                $firstBuild['body'],
                $productCase->request_draft
            );

            $assertSame(
                'generation',
                'generation timestamp present',
                true,
                $productCase
                    ->request_draft_generated_at
                    !== null
            );

            $assertSame(
                'generation',
                'builder version stored',
                ProductCaseRequestDraftBuilder::VERSION,
                data_get(
                    $productCase->metadata,
                    'request_draft_generation.version'
                )
            );

            $assertSame(
                'generation',
                'generated hash stored',
                hash(
                    'sha256',
                    $productCase->request_draft
                ),
                data_get(
                    $productCase->metadata,
                    'request_draft_generation.generated_sha256'
                )
            );

            $assertSame(
                'generation',
                'generator user stored',
                (int) $user->id,
                (int) data_get(
                    $productCase->metadata,
                    'request_draft_generation.generated_by_user_id'
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Retry idempotente
             |--------------------------------------------------------------------------
             */

            $generatedAtBeforeRetry =
                $productCase
                    ->request_draft_generated_at
                    ?->toISOString();

            $metadataBeforeRetry =
                $productCase->metadata;

            $productCase = $generator->generate(
                productCase: $productCase,
                generatedBy: $user,
            );

            $assertSame(
                'idempotency',
                'draft unchanged',
                $firstBuild['body'],
                $productCase->request_draft
            );

            $assertSame(
                'idempotency',
                'timestamp preserved',
                $generatedAtBeforeRetry,
                $productCase
                    ->request_draft_generated_at
                    ?->toISOString()
            );

            $assertSame(
                'idempotency',
                'metadata preserved',
                $metadataBeforeRetry,
                $productCase->metadata
            );

            /*
             |--------------------------------------------------------------------------
             | Rigenerazione sicura dopo modifica delle sorgenti
             |--------------------------------------------------------------------------
             */

            $draftBeforeSourceChange =
                $productCase->request_draft;

            $hashBeforeSourceChange =
                data_get(
                    $productCase->metadata,
                    'request_draft_generation.generated_sha256'
                );

            $productCase->fill([
                'description' =>
                    'Il prodotto si accende, emette un suono e lo schermo resta nero.',
            ])->save();

            $productCase = $generator->generate(
                productCase: $productCase,
                generatedBy: $user,
            );

            $assertSame(
                'regeneration',
                'generated draft updated',
                true,
                $productCase->request_draft
                    !== $draftBeforeSourceChange
            );

            $assertContains(
                'regeneration',
                'updated description included',
                'emette un suono',
                $productCase->request_draft
            );

            $assertSame(
                'regeneration',
                'generated hash updated',
                true,
                data_get(
                    $productCase->metadata,
                    'request_draft_generation.generated_sha256'
                ) !== $hashBeforeSourceChange
            );

            /*
             |--------------------------------------------------------------------------
             | Protezione della modifica manuale
             |--------------------------------------------------------------------------
             */

            $generationMetadataBeforeManualEdit =
                $productCase->metadata;

            $generatedAtBeforeManualEdit =
                $productCase
                    ->request_draft_generated_at
                    ?->toISOString();

            $manualDraft =
                'Testo personalizzato manualmente dall’utente.';

            $productCase->fill([
                'request_draft' =>
                    $manualDraft,
            ])->save();

            $manualProtectionMessage = null;

            try {
                $generator->generate(
                    productCase: $productCase,
                    generatedBy: $user,
                );
            } catch (
                ProductCaseRequestDraftProtectedException
                    $exception
            ) {
                $manualProtectionMessage =
                    $exception->getMessage();
            }

            $assertSame(
                'manual_protection',
                'manual edit rejected',
                'La bozza è stata modificata manualmente e non può essere sovrascritta automaticamente.',
                $manualProtectionMessage
            );

            $productCase->refresh();

            $assertSame(
                'manual_protection',
                'manual draft preserved',
                $manualDraft,
                $productCase->request_draft
            );

            $assertSame(
                'manual_protection',
                'generation timestamp preserved',
                $generatedAtBeforeManualEdit,
                $productCase
                    ->request_draft_generated_at
                    ?->toISOString()
            );

            $assertSame(
                'manual_protection',
                'generation metadata preserved',
                $generationMetadataBeforeManualEdit,
                $productCase->metadata
            );

            /*
             |--------------------------------------------------------------------------
             | Bozza manuale precedente alla prima generazione
             |--------------------------------------------------------------------------
             */

            $manualCase = $creator->create(
                product: $product,
                openedBy: $user,
                attributes: [
                    'title' =>
                        'Bozza manuale preesistente',

                    'description' =>
                        'Pratica con testo inserito direttamente dall’utente.',
                ],
            );

            $createdCaseIds[] =
                (int) $manualCase->id;

            $manualCase->fill([
                'request_draft' =>
                    'Bozza già inserita manualmente.',
            ])->save();

            $preexistingManualRejected = false;

            try {
                $generator->generate(
                    productCase: $manualCase,
                    generatedBy: $user,
                );
            } catch (
                ProductCaseRequestDraftProtectedException
            ) {
                $preexistingManualRejected = true;
            }

            $assertSame(
                'manual_protection',
                'preexisting manual draft rejected',
                true,
                $preexistingManualRejected
            );

            $manualCase->refresh();

            $assertSame(
                'manual_protection',
                'preexisting manual draft preserved',
                'Bozza già inserita manualmente.',
                $manualCase->request_draft
            );

            $assertSame(
                'manual_protection',
                'manual case has no generated timestamp',
                null,
                $manualCase
                    ->request_draft_generated_at
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
                            'Product Case Draft '
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
                $generator->generate(
                    productCase: $productCase,
                    generatedBy: $user,
                );
            } catch (RuntimeException $exception) {
                $crossTeamMessage =
                    $exception->getMessage();
            }

            $assertSame(
                'team_isolation',
                'cross-team generation rejected',
                'L’utente non può generare la bozza di una pratica appartenente a un altro team.',
                $crossTeamMessage
            );

            User::query()
                ->whereKey($user->id)
                ->update([
                    'current_team_id' =>
                        $product->team_id,
                ]);

            $user->refresh();

            /*
             |--------------------------------------------------------------------------
             | Stato terminale
             |--------------------------------------------------------------------------
             */

            $cancelledCase = $creator->create(
                product: $product,
                openedBy: $user,
                attributes: [
                    'title' =>
                        'Pratica annullata',

                    'description' =>
                        'Pratica usata per verificare il blocco della bozza.',
                ],
            );

            $createdCaseIds[] =
                (int) $cancelledCase->id;

            $cancelledCase =
                $transitionService->transition(
                    productCase:
                        $cancelledCase,

                    performedBy:
                        $user,

                    targetStatus:
                        ProductCase::STATUS_CANCELLED,
                );

            $terminalMessage = null;

            try {
                $generator->generate(
                    productCase: $cancelledCase,
                    generatedBy: $user,
                );
            } catch (RuntimeException $exception) {
                $terminalMessage =
                    $exception->getMessage();
            }

            $assertSame(
                'terminal_state',
                'generation after cancellation rejected',
                'La bozza può essere generata soltanto prima che il contatto venga registrato.',
                $terminalMessage
            );

            $cancelledCase->refresh();

            $assertSame(
                'terminal_state',
                'cancelled case has no draft',
                null,
                $cancelledCase->request_draft
            );
        } catch (Throwable $exception) {
            $rows[] = [
                'runtime',
                'request draft workflow completed',
                'FAIL',
            ];

            $failures[] = [
                'scenario' => 'runtime',
                'assertion' =>
                    'request draft workflow completed',
                'expected' =>
                    'no exception',
                'actual' =>
                    $exception::class
                    . ': '
                    . $exception->getMessage(),
            ];
        } finally {
            /*
             * Il rollback SQL non elimina i file Media Library.
             */
            try {
                if ($createdCaseIds !== []) {
                    $morphType =
                        (new ProductCase())
                            ->getMorphClass();

                    $createdMedia = Media::query()
                        ->where(
                            'model_type',
                            $morphType
                        )
                        ->whereIn(
                            'model_id',
                            $createdCaseIds
                        )
                        ->get();

                    foreach (
                        $createdMedia as $media
                    ) {
                        $media->delete();
                    }
                }
            } catch (Throwable $cleanupException) {
                $rows[] = [
                    'cleanup',
                    'temporary media cleanup',
                    'FAIL',
                ];

                $failures[] = [
                    'scenario' => 'cleanup',
                    'assertion' =>
                        'temporary media cleanup',
                    'expected' =>
                        'all media removed',
                    'actual' =>
                        $cleanupException::class
                        . ': '
                        . $cleanupException
                            ->getMessage(),
                ];
            } finally {
                DB::rollBack();

                foreach (
                    $temporaryPaths as $path
                ) {
                    if (is_file($path)) {
                        @unlink($path);
                    }
                }
            }
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

        foreach (
            $createdCaseIds as $caseId
        ) {
            $assertSame(
                'rollback',
                'temporary case removed '
                    . $caseId,
                false,
                ProductCase::query()
                    ->whereKey($caseId)
                    ->exists()
            );
        }

        $assertSame(
            'rollback',
            'media count restored',
            $mediaBefore,
            Media::query()->count()
        );

        $assertSame(
            'rollback',
            'case document links restored',
            $caseDocumentLinksBefore,
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

        $remainingMediaPaths =
            array_values(
                array_filter(
                    $mediaPaths,
                    fn (string $path): bool =>
                        is_file($path)
                )
            );

        $assertSame(
            'cleanup',
            'physical media files removed',
            [],
            $remainingMediaPaths
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
            'Product case request draft checks passed.'
        );

        return self::SUCCESS;
    }
}