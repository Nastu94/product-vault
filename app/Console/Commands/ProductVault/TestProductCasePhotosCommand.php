<?php

namespace App\Console\Commands\ProductVault;

use App\Models\Product;
use App\Models\ProductCase;
use App\Models\User;
use App\Services\ProductCases\ProductCaseCreator;
use App\Services\ProductCases\ProductCasePhotoManager;
use App\Services\ProductCases\ProductCaseStatusTransitionService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

class TestProductCasePhotosCommand extends Command
{
    /**
     * @var string
     */
    protected $signature =
        'product-vault:test-product-case-photos';

    /**
     * @var string
     */
    protected $description =
        'Verifica con rollback il caricamento privato delle fotografie delle pratiche.';

    public function handle(
        ProductCaseCreator $creator,
        ProductCasePhotoManager $photoManager,
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

        /*
         * Genera un PNG RGB 1x1 valido senza dipendere da GD.
         */
        $makePngContent = function (
            int $variant
        ): string {
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
                "\x00"
                . chr($variant % 256)
                . chr(($variant * 37) % 256)
                . chr(($variant * 73) % 256);

            return "\x89PNG\r\n\x1a\n"
                . $chunk('IHDR', $header)
                . $chunk(
                    'IDAT',
                    gzcompress($pixel)
                )
                . $chunk('IEND', '');
        };

        $makeUpload = function (
            string $filename,
            string $content,
            string $mimeType
        ) use (&$temporaryPaths): UploadedFile {
            $path = tempnam(
                sys_get_temp_dir(),
                'pv-case-photo-'
            );

            if ($path === false) {
                throw new RuntimeException(
                    'Impossibile creare il file temporaneo del test.'
                );
            }

            if (
                file_put_contents(
                    $path,
                    $content
                ) === false
            ) {
                throw new RuntimeException(
                    'Impossibile scrivere il file temporaneo del test.'
                );
            }

            $temporaryPaths[] = $path;

            return new UploadedFile(
                path: $path,
                originalName: $filename,
                mimeType: $mimeType,
                error: UPLOAD_ERR_OK,
                test: true,
            );
        };

        $trackMedia = function (
            Media $media
        ) use (&$mediaPaths): void {
            $path = $media->getPath();

            if (! in_array(
                $path,
                $mediaPaths,
                true
            )) {
                $mediaPaths[] = $path;
            }
        };

        DB::beginTransaction();

        try {
            $product = Product::query()
                ->with('team')
                ->whereNotNull('team_id')
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
                        'Fotografie danno prodotto',
                    'description' =>
                        'Pratica usata per verificare le fotografie.',
                ],
            );

            $createdCaseIds[] =
                (int) $productCase->id;

            /*
             |--------------------------------------------------------------------------
             | Primo upload
             |--------------------------------------------------------------------------
             */

            $firstMedia =
                $photoManager->addPhoto(
                    productCase: $productCase,
                    uploadedBy: $user,
                    photo: $makeUpload(
                        filename: '  danno-monitor.png  ',
                        content:
                            $makePngContent(1),
                        mimeType: 'image/png',
                    ),
                );

            $trackMedia($firstMedia);

            $assertSame(
                'upload',
                'photo stored',
                true,
                $firstMedia->exists
            );

            $assertSame(
                'upload',
                'private disk used',
                'local',
                $firstMedia->disk
            );

            $assertSame(
                'upload',
                'correct collection used',
                ProductCase::MEDIA_COLLECTION_ISSUE_PHOTOS,
                $firstMedia->collection_name
            );

            $assertSame(
                'upload',
                'physical file exists',
                true,
                is_file($firstMedia->getPath())
            );

            $assertSame(
                'upload',
                'uploader provenance stored',
                (int) $user->id,
                (int) $firstMedia
                    ->getCustomProperty(
                        'uploaded_by_user_id'
                    )
            );

            $assertSame(
                'upload',
                'team provenance stored',
                (int) $product->team_id,
                (int) $firstMedia
                    ->getCustomProperty(
                        'team_id'
                    )
            );

            $assertSame(
                'upload',
                'hash stored',
                true,
                is_string(
                    $firstMedia->getCustomProperty(
                        'sha256'
                    )
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Retry dello stesso contenuto
             |--------------------------------------------------------------------------
             */

            $duplicateMedia =
                $photoManager->addPhoto(
                    productCase: $productCase,
                    uploadedBy: $user,
                    photo: $makeUpload(
                        filename:
                            'stessa-fotografia.png',
                        content:
                            $makePngContent(1),
                        mimeType: 'image/png',
                    ),
                );

            $assertSame(
                'idempotency',
                'same media returned',
                (int) $firstMedia->id,
                (int) $duplicateMedia->id
            );

            $assertSame(
                'idempotency',
                'duplicate creates no media',
                1,
                $productCase
                    ->getMedia(
                        ProductCase::MEDIA_COLLECTION_ISSUE_PHOTOS
                    )
                    ->count()
            );

            /*
             |--------------------------------------------------------------------------
             | Validazione formato
             |--------------------------------------------------------------------------
             */

            $invalidTypeRejected = false;

            try {
                $photoManager->addPhoto(
                    productCase: $productCase,
                    uploadedBy: $user,
                    photo: $makeUpload(
                        filename: 'allegato.pdf',
                        content:
                            "%PDF-1.4\nProduct Vault",
                        mimeType:
                            'application/pdf',
                    ),
                );
            } catch (ValidationException $exception) {
                $invalidTypeRejected =
                    array_key_exists(
                        'photo',
                        $exception->errors()
                    );
            }

            $assertSame(
                'validation',
                'non-image rejected',
                true,
                $invalidTypeRejected
            );

            $oversizedContent =
                $makePngContent(40)
                . str_repeat(
                    'A',
                    (
                        ProductCasePhotoManager
                            ::MAX_PHOTO_SIZE_KB
                        * 1024
                    ) + 1
                );

            $oversizedRejected = false;

            try {
                $photoManager->addPhoto(
                    productCase: $productCase,
                    uploadedBy: $user,
                    photo: $makeUpload(
                        filename:
                            'fotografia-troppo-grande.png',
                        content:
                            $oversizedContent,
                        mimeType:
                            'image/png',
                    ),
                );
            } catch (ValidationException $exception) {
                $oversizedRejected =
                    array_key_exists(
                        'photo',
                        $exception->errors()
                    );
            }

            $assertSame(
                'validation',
                'oversized image rejected',
                true,
                $oversizedRejected
            );

            $assertSame(
                'validation',
                'invalid uploads create no media',
                1,
                $productCase
                    ->getMedia(
                        ProductCase::MEDIA_COLLECTION_ISSUE_PHOTOS
                    )
                    ->count()
            );

            /*
             |--------------------------------------------------------------------------
             | Limite massimo
             |--------------------------------------------------------------------------
             */

            for (
                $index = 2;
                $index <= ProductCasePhotoManager::MAX_PHOTOS;
                $index++
            ) {
                $media = $photoManager->addPhoto(
                    productCase: $productCase,
                    uploadedBy: $user,
                    photo: $makeUpload(
                        filename:
                            'fotografia-'
                            . $index
                            . '.png',
                        content:
                            $makePngContent($index),
                        mimeType:
                            'image/png',
                    ),
                );

                $trackMedia($media);
            }

            $assertSame(
                'limit',
                'maximum photo count reached',
                ProductCasePhotoManager::MAX_PHOTOS,
                $productCase
                    ->fresh()
                    ->getMedia(
                        ProductCase::MEDIA_COLLECTION_ISSUE_PHOTOS
                    )
                    ->count()
            );

            $limitRejected = false;

            try {
                $photoManager->addPhoto(
                    productCase: $productCase,
                    uploadedBy: $user,
                    photo: $makeUpload(
                        filename:
                            'fotografia-oltre-limite.png',
                        content:
                            $makePngContent(20),
                        mimeType:
                            'image/png',
                    ),
                );
            } catch (ValidationException $exception) {
                $limitRejected =
                    array_key_exists(
                        'photo',
                        $exception->errors()
                    );
            }

            $assertSame(
                'limit',
                'additional photo rejected',
                true,
                $limitRejected
            );

            /*
             |--------------------------------------------------------------------------
             | Rimozione e retry
             |--------------------------------------------------------------------------
             */

            $firstPath =
                $firstMedia->getPath();

            $removed =
                $photoManager->removePhoto(
                    productCase: $productCase,
                    removedBy: $user,
                    media: $firstMedia,
                );

            $assertSame(
                'removal',
                'photo removed',
                true,
                $removed
            );

            $assertSame(
                'removal',
                'physical file removed',
                false,
                is_file($firstPath)
            );

            $removedAgain =
                $photoManager->removePhoto(
                    productCase: $productCase,
                    removedBy: $user,
                    media: $firstMedia,
                );

            $assertSame(
                'idempotency',
                'removal retry returns false',
                false,
                $removedAgain
            );

            $replacementMedia =
                $photoManager->addPhoto(
                    productCase: $productCase,
                    uploadedBy: $user,
                    photo: $makeUpload(
                        filename:
                            'fotografia-sostitutiva.png',
                        content:
                            $makePngContent(21),
                        mimeType:
                            'image/png',
                    ),
                );

            $trackMedia($replacementMedia);

            $assertSame(
                'limit',
                'removed slot can be reused',
                ProductCasePhotoManager::MAX_PHOTOS,
                $productCase
                    ->fresh()
                    ->getMedia(
                        ProductCase::MEDIA_COLLECTION_ISSUE_PHOTOS
                    )
                    ->count()
            );

            /*
             |--------------------------------------------------------------------------
             | Media appartenente a un'altra pratica
             |--------------------------------------------------------------------------
             */

            $otherCase = $creator->create(
                product: $product,
                openedBy: $user,
                attributes: [
                    'title' =>
                        'Seconda pratica fotografica',
                    'description' =>
                        'Pratica usata per verificare la proprietà dei media.',
                ],
            );

            $createdCaseIds[] =
                (int) $otherCase->id;

            $otherCaseMedia =
                $photoManager->addPhoto(
                    productCase: $otherCase,
                    uploadedBy: $user,
                    photo: $makeUpload(
                        filename:
                            'altra-pratica.png',
                        content:
                            $makePngContent(30),
                        mimeType:
                            'image/png',
                    ),
                );

            $trackMedia($otherCaseMedia);

            $foreignMediaMessage = null;

            try {
                $photoManager->removePhoto(
                    productCase: $productCase,
                    removedBy: $user,
                    media: $otherCaseMedia,
                );
            } catch (RuntimeException $exception) {
                $foreignMediaMessage =
                    $exception->getMessage();
            }

            $assertSame(
                'ownership',
                'foreign case media rejected',
                'La fotografia non appartiene alla pratica.',
                $foreignMediaMessage
            );

            $assertSame(
                'ownership',
                'foreign media preserved',
                true,
                Media::query()
                    ->whereKey(
                        $otherCaseMedia->id
                    )
                    ->exists()
            );

            /*
             |--------------------------------------------------------------------------
             | Isolamento team
             |--------------------------------------------------------------------------
             */

            $otherTeamId = DB::table('teams')
                ->insertGetId([
                    'user_id' => $user->id,
                    'name' =>
                        'Product Case Photos '
                        . Str::uuid(),
                    'personal_team' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            User::query()
                ->whereKey($user->id)
                ->update([
                    'current_team_id' =>
                        $otherTeamId,
                ]);

            $user->refresh();

            $crossTeamUploadMessage = null;

            try {
                $photoManager->addPhoto(
                    productCase: $productCase,
                    uploadedBy: $user,
                    photo: $makeUpload(
                        filename:
                            'cross-team.png',
                        content:
                            $makePngContent(31),
                        mimeType:
                            'image/png',
                    ),
                );
            } catch (RuntimeException $exception) {
                $crossTeamUploadMessage =
                    $exception->getMessage();
            }

            $assertSame(
                'team_isolation',
                'cross-team upload rejected',
                'L’utente non può gestire le fotografie di una pratica appartenente a un altro team.',
                $crossTeamUploadMessage
            );

            $crossTeamRemovalMessage = null;

            try {
                $photoManager->removePhoto(
                    productCase: $productCase,
                    removedBy: $user,
                    media: $replacementMedia,
                );
            } catch (RuntimeException $exception) {
                $crossTeamRemovalMessage =
                    $exception->getMessage();
            }

            $assertSame(
                'team_isolation',
                'cross-team removal rejected',
                'L’utente non può gestire le fotografie di una pratica appartenente a un altro team.',
                $crossTeamRemovalMessage
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
             | Pratica terminale
             |--------------------------------------------------------------------------
             */

            $cancelledCase = $creator->create(
                product: $product,
                openedBy: $user,
                attributes: [
                    'title' =>
                        'Pratica fotografica annullata',
                    'description' =>
                        'Pratica usata per verificare il blocco terminale.',
                ],
            );

            $createdCaseIds[] =
                (int) $cancelledCase->id;

            $cancelledMedia =
                $photoManager->addPhoto(
                    productCase: $cancelledCase,
                    uploadedBy: $user,
                    photo: $makeUpload(
                        filename:
                            'prima-annullamento.png',
                        content:
                            $makePngContent(40),
                        mimeType:
                            'image/png',
                    ),
                );

            $trackMedia($cancelledMedia);

            $cancelledCase =
                $transitionService->transition(
                    productCase: $cancelledCase,
                    performedBy: $user,
                    targetStatus:
                        ProductCase::STATUS_CANCELLED,
                );

            $terminalUploadMessage = null;

            try {
                $photoManager->addPhoto(
                    productCase: $cancelledCase,
                    uploadedBy: $user,
                    photo: $makeUpload(
                        filename:
                            'dopo-annullamento.png',
                        content:
                            $makePngContent(41),
                        mimeType:
                            'image/png',
                    ),
                );
            } catch (RuntimeException $exception) {
                $terminalUploadMessage =
                    $exception->getMessage();
            }

            $assertSame(
                'terminal_state',
                'upload on cancelled case rejected',
                'Le fotografie non possono essere modificate quando la pratica è chiusa o annullata.',
                $terminalUploadMessage
            );

            $terminalRemovalMessage = null;

            try {
                $photoManager->removePhoto(
                    productCase: $cancelledCase,
                    removedBy: $user,
                    media: $cancelledMedia,
                );
            } catch (RuntimeException $exception) {
                $terminalRemovalMessage =
                    $exception->getMessage();
            }

            $assertSame(
                'terminal_state',
                'removal on cancelled case rejected',
                'Le fotografie non possono essere modificate quando la pratica è chiusa o annullata.',
                $terminalRemovalMessage
            );

            $assertSame(
                'terminal_state',
                'existing photo preserved',
                true,
                Media::query()
                    ->whereKey(
                        $cancelledMedia->id
                    )
                    ->exists()
            );
        } catch (Throwable $exception) {
            $rows[] = [
                'runtime',
                'photo workflow completed',
                'FAIL',
            ];

            $failures[] = [
                'scenario' => 'runtime',
                'assertion' =>
                    'photo workflow completed',
                'expected' => 'no exception',
                'actual' =>
                    $exception::class
                    . ': '
                    . $exception->getMessage(),
            ];
        } finally {
            /*
             * Un rollback SQL non elimina i file fisici creati da Media
             * Library. Li eliminiamo esplicitamente prima del rollback.
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
         | Verifica rollback e filesystem
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
            'team count restored',
            $teamsBefore,
            DB::table('teams')->count()
        );

        $remainingMediaPaths = array_values(
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
            'Product case photo checks passed.'
        );

        return self::SUCCESS;
    }
}