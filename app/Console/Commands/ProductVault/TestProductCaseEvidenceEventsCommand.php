<?php

namespace App\Console\Commands\ProductVault;

use App\Models\Product;
use App\Models\ProductCase;
use App\Models\ProductCaseEvent;
use App\Models\User;
use App\Services\ProductCases\ProductCaseCreator;
use App\Services\ProductCases\ProductCaseDocumentSelector;
use App\Services\ProductCases\ProductCasePhotoManager;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

final class TestProductCaseEvidenceEventsCommand
    extends Command
{
    /**
     * @var string
     */
    protected $signature =
        'product-vault:test-product-case-evidence-events';

    /**
     * @var string
     */
    protected $description =
        'Verifica con rollback gli eventi lifecycle di documenti e fotografie della pratica.';

    public function handle(
        ProductCaseCreator $creator,
        ProductCaseDocumentSelector $documentSelector,
        ProductCasePhotoManager $photoManager
    ): int {
        $rows = [];
        $failures = [];

        $createdCaseId = null;
        $temporaryPaths = [];
        $mediaPaths = [];

        $casesBefore =
            ProductCase::query()->count();

        $eventsBefore =
            ProductCaseEvent::query()->count();

        $mediaBefore =
            Media::query()->count();

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
                "\x00\x31\x77\xA2";

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
                'pv-case-event-'
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

            $temporaryPaths[] =
                $path;

            return new UploadedFile(
                path:
                    $path,

                originalName:
                    'danno-evento.png',

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
                ])
                ->whereNotNull(
                    'team_id'
                )
                ->whereHas(
                    'documents'
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
                    'Nessun prodotto con team e documenti utilizzabile per il test.'
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
                            'Eventi evidenze pratica',

                        'description' =>
                            'Pratica usata per verificare documenti e fotografie nella timeline.',
                    ],
                );

            $createdCaseId =
                (int) $productCase->id;

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

            /*
             |--------------------------------------------------------------------------
             | Documento selezionato
             |--------------------------------------------------------------------------
             */

            $selected =
                $documentSelector->select(
                    productCase:
                        $productCase,

                    document:
                        $document,

                    selectedBy:
                        $user,

                    notes:
                        '  Prova di acquisto principale.  ',
                );

            $assertSame(
                'document_selection',
                'document selected',
                true,
                $selected
            );

            $assertSame(
                'document_selection',
                'one selection event',
                1,
                $eventCount(
                    ProductCaseEvent
                        ::TYPE_DOCUMENT_SELECTED
                )
            );

            $selectionEvent =
                ProductCaseEvent::query()
                    ->where(
                        'product_case_id',
                        $productCase->id
                    )
                    ->where(
                        'event_type',
                        ProductCaseEvent
                            ::TYPE_DOCUMENT_SELECTED
                    )
                    ->first();

            if (
                ! $selectionEvent
                    instanceof ProductCaseEvent
            ) {
                throw new RuntimeException(
                    'Evento di selezione documento non disponibile.'
                );
            }

            $assertSame(
                'document_selection',
                'selection actor stored',
                (int) $user->id,
                (int) $selectionEvent
                    ->actor_user_id
            );

            $assertSame(
                'document_selection',
                'selection document id stored',
                (int) $document->id,
                (int) data_get(
                    $selectionEvent->metadata,
                    'document_id'
                )
            );

            $assertSame(
                'document_selection',
                'selection filename stored',
                $document
                    ->original_filename,
                data_get(
                    $selectionEvent->metadata,
                    'original_filename'
                )
            );

            $assertSame(
                'document_selection',
                'selection notes stored',
                'Prova di acquisto principale.',
                data_get(
                    $selectionEvent->metadata,
                    'notes'
                )
            );

            /*
             * Retry: nessun nuovo pivot e nessun nuovo evento.
             */
            $selectionRetry =
                $documentSelector->select(
                    productCase:
                        $productCase,

                    document:
                        $document,

                    selectedBy:
                        $user,

                    notes:
                        'Nota da ignorare.',
                );

            $assertSame(
                'document_idempotency',
                'selection retry returns false',
                false,
                $selectionRetry
            );

            $assertSame(
                'document_idempotency',
                'selection retry creates no event',
                1,
                $eventCount(
                    ProductCaseEvent
                        ::TYPE_DOCUMENT_SELECTED
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Documento rimosso
             |--------------------------------------------------------------------------
             */

            $deselected =
                $documentSelector->deselect(
                    productCase:
                        $productCase,

                    document:
                        $document,

                    deselectedBy:
                        $user,
                );

            $assertSame(
                'document_deselection',
                'document deselected',
                true,
                $deselected
            );

            $assertSame(
                'document_deselection',
                'one deselection event',
                1,
                $eventCount(
                    ProductCaseEvent
                        ::TYPE_DOCUMENT_DESELECTED
                )
            );

            $deselectionEvent =
                ProductCaseEvent::query()
                    ->where(
                        'product_case_id',
                        $productCase->id
                    )
                    ->where(
                        'event_type',
                        ProductCaseEvent
                            ::TYPE_DOCUMENT_DESELECTED
                    )
                    ->first();

            if (
                ! $deselectionEvent
                    instanceof ProductCaseEvent
            ) {
                throw new RuntimeException(
                    'Evento di rimozione documento non disponibile.'
                );
            }

            $assertSame(
                'document_deselection',
                'original selector preserved',
                (int) $user->id,
                (int) data_get(
                    $deselectionEvent->metadata,
                    'original_selected_by_user_id'
                )
            );

            $assertSame(
                'document_deselection',
                'removed document notes preserved',
                'Prova di acquisto principale.',
                data_get(
                    $deselectionEvent->metadata,
                    'notes'
                )
            );

            $deselectionRetry =
                $documentSelector->deselect(
                    productCase:
                        $productCase,

                    document:
                        $document,

                    deselectedBy:
                        $user,
                );

            $assertSame(
                'document_idempotency',
                'deselection retry returns false',
                false,
                $deselectionRetry
            );

            $assertSame(
                'document_idempotency',
                'deselection retry creates no event',
                1,
                $eventCount(
                    ProductCaseEvent
                        ::TYPE_DOCUMENT_DESELECTED
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Fotografia aggiunta
             |--------------------------------------------------------------------------
             */

            $pngContent =
                $makePngContent();

            $media =
                $photoManager->addPhoto(
                    productCase:
                        $productCase,

                    uploadedBy:
                        $user,

                    photo:
                        $makeUpload(
                            $pngContent
                        ),
                );

            $mediaPaths[] =
                $media->getPath();

            $mediaHash =
                $media->getCustomProperty(
                    'sha256'
                );

            $assertSame(
                'photo_addition',
                'one photo event',
                1,
                $eventCount(
                    ProductCaseEvent
                        ::TYPE_PHOTO_ADDED
                )
            );

            $photoAddedEvent =
                ProductCaseEvent::query()
                    ->where(
                        'product_case_id',
                        $productCase->id
                    )
                    ->where(
                        'event_type',
                        ProductCaseEvent
                            ::TYPE_PHOTO_ADDED
                    )
                    ->first();

            if (
                ! $photoAddedEvent
                    instanceof ProductCaseEvent
            ) {
                throw new RuntimeException(
                    'Evento di aggiunta fotografia non disponibile.'
                );
            }

            $assertSame(
                'photo_addition',
                'photo media id stored',
                (int) $media->id,
                (int) data_get(
                    $photoAddedEvent->metadata,
                    'media_id'
                )
            );

            $assertSame(
                'photo_addition',
                'photo hash stored',
                $mediaHash,
                data_get(
                    $photoAddedEvent->metadata,
                    'sha256'
                )
            );

            $assertSame(
                'photo_addition',
                'photo filename stored',
                'danno-evento.png',
                data_get(
                    $photoAddedEvent->metadata,
                    'original_filename'
                )
            );

            /*
             * Retry dello stesso contenuto.
             */
            $duplicateMedia =
                $photoManager->addPhoto(
                    productCase:
                        $productCase,

                    uploadedBy:
                        $user,

                    photo:
                        $makeUpload(
                            $pngContent
                        ),
                );

            $assertSame(
                'photo_idempotency',
                'duplicate returns same media',
                (int) $media->id,
                (int) $duplicateMedia->id
            );

            $assertSame(
                'photo_idempotency',
                'duplicate creates no event',
                1,
                $eventCount(
                    ProductCaseEvent
                        ::TYPE_PHOTO_ADDED
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Fotografia rimossa
             |--------------------------------------------------------------------------
             */

            $mediaPath =
                $media->getPath();

            $removed =
                $photoManager->removePhoto(
                    productCase:
                        $productCase,

                    removedBy:
                        $user,

                    media:
                        $media,
                );

            $assertSame(
                'photo_removal',
                'photo removed',
                true,
                $removed
            );

            $assertSame(
                'photo_removal',
                'physical file removed',
                false,
                is_file($mediaPath)
            );

            $assertSame(
                'photo_removal',
                'one removal event',
                1,
                $eventCount(
                    ProductCaseEvent
                        ::TYPE_PHOTO_REMOVED
                )
            );

            $photoRemovedEvent =
                ProductCaseEvent::query()
                    ->where(
                        'product_case_id',
                        $productCase->id
                    )
                    ->where(
                        'event_type',
                        ProductCaseEvent
                            ::TYPE_PHOTO_REMOVED
                    )
                    ->first();

            if (
                ! $photoRemovedEvent
                    instanceof ProductCaseEvent
            ) {
                throw new RuntimeException(
                    'Evento di rimozione fotografia non disponibile.'
                );
            }

            $assertSame(
                'photo_removal',
                'removed media id preserved',
                (int) $media->id,
                (int) data_get(
                    $photoRemovedEvent->metadata,
                    'media_id'
                )
            );

            $assertSame(
                'photo_removal',
                'removed photo hash preserved',
                $mediaHash,
                data_get(
                    $photoRemovedEvent->metadata,
                    'sha256'
                )
            );

            $removeRetry =
                $photoManager->removePhoto(
                    productCase:
                        $productCase,

                    removedBy:
                        $user,

                    media:
                        $media,
                );

            $assertSame(
                'photo_idempotency',
                'removal retry returns false',
                false,
                $removeRetry
            );

            $assertSame(
                'photo_idempotency',
                'removal retry creates no event',
                1,
                $eventCount(
                    ProductCaseEvent
                        ::TYPE_PHOTO_REMOVED
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Ordine timeline
             |--------------------------------------------------------------------------
             */

            $eventTypes =
                ProductCaseEvent::query()
                    ->where(
                        'product_case_id',
                        $productCase->id
                    )
                    ->orderBy('id')
                    ->pluck(
                        'event_type'
                    )
                    ->all();

            $assertSame(
                'timeline',
                'evidence event order',
                [
                    ProductCaseEvent
                        ::TYPE_CASE_OPENED,

                    ProductCaseEvent
                        ::TYPE_DOCUMENT_SELECTED,

                    ProductCaseEvent
                        ::TYPE_DOCUMENT_DESELECTED,

                    ProductCaseEvent
                        ::TYPE_PHOTO_ADDED,

                    ProductCaseEvent
                        ::TYPE_PHOTO_REMOVED,
                ],
                $eventTypes
            );
        } catch (Throwable $exception) {
            $rows[] = [
                'runtime',
                'evidence event workflow completed',
                'FAIL',
            ];

            $failures[] = [
                'scenario' =>
                    'runtime',

                'assertion' =>
                    'evidence event workflow completed',

                'expected' =>
                    'no exception',

                'actual' =>
                    $exception::class
                    . ': '
                    . $exception
                        ->getMessage(),
            ];
        } finally {
            /*
             * Il rollback SQL non elimina gli eventuali file ancora presenti.
             */
            try {
                if ($createdCaseId !== null) {
                    $morphType =
                        (new ProductCase())
                            ->getMorphClass();

                    $createdMedia =
                        Media::query()
                            ->where(
                                'model_type',
                                $morphType
                            )
                            ->where(
                                'model_id',
                                $createdCaseId
                            )
                            ->get();

                    foreach (
                        $createdMedia as $storedMedia
                    ) {
                        $storedMedia->delete();
                    }
                }
            } catch (Throwable $cleanupException) {
                $rows[] = [
                    'cleanup',
                    'temporary media cleanup',
                    'FAIL',
                ];

                $failures[] = [
                    'scenario' =>
                        'cleanup',

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
            'media count restored',
            $mediaBefore,
            Media::query()->count()
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
            'Product case evidence event checks passed.'
        );

        return self::SUCCESS;
    }
}