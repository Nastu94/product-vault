<?php

namespace App\Console\Commands\ProductVault;

use App\Livewire\ProductCases\ProductCaseShow;
use App\Models\Product;
use App\Models\ProductCase;
use App\Models\ProductCaseEvent;
use App\Models\User;
use App\Services\ProductCases\ProductCaseCreator;
use App\Services\ProductCases\ProductCaseDocumentSelector;
use App\Services\ProductCases\ProductCasePhotoManager;
use App\Services\ProductCases\ProductCaseRequestDraftGenerator;
use App\Services\ProductCases\ProductCaseStatusTransitionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

final class TestProductCaseShowReadOnlyCommand
    extends Command
{
    /**
     * @var string
     */
    protected $signature =
        'product-vault:test-product-case-show-read-only';

    /**
     * @var string
     */
    protected $description =
        'Verifica con rollback la pagina read-only di dettaglio pratica.';

    public function handle(
        ProductCaseCreator $creator,
        ProductCaseDocumentSelector $documentSelector,
        ProductCasePhotoManager $photoManager,
        ProductCaseRequestDraftGenerator $generator,
        ProductCaseStatusTransitionService $transitionService
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

        $teamsBefore =
            DB::table('teams')->count();

        $linksBefore =
            DB::table(
                'product_case_documents'
            )->count();

        /*
         * Nei comandi CLI non viene eseguito il middleware web che imposta
         * il team corrente per Spatie Permission.
         */
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
            string $content
        ) use (&$temporaryPaths): UploadedFile {
            $path = tempnam(
                sys_get_temp_dir(),
                'pv-case-show-'
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
                    'foto-problema-read-only.png',

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
            /*
             |--------------------------------------------------------------------------
             | Contratto rotta e vista
             |--------------------------------------------------------------------------
             */

            $assertSame(
                'route',
                'show route registered',
                true,
                Route::has(
                    'product-cases.show'
                )
            );

            $route = Route::getRoutes()
                ->getByName(
                    'product-cases.show'
                );

            $assertSame(
                'route',
                'show route uri',
                'product-cases/{productCase}',
                $route?->uri()
            );

            $assertSame(
                'route',
                'show route component',
                ProductCaseShow::class,
                $route?->getActionName()
            );

            $assertSame(
                'view',
                'show view exists',
                true,
                View::exists(
                    'livewire.product-cases.product-case-show'
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Fixture
             |--------------------------------------------------------------------------
             */

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

            /*
             * Allineiamo anche il contesto team usato da Spatie Permission.
             */
            $permissionRegistrar
                ->setPermissionsTeamId(
                    $product->team_id
                );

            $user->unsetRelation(
                'roles'
            );

            $user->unsetRelation(
                'permissions'
            );

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
                            'Problema visualizzato in sola lettura',

                        'description' =>
                            'Il prodotto non si avvia dopo un utilizzo normale.',

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

                notes:
                    'Documento principale della pratica.',
            );

            $media =
                $photoManager->addPhoto(
                    productCase:
                        $productCase,

                    uploadedBy:
                        $user,

                    photo:
                        $makeUpload(
                            $makePngContent()
                        ),
                );

            $mediaPaths[] =
                $media->getPath();

            $productCase =
                $generator->generate(
                    productCase:
                        $productCase,

                    generatedBy:
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

            $draft =
                $productCase
                    ->request_draft;

            if (
                ! is_string($draft)
                || trim($draft) === ''
            ) {
                throw new RuntimeException(
                    'Bozza test non disponibile.'
                );
            }

            $caseMetadataBefore =
                $productCase->metadata;

            $caseUpdatedAtBefore =
                $productCase
                    ->updated_at
                    ?->toISOString();

            $caseCountBeforeRender =
                ProductCase::query()->count();

            $eventCountBeforeRender =
                ProductCaseEvent::query()
                    ->count();

            $mediaCountBeforeRender =
                Media::query()->count();

            $linkCountBeforeRender =
                DB::table(
                    'product_case_documents'
                )->count();

            /*
             |--------------------------------------------------------------------------
             | Mount autorizzato e rendering
             |--------------------------------------------------------------------------
             */

            Auth::login(
                $user
            );

            $component =
                app(
                    ProductCaseShow::class
                );

            $component->mount(
                $productCase
            );

            $assertSame(
                'component',
                'case loaded',
                (int) $productCase->id,
                (int) $component
                    ->productCase
                    ->id
            );

            $assertSame(
                'component',
                'status label',
                'Pronta per il contatto',
                $component
                    ->statusLabel
            );

            $assertSame(
                'component',
                'readiness ready',
                true,
                data_get(
                    $component
                        ->readiness,
                    'is_ready_to_contact'
                )
            );

            $assertSame(
                'component',
                'one private photo metadata entry',
                1,
                count(
                    $component
                        ->issuePhotos
                )
            );

            $assertSame(
                'component',
                'timeline exposed',
                true,
                count(
                    data_get(
                        $component
                            ->timeline,
                        'events',
                        []
                    )
                ) > 0
            );

            $view =
                $component->render();

            $assertSame(
                'view',
                'correct blade rendered',
                'livewire.product-cases.product-case-show',
                $view->name()
            );

            /*
             * Nel normale ciclo Livewire le proprietà pubbliche del
             * componente vengono rese disponibili automaticamente alla view.
             *
             * Qui stiamo renderizzando direttamente una View Laravel da un
             * comando Artisan, quindi passiamo esplicitamente lo stesso
             * contratto pubblico usato dal template.
             */
            $html =
                $view
                    ->with([
                        'productCase' =>
                            $component
                                ->productCase,

                        'readiness' =>
                            $component
                                ->readiness,

                        'timeline' =>
                            $component
                                ->timeline,

                        'issuePhotos' =>
                            $component
                                ->issuePhotos,

                        'statusLabel' =>
                            $component
                                ->statusLabel,

                        'statusBadgeClasses' =>
                            $component
                                ->statusBadgeClasses,

                        'readinessLabel' =>
                            $component
                                ->readinessLabel,

                        'readinessBadgeClasses' =>
                            $component
                                ->readinessBadgeClasses,

                        'usabilityLabel' =>
                            $component
                                ->usabilityLabel,

                        'accidentalDamageLabel' =>
                            $component
                                ->accidentalDamageLabel,

                        'requestDraftSourceLabel' =>
                            $component
                                ->requestDraftSourceLabel,

                        /*
                         * Proprietà dell’editor introdotte dalla 7B2b.
                         *
                         * La fixture è ready_to_contact, quindi form e
                         * pulsante di modifica devono restare nascosti.
                         */
                        'isEditingDetails' =>
                            $component
                                ->isEditingDetails,

                        'detailsTitle' =>
                            $component
                                ->detailsTitle,

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

                        'isEditingRequestDraft' =>
                            $component
                                ->isEditingRequestDraft,

                        'requestDraftBody' =>
                            $component
                                ->requestDraftBody,
                    ])
                    ->render();

            $assertSame(
                'html',
                'case title visible',
                true,
                str_contains(
                    $html,
                    'Problema visualizzato in sola lettura'
                )
            );

            $assertSame(
                'html',
                'product visible',
                true,
                str_contains(
                    $html,
                    e($product->name)
                )
            );

            $assertSame(
                'html',
                'selected document visible',
                true,
                str_contains(
                    $html,
                    e(
                        $document
                            ->original_filename
                    )
                )
            );

            $assertSame(
                'html',
                'original photo filename visible',
                true,
                str_contains(
                    $html,
                    'foto-problema-read-only.png'
                )
            );

            $assertSame(
                'html',
                'current request draft visible',
                true,
                str_contains(
                    $html,
                    e($draft)
                )
                || str_contains(
                    html_entity_decode(
                        $html
                    ),
                    $draft
                )
            );

            $assertSame(
                'html',
                'timeline visible',
                true,
                str_contains(
                    $html,
                    'Timeline della pratica'
                )
            );

            $assertSame(
                'privacy',
                'physical media filename hidden',
                false,
                str_contains(
                    $html,
                    $media->file_name
                )
            );

            $assertSame(
                'read_only',
                'no form rendered',
                false,
                str_contains(
                    $html,
                    '<form'
                )
            );

            $assertSame(
                'document_management',
                'document management action available',
                true,
                str_contains(
                    $html,
                    'start-product-case-document-management'
                )
            );

            $assertSame(
                'document_management',
                'document manager starts closed',
                false,
                str_contains(
                    $html,
                    'product-case-document-manager'
                )
            );

            $assertSame(
                'read_only',
                'no wire submit action rendered',
                false,
                str_contains(
                    $html,
                    'wire:submit'
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Assenza di scritture
             |--------------------------------------------------------------------------
             */

            $productCase->refresh();

            $assertSame(
                'read_only',
                'case count unchanged',
                $caseCountBeforeRender,
                ProductCase::query()->count()
            );

            $assertSame(
                'read_only',
                'event count unchanged',
                $eventCountBeforeRender,
                ProductCaseEvent::query()
                    ->count()
            );

            $assertSame(
                'read_only',
                'media count unchanged',
                $mediaCountBeforeRender,
                Media::query()->count()
            );

            $assertSame(
                'read_only',
                'document links unchanged',
                $linkCountBeforeRender,
                DB::table(
                    'product_case_documents'
                )->count()
            );

            $assertSame(
                'read_only',
                'case metadata unchanged',
                $caseMetadataBefore,
                $productCase->metadata
            );

            $assertSame(
                'read_only',
                'case timestamp unchanged',
                $caseUpdatedAtBefore,
                $productCase
                    ->updated_at
                    ?->toISOString()
            );

            $assertSame(
                'read_only',
                'case model remains clean',
                false,
                $productCase->isDirty()
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
                            'Product Case Show '
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

            $user->unsetRelation(
                'roles'
            );

            $user->unsetRelation(
                'permissions'
            );

            Auth::setUser(
                $user
            );

            $crossTeamRejected =
                false;

            try {
                $foreignComponent =
                    app(
                        ProductCaseShow::class
                    );

                $foreignComponent->mount(
                    $productCase->fresh()
                );
            } catch (
                AuthorizationException
            ) {
                $crossTeamRejected =
                    true;
            }

            $assertSame(
                'authorization',
                'cross-team view rejected',
                true,
                $crossTeamRejected
            );

            $assertSame(
                'authorization',
                'cross-team attempt creates no event',
                $eventCountBeforeRender,
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

            $permissionRegistrar
                ->setPermissionsTeamId(
                    $product->team_id
                );

            $user->unsetRelation(
                'roles'
            );

            $user->unsetRelation(
                'permissions'
            );

            Auth::setUser(
                $user
            );
        } catch (Throwable $exception) {
            $rows[] = [
                'runtime',
                'read-only show workflow completed',
                'FAIL',
            ];

            $failures[] = [
                'scenario' =>
                    'runtime',

                'assertion' =>
                    'read-only show workflow completed',

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
         | Verifica rollback
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
            'team count restored',
            $teamsBefore,
            DB::table('teams')->count()
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
            'Product case read-only show checks passed.'
        );

        return self::SUCCESS;
    }
}