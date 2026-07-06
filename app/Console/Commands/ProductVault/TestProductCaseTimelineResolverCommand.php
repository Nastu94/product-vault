<?php

namespace App\Console\Commands\ProductVault;

use App\Models\Product;
use App\Models\ProductCase;
use App\Models\ProductCaseEvent;
use App\Models\User;
use App\Services\ProductCases\ProductCaseCreator;
use App\Services\ProductCases\ProductCaseDocumentSelector;
use App\Services\ProductCases\ProductCasePhotoManager;
use App\Services\ProductCases\ProductCaseRequestDraftEditor;
use App\Services\ProductCases\ProductCaseRequestDraftGenerator;
use App\Services\ProductCases\ProductCaseStatusTransitionService;
use App\Services\ProductCases\ProductCaseTimelineResolver;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

final class TestProductCaseTimelineResolverCommand
    extends Command
{
    /**
     * @var string
     */
    protected $signature =
        'product-vault:test-product-case-timeline-resolver';

    /**
     * @var string
     */
    protected $description =
        'Verifica con rollback la lettura normalizzata della timeline pratica.';

    public function handle(
        ProductCaseCreator $creator,
        ProductCaseDocumentSelector $documentSelector,
        ProductCasePhotoManager $photoManager,
        ProductCaseRequestDraftGenerator $generator,
        ProductCaseRequestDraftEditor $editor,
        ProductCaseStatusTransitionService $transitionService,
        ProductCaseTimelineResolver $resolver
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
                "\x00\x41\x82\xC3";

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
                'pv-case-timeline-'
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
                    'foto-timeline.png',

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
                            'Timeline normalizzata',

                        'description' =>
                            'Pratica completa per verificare il read model della timeline.',

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
                    'Documento principale.',
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

            $mediaPath =
                $media->getPath();

            $mediaPaths[] =
                $mediaPath;

            $productCase =
                $generator->generate(
                    productCase:
                        $productCase,

                    generatedBy:
                        $user,
                );

            $generatedDraftHash =
                hash(
                    'sha256',
                    $productCase
                        ->request_draft
                );

            $manualDraft =
                'Bozza manuale corrente della pratica.';

            $productCase =
                $editor->saveManualDraft(
                    productCase:
                        $productCase,

                    editedBy:
                        $user,

                    draft:
                        $manualDraft,
                );

            $manualDraftHash =
                hash(
                    'sha256',
                    $manualDraft
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

            $documentSelector->deselect(
                productCase:
                    $productCase,

                document:
                    $document,

                deselectedBy:
                    $user,
            );

            $photoManager->removePhoto(
                productCase:
                    $productCase,

                removedBy:
                    $user,

                media:
                    $media,
            );

            $caseMetadataBefore =
                $productCase->metadata;

            $caseUpdatedAtBefore =
                $productCase
                    ->updated_at
                    ?->toISOString();

            $eventCountBeforeResolve =
                ProductCaseEvent::query()
                    ->count();

            /*
             |--------------------------------------------------------------------------
             | Prima risoluzione
             |--------------------------------------------------------------------------
             */

            $timeline =
                $resolver->resolve(
                    $productCase
                );

            $assertSame(
                'contract',
                'contract version',
                ProductCaseTimelineResolver::VERSION,
                $timeline['version']
            );

            $assertSame(
                'contract',
                'case id',
                (int) $productCase->id,
                $timeline[
                    'product_case_id'
                ]
            );

            $assertSame(
                'contract',
                'current status',
                ProductCase
                    ::STATUS_READY_TO_CONTACT,
                $timeline[
                    'current_status'
                ]
            );

            $assertSame(
                'contract',
                'current status label',
                'Pronta per il contatto',
                $timeline[
                    'current_status_label'
                ]
            );

            $assertSame(
                'contract',
                'eight events returned',
                8,
                $timeline[
                    'event_count'
                ]
            );

            $assertSame(
                'categories',
                'workflow event count',
                2,
                $timeline[
                    'counts_by_category'
                ]['workflow']
                ?? null
            );

            $assertSame(
                'categories',
                'evidence event count',
                4,
                $timeline[
                    'counts_by_category'
                ]['evidence']
                ?? null
            );

            $assertSame(
                'categories',
                'draft event count',
                2,
                $timeline[
                    'counts_by_category'
                ]['request_draft']
                ?? null
            );

            $eventTypes = collect(
                $timeline['events']
            )
                ->pluck('type')
                ->all();

            $assertSame(
                'ordering',
                'deterministic event order',
                [
                    ProductCaseEvent
                        ::TYPE_CASE_OPENED,

                    ProductCaseEvent
                        ::TYPE_DOCUMENT_SELECTED,

                    ProductCaseEvent
                        ::TYPE_PHOTO_ADDED,

                    ProductCaseEvent
                        ::TYPE_REQUEST_DRAFT_GENERATED,

                    ProductCaseEvent
                        ::TYPE_REQUEST_DRAFT_EDITED,

                    ProductCaseEvent
                        ::TYPE_STATUS_CHANGED,

                    ProductCaseEvent
                        ::TYPE_DOCUMENT_DESELECTED,

                    ProductCaseEvent
                        ::TYPE_PHOTO_REMOVED,
                ],
                $eventTypes
            );

            $assertSame(
                'ordering',
                'first timestamp exposed',
                true,
                is_string(
                    $timeline[
                        'first_occurred_at'
                    ]
                )
            );

            $assertSame(
                'ordering',
                'last timestamp exposed',
                true,
                is_string(
                    $timeline[
                        'last_occurred_at'
                    ]
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Actor e workflow
             |--------------------------------------------------------------------------
             */

            $openingEvent = collect(
                $timeline['events']
            )->firstWhere(
                'type',
                ProductCaseEvent
                    ::TYPE_CASE_OPENED
            );

            $assertSame(
                'actor',
                'opening actor id',
                (int) $user->id,
                data_get(
                    $openingEvent,
                    'actor.id'
                )
            );

            $assertSame(
                'actor',
                'opening actor name',
                $user->name,
                data_get(
                    $openingEvent,
                    'actor.name'
                )
            );

            $assertSame(
                'actor',
                'opening actor available',
                true,
                data_get(
                    $openingEvent,
                    'actor.is_available'
                )
            );

            $statusEvent = collect(
                $timeline['events']
            )->firstWhere(
                'type',
                ProductCaseEvent
                    ::TYPE_STATUS_CHANGED
            );

            $assertSame(
                'workflow',
                'status category',
                'workflow',
                $statusEvent[
                    'category'
                ] ?? null
            );

            $assertSame(
                'workflow',
                'status from label',
                'Bozza',
                data_get(
                    $statusEvent,
                    'details.from_status_label'
                )
            );

            $assertSame(
                'workflow',
                'status to label',
                'Pronta per il contatto',
                data_get(
                    $statusEvent,
                    'details.to_status_label'
                )
            );

            $assertSame(
                'workflow',
                'status summary',
                'Stato: Bozza → Pronta per il contatto',
                $statusEvent[
                    'summary'
                ] ?? null
            );

            /*
             |--------------------------------------------------------------------------
             | Riferimenti alle evidenze
             |--------------------------------------------------------------------------
             */

            $documentSelectedEvent =
                collect(
                    $timeline['events']
                )->firstWhere(
                    'type',
                    ProductCaseEvent
                        ::TYPE_DOCUMENT_SELECTED
                );

            $documentRemovedEvent =
                collect(
                    $timeline['events']
                )->firstWhere(
                    'type',
                    ProductCaseEvent
                        ::TYPE_DOCUMENT_DESELECTED
                );

            $assertSame(
                'document_reference',
                'document reference id',
                (int) $document->id,
                data_get(
                    $documentSelectedEvent,
                    'reference.id'
                )
            );

            $assertSame(
                'document_reference',
                'selected event reflects current removal',
                'removed',
                data_get(
                    $documentSelectedEvent,
                    'reference.state'
                )
            );

            $assertSame(
                'document_reference',
                'removed event keeps filename',
                $document
                    ->original_filename,
                data_get(
                    $documentRemovedEvent,
                    'reference.label'
                )
            );

            $assertSame(
                'document_reference',
                'document notes preserved',
                'Documento principale.',
                data_get(
                    $documentRemovedEvent,
                    'details.notes'
                )
            );

            $photoAddedEvent =
                collect(
                    $timeline['events']
                )->firstWhere(
                    'type',
                    ProductCaseEvent
                        ::TYPE_PHOTO_ADDED
                );

            $photoRemovedEvent =
                collect(
                    $timeline['events']
                )->firstWhere(
                    'type',
                    ProductCaseEvent
                        ::TYPE_PHOTO_REMOVED
                );

            $assertSame(
                'photo_reference',
                'photo reference id',
                (int) $media->id,
                data_get(
                    $photoAddedEvent,
                    'reference.id'
                )
            );

            $assertSame(
                'photo_reference',
                'added photo reflects current removal',
                'removed',
                data_get(
                    $photoAddedEvent,
                    'reference.state'
                )
            );

            $assertSame(
                'photo_reference',
                'removed photo filename preserved',
                'foto-timeline.png',
                data_get(
                    $photoRemovedEvent,
                    'reference.label'
                )
            );

            $assertSame(
                'photo_reference',
                'removed photo file absent',
                false,
                is_file(
                    $mediaPath
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Versioni della bozza
             |--------------------------------------------------------------------------
             */

            $generatedEvent =
                collect(
                    $timeline['events']
                )->firstWhere(
                    'type',
                    ProductCaseEvent
                        ::TYPE_REQUEST_DRAFT_GENERATED
                );

            $manualEvent =
                collect(
                    $timeline['events']
                )->firstWhere(
                    'type',
                    ProductCaseEvent
                        ::TYPE_REQUEST_DRAFT_EDITED
                );

            $assertSame(
                'draft_reference',
                'generated draft superseded',
                'superseded',
                data_get(
                    $generatedEvent,
                    'reference.state'
                )
            );

            $assertSame(
                'draft_reference',
                'generated hash exposed',
                $generatedDraftHash,
                data_get(
                    $generatedEvent,
                    'details.new_sha256'
                )
            );

            $assertSame(
                'draft_reference',
                'manual draft is current',
                'current',
                data_get(
                    $manualEvent,
                    'reference.state'
                )
            );

            $assertSame(
                'draft_reference',
                'manual hash exposed',
                $manualDraftHash,
                data_get(
                    $manualEvent,
                    'details.new_sha256'
                )
            );

            /*
             * Il testo completo della bozza non deve entrare nel read model.
             */
            $timelineJson = json_encode(
                $timeline,
                JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
            );

            if (! is_string($timelineJson)) {
                throw new RuntimeException(
                    'Impossibile serializzare la timeline.'
                );
            }

            $assertSame(
                'privacy',
                'manual draft body not exposed',
                false,
                str_contains(
                    $timelineJson,
                    $manualDraft
                )
            );

            $assertSame(
                'privacy',
                'private physical filename not exposed',
                false,
                str_contains(
                    $timelineJson,
                    $media->file_name
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Determinismo e assenza di scritture
             |--------------------------------------------------------------------------
             */

            $secondTimeline =
                $resolver->resolve(
                    $productCase
                );

            $assertSame(
                'read_only',
                'second resolution is deterministic',
                $timeline,
                $secondTimeline
            );

            $productCase->refresh();

            $assertSame(
                'read_only',
                'case metadata unchanged',
                $caseMetadataBefore,
                $productCase->metadata
            );

            $assertSame(
                'read_only',
                'case updated timestamp unchanged',
                $caseUpdatedAtBefore,
                $productCase
                    ->updated_at
                    ?->toISOString()
            );

            $assertSame(
                'read_only',
                'event count unchanged',
                $eventCountBeforeResolve,
                ProductCaseEvent::query()
                    ->count()
            );

            $assertSame(
                'read_only',
                'case model remains clean',
                false,
                $productCase->isDirty()
            );
        } catch (Throwable $exception) {
            $rows[] = [
                'runtime',
                'timeline resolver workflow completed',
                'FAIL',
            ];

            $failures[] = [
                'scenario' =>
                    'runtime',

                'assertion' =>
                    'timeline resolver workflow completed',

                'expected' =>
                    'no exception',

                'actual' =>
                    $exception::class
                    . ': '
                    . $exception
                        ->getMessage(),
            ];
        } finally {
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
            'Product case timeline resolver checks passed.'
        );

        return self::SUCCESS;
    }
}