<?php

namespace App\Console\Commands\ProductVault;

use App\Livewire\ProductCases\ProductCaseShow;
use App\Models\Product;
use App\Models\ProductCase;
use App\Models\ProductCaseEvent;
use App\Models\User;
use App\Services\ProductCases\ProductCaseCreator;
use App\Services\ProductCases\ProductCasePhotoManager;
use App\Services\ProductCases\ProductCaseStatusTransitionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

final class TestProductCasePhotoManagementUiCommand
    extends Command
{
    protected $signature =
        'product-vault:test-product-case-photo-management-ui';

    protected $description =
        'Verifica con rollback la gestione UI delle fotografie private della pratica.';

    public function handle(
        ProductCaseCreator $creator,
        ProductCasePhotoManager $photoManager,
        ProductCaseStatusTransitionService $transitionService
    ): int {
        $rows = [];
        $failures = [];

        $createdCaseId = null;
        $createdMediaId = null;

        $temporaryPaths = [];
        $mediaPaths = [];

        $casesBefore =
            ProductCase::query()->count();

        $eventsBefore =
            ProductCaseEvent::query()->count();

        $mediaBefore =
            Media::query()->count();

        $caseDocumentLinksBefore =
            DB::table(
                'product_case_documents'
            )->count();

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

        /*
         * PNG RGB 1x1 valido, generato senza dipendenze esterne.
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
                "\x00\x35\x79\xBD";

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
            string $content,
            string $originalName
        ) use (&$temporaryPaths): UploadedFile {
            $path = tempnam(
                sys_get_temp_dir(),
                'pv-case-photo-ui-'
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
                    $originalName,

                mimeType:
                    'image/png',

                error:
                    UPLOAD_ERR_OK,

                test:
                    true,
            );
        };

        $render = function (
            ProductCaseShow $component
        ): string {
            return $component
                ->render()
                ->with([
                    'errors' =>
                        new ViewErrorBag(),

                    'productCase' =>
                        $component->productCase,

                    'readiness' =>
                        $component->readiness,

                    'timeline' =>
                        $component->timeline,

                    'issuePhotos' =>
                        $component->issuePhotos,

                    'statusLabel' =>
                        $component->statusLabel,

                    'statusBadgeClasses' =>
                        $component
                            ->statusBadgeClasses,

                    'readinessLabel' =>
                        $component->readinessLabel,

                    'readinessBadgeClasses' =>
                        $component
                            ->readinessBadgeClasses,

                    'usabilityLabel' =>
                        $component->usabilityLabel,

                    'accidentalDamageLabel' =>
                        $component
                            ->accidentalDamageLabel,

                    'requestDraftSourceLabel' =>
                        $component
                            ->requestDraftSourceLabel,

                    'isEditingDetails' =>
                        $component
                            ->isEditingDetails,

                    'detailsTitle' =>
                        $component->detailsTitle,

                    'detailsDescription' =>
                        $component
                            ->detailsDescription,

                    'detailsOccurredOn' =>
                        $component
                            ->detailsOccurredOn,

                    'detailsUsabilityStatus' =>
                        $component
                            ->detailsUsabilityStatus,

                    'detailsAccidentalDamageDeclared' =>
                        $component
                            ->detailsAccidentalDamageDeclared,

                    'detailsAccidentalDamageNotes' =>
                        $component
                            ->detailsAccidentalDamageNotes,

                    'detailsSuccessMessage' =>
                        $component
                            ->detailsSuccessMessage,

                    'selectableDocuments' =>
                        $component
                            ->selectableDocuments,

                    'isManagingDocuments' =>
                        $component
                            ->isManagingDocuments,

                    'documentToSelectId' =>
                        $component
                            ->documentToSelectId,

                    'documentSelectionNotes' =>
                        $component
                            ->documentSelectionNotes,

                    'documentsSuccessMessage' =>
                        $component
                            ->documentsSuccessMessage,

                    'isManagingPhotos' =>
                        $component
                            ->isManagingPhotos,

                    'photoUpload' =>
                        $component
                            ->photoUpload,

                    'photosSuccessMessage' =>
                        $component
                            ->photosSuccessMessage,

                    'requestDraftSuccessMessage' =>
                        $component
                            ->requestDraftSuccessMessage,

                    'requestDraftErrorMessage' =>
                        $component
                            ->requestDraftErrorMessage,
                ])
                ->render();
        };

        DB::beginTransaction();

        try {
            /*
             |--------------------------------------------------------------------------
             | Fixture
             |--------------------------------------------------------------------------
             */

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

            Auth::login(
                $user
            );

            $productCase =
                $creator->create(
                    product:
                        $product,

                    openedBy:
                        $user,

                    attributes: [
                        'title' =>
                            'Problema con fotografie private',

                        'description' =>
                            'Il prodotto presenta un difetto visibile da documentare.',

                        'occurred_on' =>
                            today()
                                ->toDateString(),

                        'usability_status' =>
                            ProductCase
                                ::USABILITY_PARTIALLY_USABLE,

                        'accidental_damage_declared' =>
                            false,
                    ],
                );

            $createdCaseId =
                (int) $productCase->id;

            $caseMetadataBefore =
                $productCase->metadata;

            $requestDraftBefore =
                $productCase
                    ->request_draft;

            $caseUpdatedAtBefore =
                $productCase
                    ->updated_at
                    ?->toISOString();

            $caseDocumentLinksBeforeOperations =
                DB::table(
                    'product_case_documents'
                )->count();

            $component =
                app(
                    ProductCaseShow::class
                );

            $component->mount(
                $productCase
            );

            $readinessBefore =
                $component->readiness;

            /*
             |--------------------------------------------------------------------------
             | Stato iniziale
             |--------------------------------------------------------------------------
             */

            $assertSame(
                'initial',
                'photo manager starts closed',
                false,
                $component
                    ->isManagingPhotos
            );

            $assertSame(
                'initial',
                'case starts without photos',
                [],
                $component
                    ->issuePhotos
            );

            $initialHtml =
                $render(
                    $component
                );

            $assertSame(
                'html',
                'photo management action visible',
                true,
                str_contains(
                    $initialHtml,
                    'start-product-case-photo-management'
                )
            );

            $assertSame(
                'html',
                'photo manager hidden initially',
                false,
                str_contains(
                    $initialHtml,
                    'product-case-photo-manager'
                )
            );

            $assertSame(
                'html',
                'no file input before opening',
                false,
                str_contains(
                    $initialHtml,
                    'type="file"'
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Apertura pannello
             |--------------------------------------------------------------------------
             */

            $component
                ->startPhotoManagement();

            $assertSame(
                'manager',
                'photo manager opened',
                true,
                $component
                    ->isManagingPhotos
            );

            $assertSame(
                'manager',
                'details editor closed',
                false,
                $component
                    ->isEditingDetails
            );

            $assertSame(
                'manager',
                'document manager closed',
                false,
                $component
                    ->isManagingDocuments
            );

            $openHtml =
                $render(
                    $component
                );

            $assertSame(
                'html',
                'photo manager rendered',
                true,
                str_contains(
                    $openHtml,
                    'product-case-photo-manager'
                )
            );

            $assertSame(
                'html',
                'upload action rendered',
                true,
                str_contains(
                    $openHtml,
                    'wire:submit.prevent="uploadPhoto"'
                )
            );

            $assertSame(
                'html',
                'private image input rendered',
                true,
                str_contains(
                    $openHtml,
                    'type="file"'
                )
            );

            $assertSame(
                'html',
                'input accepts supported formats',
                true,
                str_contains(
                    $openHtml,
                    '.jpg,.jpeg,.png,.webp'
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Validazione upload vuoto
             |--------------------------------------------------------------------------
             */

            $eventsBeforeInvalid =
                ProductCaseEvent::query()
                    ->count();

            $mediaBeforeInvalid =
                Media::query()->count();

            $emptyUploadRejected =
                false;

            $invalidFields =
                [];

            try {
                $component->uploadPhoto(
                    $photoManager
                );
            } catch (
                ValidationException $exception
            ) {
                $emptyUploadRejected =
                    true;

                $invalidFields =
                    array_keys(
                        $exception->errors()
                    );
            }

            $assertSame(
                'validation',
                'empty upload rejected',
                true,
                $emptyUploadRejected
            );

            $assertSame(
                'validation',
                'photo field reported',
                [
                    'photoUpload',
                ],
                $invalidFields
            );

            $assertSame(
                'validation',
                'invalid upload creates no media',
                $mediaBeforeInvalid,
                Media::query()->count()
            );

            $assertSame(
                'validation',
                'invalid upload creates no event',
                $eventsBeforeInvalid,
                ProductCaseEvent::query()
                    ->count()
            );

            /*
             |--------------------------------------------------------------------------
             | Upload valido
             |--------------------------------------------------------------------------
             */

            $pngContent =
                $makePngContent();

            $originalFilename =
                'foto-problema-privata.png';

            $eventsBeforeUpload =
                ProductCaseEvent::query()
                    ->count();

            $mediaBeforeUpload =
                Media::query()->count();

            $component->photoUpload =
                $makeUpload(
                    content:
                        $pngContent,

                    originalName:
                        $originalFilename,
                );

            $component->uploadPhoto(
                $photoManager
            );

            $storedMedia = Media::query()
                ->where(
                    'model_type',
                    $productCase
                        ->getMorphClass()
                )
                ->where(
                    'model_id',
                    $productCase->id
                )
                ->where(
                    'collection_name',
                    ProductCase
                        ::MEDIA_COLLECTION_ISSUE_PHOTOS
                )
                ->orderByDesc('id')
                ->first();

            if ($storedMedia === null) {
                throw new RuntimeException(
                    'Fotografia caricata non disponibile.'
                );
            }

            $createdMediaId =
                (int) $storedMedia->id;

            $storedPath =
                $storedMedia->getPath();

            $mediaPaths[] =
                $storedPath;

            $assertSame(
                'upload',
                'one media created',
                $mediaBeforeUpload + 1,
                Media::query()->count()
            );

            $assertSame(
                'upload',
                'one event created',
                $eventsBeforeUpload + 1,
                ProductCaseEvent::query()
                    ->count()
            );

            $assertSame(
                'upload',
                'private disk used',
                'local',
                $storedMedia->disk
            );

            $assertSame(
                'upload',
                'issue photo collection used',
                ProductCase
                    ::MEDIA_COLLECTION_ISSUE_PHOTOS,
                $storedMedia
                    ->collection_name
            );

            $assertSame(
                'upload',
                'original filename preserved',
                $originalFilename,
                $storedMedia
                    ->getCustomProperty(
                        'original_filename'
                    )
            );

            $assertSame(
                'upload',
                'physical filename randomized',
                false,
                $storedMedia->file_name
                    === $originalFilename
            );

            $assertSame(
                'upload',
                'physical file exists',
                true,
                is_file(
                    $storedPath
                )
            );

            $storedHash =
                $storedMedia
                    ->getCustomProperty(
                        'sha256'
                    );

            $assertSame(
                'upload',
                'sha256 stored',
                hash(
                    'sha256',
                    $pngContent
                ),
                $storedHash
            );

            $assertSame(
                'component',
                'one photo immediately visible',
                1,
                count(
                    $component
                        ->issuePhotos
                )
            );

            $assertSame(
                'component',
                'photo id exposed',
                (int) $storedMedia->id,
                (int) data_get(
                    $component
                        ->issuePhotos,
                    '0.id'
                )
            );

            $assertSame(
                'component',
                'original filename exposed',
                $originalFilename,
                data_get(
                    $component
                        ->issuePhotos,
                    '0.original_filename'
                )
            );

            $assertSame(
                'component',
                'upload property reset',
                null,
                $component
                    ->photoUpload
            );

            $assertSame(
                'component',
                'upload success exposed',
                'Fotografia aggiunta alla pratica.',
                $component
                    ->photosSuccessMessage
            );

            /*
             * Il contratto pubblico non deve contenere informazioni
             * sull’ubicazione fisica del file.
             */
            $photoMetadata =
                $component
                    ->issuePhotos[0];

            $assertSame(
                'privacy',
                'physical filename not exposed',
                false,
                array_key_exists(
                    'file_name',
                    $photoMetadata
                )
            );

            $assertSame(
                'privacy',
                'disk not exposed',
                false,
                array_key_exists(
                    'disk',
                    $photoMetadata
                )
            );

            $assertSame(
                'privacy',
                'path not exposed',
                false,
                array_key_exists(
                    'path',
                    $photoMetadata
                )
            );

            $assertSame(
                'privacy',
                'url not exposed',
                false,
                array_key_exists(
                    'url',
                    $photoMetadata
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
                    ->orderByDesc('id')
                    ->first();

            $assertSame(
                'event',
                'photo added event available',
                true,
                $photoAddedEvent !== null
            );

            $assertSame(
                'event',
                'event references media',
                (int) $storedMedia->id,
                (int) data_get(
                    $photoAddedEvent
                        ?->metadata,
                    'media_id'
                )
            );

            $assertSame(
                'event',
                'event references uploader',
                (int) $user->id,
                (int) data_get(
                    $photoAddedEvent
                        ?->metadata,
                    'uploaded_by_user_id'
                )
            );

            $timelineAdded =
                collect(
                    data_get(
                        $component->timeline,
                        'events',
                        []
                    )
                )
                    ->where(
                        'type',
                        ProductCaseEvent
                            ::TYPE_PHOTO_ADDED
                    )
                    ->last();

            $assertSame(
                'timeline',
                'added event immediately visible',
                true,
                $timelineAdded !== null
            );

            $assertSame(
                'timeline',
                'photo reference available',
                'available',
                data_get(
                    $timelineAdded,
                    'reference.state'
                )
            );

            $assertSame(
                'timeline',
                'original filename normalized',
                $originalFilename,
                data_get(
                    $timelineAdded,
                    'details.original_filename'
                )
            );

            $timelineJson =
                json_encode(
                    $component->timeline
                );

            if (! is_string($timelineJson)) {
                throw new RuntimeException(
                    'Timeline non serializzabile.'
                );
            }

            $assertSame(
                'privacy',
                'timeline hides physical filename',
                false,
                str_contains(
                    $timelineJson,
                    $storedMedia->file_name
                )
            );

            $uploadedHtml =
                $render(
                    $component
                );

            $assertSame(
                'html',
                'original filename rendered',
                true,
                str_contains(
                    $uploadedHtml,
                    $originalFilename
                )
            );

            $assertSame(
                'html',
                'remove action rendered',
                true,
                str_contains(
                    $uploadedHtml,
                    'remove-product-case-photo-'
                    . $storedMedia->id
                )
            );

            $assertSame(
                'privacy',
                'physical filename hidden from html',
                false,
                str_contains(
                    $uploadedHtml,
                    $storedMedia->file_name
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Upload duplicato
             |--------------------------------------------------------------------------
             */

            $eventsBeforeDuplicate =
                ProductCaseEvent::query()
                    ->count();

            $mediaBeforeDuplicate =
                Media::query()->count();

            $component->photoUpload =
                $makeUpload(
                    content:
                        $pngContent,

                    originalName:
                        'nome-diverso-stesso-contenuto.png',
                );

            $component->uploadPhoto(
                $photoManager
            );

            $assertSame(
                'deduplication',
                'duplicate creates no media',
                $mediaBeforeDuplicate,
                Media::query()->count()
            );

            $assertSame(
                'deduplication',
                'duplicate creates no event',
                $eventsBeforeDuplicate,
                ProductCaseEvent::query()
                    ->count()
            );

            $assertSame(
                'deduplication',
                'single photo remains visible',
                1,
                count(
                    $component
                        ->issuePhotos
                )
            );

            $assertSame(
                'deduplication',
                'duplicate feedback exposed',
                'La stessa fotografia era già presente nella pratica.',
                $component
                    ->photosSuccessMessage
            );

            /*
             |--------------------------------------------------------------------------
             | Rimozione
             |--------------------------------------------------------------------------
             */

            $eventsBeforeRemoval =
                ProductCaseEvent::query()
                    ->count();

            $mediaBeforeRemoval =
                Media::query()->count();

            $component->removePhoto(
                mediaId:
                    (int) $storedMedia->id,

                photoManager:
                    $photoManager,
            );

            $assertSame(
                'removal',
                'one media removed',
                $mediaBeforeRemoval - 1,
                Media::query()->count()
            );

            $assertSame(
                'removal',
                'one event created',
                $eventsBeforeRemoval + 1,
                ProductCaseEvent::query()
                    ->count()
            );

            $assertSame(
                'removal',
                'media record removed',
                false,
                Media::query()
                    ->whereKey(
                        $storedMedia->id
                    )
                    ->exists()
            );

            $assertSame(
                'removal',
                'physical file removed',
                false,
                is_file(
                    $storedPath
                )
            );

            $assertSame(
                'component',
                'photo list refreshed',
                [],
                $component
                    ->issuePhotos
            );

            $assertSame(
                'component',
                'removal success exposed',
                'Fotografia rimossa dalla pratica.',
                $component
                    ->photosSuccessMessage
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
                    ->orderByDesc('id')
                    ->first();

            $assertSame(
                'event',
                'photo removed event available',
                true,
                $photoRemovedEvent !== null
            );

            $assertSame(
                'event',
                'removed filename snapshot preserved',
                $originalFilename,
                data_get(
                    $photoRemovedEvent
                        ?->metadata,
                    'original_filename'
                )
            );

            $assertSame(
                'event',
                'remover stored',
                (int) $user->id,
                (int) data_get(
                    $photoRemovedEvent
                        ?->metadata,
                    'removed_by_user_id'
                )
            );

            $timelineEvents =
                collect(
                    data_get(
                        $component->timeline,
                        'events',
                        []
                    )
                );

            $timelineAdded =
                $timelineEvents
                    ->where(
                        'type',
                        ProductCaseEvent
                            ::TYPE_PHOTO_ADDED
                    )
                    ->last();

            $timelineRemoved =
                $timelineEvents
                    ->where(
                        'type',
                        ProductCaseEvent
                            ::TYPE_PHOTO_REMOVED
                    )
                    ->last();

            $assertSame(
                'timeline',
                'old add event reflects removal',
                'removed',
                data_get(
                    $timelineAdded,
                    'reference.state'
                )
            );

            $assertSame(
                'timeline',
                'removal event reflects removal',
                'removed',
                data_get(
                    $timelineRemoved,
                    'reference.state'
                )
            );

            $assertSame(
                'timeline',
                'removal filename preserved',
                $originalFilename,
                data_get(
                    $timelineRemoved,
                    'details.original_filename'
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Protezioni e scope
             |--------------------------------------------------------------------------
             */

            $currentCase =
                ProductCase::query()
                    ->findOrFail(
                        $productCase->id
                    );

            $assertSame(
                'protection',
                'case remains draft',
                ProductCase::STATUS_DRAFT,
                $currentCase->status
            );

            $assertSame(
                'protection',
                'case metadata unchanged',
                $caseMetadataBefore,
                $currentCase->metadata
            );

            $assertSame(
                'protection',
                'request draft unchanged',
                $requestDraftBefore,
                $currentCase
                    ->request_draft
            );

            $assertSame(
                'protection',
                'case timestamp unchanged',
                $caseUpdatedAtBefore,
                $currentCase
                    ->updated_at
                    ?->toISOString()
            );

            $assertSame(
                'scope',
                'document links unchanged',
                $caseDocumentLinksBeforeOperations,
                DB::table(
                    'product_case_documents'
                )->count()
            );

            $assertSame(
                'readiness',
                'photo operations do not alter readiness',
                $readinessBefore,
                $component
                    ->readiness
            );

            /*
             |--------------------------------------------------------------------------
             | Chiusura pannello
             |--------------------------------------------------------------------------
             */

            $component
                ->cancelPhotoManagement();

            $assertSame(
                'cancellation',
                'photo manager closed',
                false,
                $component
                    ->isManagingPhotos
            );

            $assertSame(
                'cancellation',
                'upload reset',
                null,
                $component
                    ->photoUpload
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
                            'Product Case Photos UI '
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

            $permissionRegistrar
                ->setPermissionsTeamId(
                    $otherTeamId
                );

            $user->unsetRelation('roles');
            $user->unsetRelation('permissions');

            Auth::setUser(
                $user
            );

            $eventsBeforeCrossTeam =
                ProductCaseEvent::query()
                    ->count();

            $mediaBeforeCrossTeam =
                Media::query()->count();

            $crossTeamRejected =
                false;

            try {
                $component
                    ->startPhotoManagement();
            } catch (
                AuthorizationException
            ) {
                $crossTeamRejected =
                    true;
            }

            $assertSame(
                'authorization',
                'cross-team management rejected',
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

            $assertSame(
                'authorization',
                'cross-team attempt creates no media',
                $mediaBeforeCrossTeam,
                Media::query()->count()
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

            $permissionRegistrar
                ->setPermissionsTeamId(
                    $product->team_id
                );

            $user->unsetRelation('roles');
            $user->unsetRelation('permissions');

            Auth::setUser(
                $user
            );

            /*
             |--------------------------------------------------------------------------
             | Stato terminale
             |--------------------------------------------------------------------------
             */

            $cancelledCase =
                $transitionService
                    ->transition(
                        productCase:
                            $currentCase,

                        performedBy:
                            $user,

                        targetStatus:
                            ProductCase
                                ::STATUS_CANCELLED,
                    );

            $terminalComponent =
                app(
                    ProductCaseShow::class
                );

            $terminalComponent->mount(
                $cancelledCase
            );

            $terminalHtml =
                $render(
                    $terminalComponent
                );

            $assertSame(
                'state',
                'photo action hidden in terminal state',
                false,
                str_contains(
                    $terminalHtml,
                    'start-product-case-photo-management'
                )
            );

            $assertSame(
                'state',
                'photo manager hidden in terminal state',
                false,
                str_contains(
                    $terminalHtml,
                    'product-case-photo-manager'
                )
            );

            $eventsBeforeTerminalAttempt =
                ProductCaseEvent::query()
                    ->count();

            $mediaBeforeTerminalAttempt =
                Media::query()->count();

            $terminalRejected =
                false;

            try {
                $terminalComponent
                    ->startPhotoManagement();
            } catch (
                RuntimeException
            ) {
                $terminalRejected =
                    true;
            }

            $assertSame(
                'state',
                'terminal management rejected',
                true,
                $terminalRejected
            );

            $assertSame(
                'state',
                'terminal attempt creates no event',
                $eventsBeforeTerminalAttempt,
                ProductCaseEvent::query()
                    ->count()
            );

            $assertSame(
                'state',
                'terminal attempt creates no media',
                $mediaBeforeTerminalAttempt,
                Media::query()->count()
            );
        } catch (Throwable $exception) {
            $rows[] = [
                'runtime',
                'photo management UI workflow completed',
                'FAIL',
            ];

            $failures[] = [
                'scenario' =>
                    'runtime',

                'assertion' =>
                    'photo management UI workflow completed',

                'expected' =>
                    'no exception',

                'actual' =>
                    $exception::class
                    . ': '
                    . $exception
                        ->getMessage(),
            ];
        } finally {
            Auth::logout();

            $permissionRegistrar
                ->setPermissionsTeamId(
                    null
                );

            DB::rollBack();

            /*
             * Il rollback SQL non elimina eventuali file fisici rimasti
             * dopo un’interruzione anticipata del test.
             */
            foreach (
                array_unique([
                    ...$temporaryPaths,
                    ...$mediaPaths,
                ]) as $path
            ) {
                if (
                    is_string($path)
                    && is_file($path)
                ) {
                    @unlink(
                        $path
                    );
                }
            }
        }

        /*
         |--------------------------------------------------------------------------
         | Rollback e pulizia
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

        if ($createdMediaId !== null) {
            $assertSame(
                'rollback',
                'temporary media removed',
                false,
                Media::query()
                    ->whereKey(
                        $createdMediaId
                    )
                    ->exists()
            );
        }

        $remainingPhysicalFiles =
            collect([
                ...$temporaryPaths,
                ...$mediaPaths,
            ])
                ->filter(
                    fn (mixed $path): bool =>
                        is_string($path)
                        && is_file($path)
                )
                ->values()
                ->all();

        $assertSame(
            'cleanup',
            'physical files removed',
            [],
            $remainingPhysicalFiles
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
            'Product case photo management UI checks passed.'
        );

        return self::SUCCESS;
    }
}