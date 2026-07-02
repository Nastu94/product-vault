<?php

namespace App\Livewire\ProductCases;

use App\Models\ProductCase;
use App\Services\ProductCases\ProductCaseReadinessResolver;
use App\Services\ProductCases\ProductCaseTimelineResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class ProductCaseShow extends Component
{
    use AuthorizesRequests;

    /**
     * Pratica visualizzata.
     */
    public ProductCase $productCase;

    /**
     * Snapshot read-only della readiness.
     *
     * @var array<string, mixed>
     */
    public array $readiness = [];

    /**
     * Timeline normalizzata.
     *
     * @var array<string, mixed>
     */
    public array $timeline = [];

    /**
     * Metadata non sensibili delle fotografie private correnti.
     *
     * @var list<array<string, mixed>>
     */
    public array $issuePhotos = [];

    public string $statusLabel =
        'Stato non disponibile';

    public string $statusBadgeClasses =
        'bg-gray-100 text-gray-700 ring-gray-500/20';

    public string $readinessLabel =
        'Completezza non disponibile';

    public string $readinessBadgeClasses =
        'bg-gray-100 text-gray-700 ring-gray-500/20';

    public string $usabilityLabel =
        'Non specificata';

    public string $accidentalDamageLabel =
        'Non specificato';

    public string $requestDraftSourceLabel =
        'Nessuna bozza';

    /**
     * Inizializza il dettaglio autorizzato e read-only.
     */
    public function mount(
        ProductCase $productCase
    ): void {
        $this->authorize(
            'view',
            $productCase
        );

        $this->productCase =
            $productCase->load([
                'product.brand',
                'product.category',
                'product.merchant',
                'product.currency',
                'openedBy',
                'documents.documentType',
                'documents.merchant',
            ]);

        $this->readiness = app(
            ProductCaseReadinessResolver::class
        )->resolve(
            $this->productCase
        );

        $this->timeline = app(
            ProductCaseTimelineResolver::class
        )->resolve(
            $this->productCase
        );

        $this->issuePhotos =
            $this->productCase
                ->getMedia(
                    ProductCase
                        ::MEDIA_COLLECTION_ISSUE_PHOTOS
                )
                ->sortBy([
                    ['created_at', 'asc'],
                    ['id', 'asc'],
                ])
                ->map(
                    fn (Media $media): array => [
                        'id' =>
                            (int) $media->id,

                        /*
                         * Mostriamo soltanto il nome originale leggibile.
                         *
                         * file_name, path, disk e URL privato non vengono
                         * esposti dal componente.
                         */
                        'original_filename' =>
                            $this->photoOriginalFilename(
                                $media
                            ),

                        'name' =>
                            $media->name,

                        'mime_type' =>
                            $media->mime_type,

                        'size' =>
                            (int) $media->size,

                        'uploaded_at' =>
                            $this->photoUploadedAt(
                                $media
                            ),
                    ]
                )
                ->values()
                ->all();

        $this->statusLabel =
            $this->resolveStatusLabel(
                $this->productCase->status
            );

        $this->statusBadgeClasses =
            $this->resolveStatusBadgeClasses(
                $this->productCase->status
            );

        $isReady =
            data_get(
                $this->readiness,
                'is_ready_to_contact'
            ) === true;

        $this->readinessLabel =
            $isReady
                ? 'Dati completi per il contatto'
                : 'Informazioni da completare';

        $this->readinessBadgeClasses =
            $isReady
                ? 'bg-green-50 text-green-700 ring-green-600/20'
                : 'bg-yellow-50 text-yellow-800 ring-yellow-600/20';

        $this->usabilityLabel =
            $this->resolveUsabilityLabel(
                $this->productCase
                    ->usability_status
            );

        $this->accidentalDamageLabel =
            match (
                $this->productCase
                    ->accidental_damage_declared
            ) {
                true =>
                    'Sì',

                false =>
                    'No',

                default =>
                    'Non specificato',
            };

        $this->requestDraftSourceLabel =
            $this->resolveRequestDraftSourceLabel();
    }

    private function resolveStatusLabel(
        ?string $status
    ): string {
        return match ($status) {
            ProductCase::STATUS_DRAFT =>
                'Bozza',

            ProductCase::STATUS_READY_TO_CONTACT =>
                'Pronta per il contatto',

            ProductCase::STATUS_CONTACTED =>
                'Contattato',

            ProductCase::STATUS_RESOLVED =>
                'Risolta',

            ProductCase::STATUS_CLOSED =>
                'Chiusa',

            ProductCase::STATUS_CANCELLED =>
                'Annullata',

            default =>
                'Stato non disponibile',
        };
    }

    private function resolveStatusBadgeClasses(
        ?string $status
    ): string {
        return match ($status) {
            ProductCase::STATUS_DRAFT =>
                'bg-gray-100 text-gray-700 ring-gray-500/20',

            ProductCase::STATUS_READY_TO_CONTACT =>
                'bg-blue-50 text-blue-700 ring-blue-600/20',

            ProductCase::STATUS_CONTACTED =>
                'bg-indigo-50 text-indigo-700 ring-indigo-600/20',

            ProductCase::STATUS_RESOLVED =>
                'bg-green-50 text-green-700 ring-green-600/20',

            ProductCase::STATUS_CLOSED =>
                'bg-gray-200 text-gray-800 ring-gray-600/20',

            ProductCase::STATUS_CANCELLED =>
                'bg-red-50 text-red-700 ring-red-600/20',

            default =>
                'bg-gray-100 text-gray-700 ring-gray-500/20',
        };
    }

    private function resolveUsabilityLabel(
        ?string $status
    ): string {
        return match ($status) {
            ProductCase::USABILITY_USABLE =>
                'Utilizzabile',

            ProductCase::USABILITY_PARTIALLY_USABLE =>
                'Parzialmente utilizzabile',

            ProductCase::USABILITY_UNUSABLE =>
                'Non utilizzabile',

            ProductCase::USABILITY_UNKNOWN =>
                'Da verificare',

            default =>
                'Non specificata',
        };
    }

    private function resolveRequestDraftSourceLabel(): string
    {
        if (
            ! is_string(
                $this->productCase
                    ->request_draft
            )
            || trim(
                $this->productCase
                    ->request_draft
            ) === ''
        ) {
            return 'Nessuna bozza';
        }

        return match (
            data_get(
                $this->productCase->metadata,
                ProductCase
                    ::REQUEST_DRAFT_CURRENT_METADATA_KEY
                    . '.source'
            )
        ) {
            ProductCase::REQUEST_DRAFT_SOURCE_GENERATED =>
                'Generata automaticamente',

            ProductCase::REQUEST_DRAFT_SOURCE_MANUAL =>
                'Modificata manualmente',

            default =>
                'Provenienza non disponibile',
        };
    }

    private function photoOriginalFilename(
        Media $media
    ): string {
        $originalFilename =
            $media->getCustomProperty(
                'original_filename'
            );

        if (
            is_string($originalFilename)
            && trim($originalFilename) !== ''
        ) {
            return trim(
                $originalFilename
            );
        }

        if (
            is_string($media->name)
            && trim($media->name) !== ''
        ) {
            return trim(
                $media->name
            );
        }

        return 'Fotografia';
    }

    private function photoUploadedAt(
        Media $media
    ): ?string {
        $uploadedAt =
            $media->getCustomProperty(
                'uploaded_at'
            );

        if (
            is_string($uploadedAt)
            && trim($uploadedAt) !== ''
        ) {
            return trim(
                $uploadedAt
            );
        }

        return $media
            ->created_at
            ?->toISOString();
    }

    /**
     * Renderizza il dettaglio senza esporre azioni di modifica.
     */
    public function render(): View
    {
        return view(
            'livewire.product-cases.product-case-show'
        )->layout(
            'layouts.app'
        );
    }
}