<?php

namespace App\Livewire\ProductCases;

use App\Models\ProductCase;
use App\Models\User;
use App\Services\ProductCases\ProductCaseDetailsUpdater;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;
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

    /*
    |--------------------------------------------------------------------------
    | Modifica controllata dei dati iniziali
    |--------------------------------------------------------------------------
    */

    public bool $isEditingDetails = false;

    public string $detailsTitle = '';

    public string $detailsDescription = '';

    public ?string $detailsOccurredOn = null;

    public string $detailsUsabilityStatus =
        ProductCase::USABILITY_UNKNOWN;

    /**
     * Valori UI:
     * - null: non specificato;
     * - "0": no;
     * - "1": sì.
     */
    public ?string $detailsAccidentalDamageDeclared =
        null;

    public ?string $detailsAccidentalDamageNotes =
        null;

    public ?string $detailsSuccessMessage =
        null;

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
     * Renderizza il dettaglio della pratica.
     */
    public function mount(
        ProductCase $productCase
    ): void {
        $this->authorize(
            'view',
            $productCase
        );

        $this->loadProductCaseState(
            $productCase
        );
    }

    /**
     * Apre il form con uno snapshot aggiornato della pratica.
     */
    public function startDetailsEdit(): void
    {
        $currentCase =
            $this->productCase
                ->fresh();

        if ($currentCase === null) {
            throw new RuntimeException(
                'La pratica non è più disponibile.'
            );
        }

        $this->authorize(
            'update',
            $currentCase
        );

        if (
            $currentCase->status
                !== ProductCase::STATUS_DRAFT
        ) {
            throw new RuntimeException(
                'I dati possono essere modificati soltanto mentre la pratica è in bozza.'
            );
        }

        $this->loadProductCaseState(
            $currentCase
        );

        $this->resetValidation();

        $this->detailsSuccessMessage =
            null;

        $this->detailsTitle =
            $this->productCase->title;

        $this->detailsDescription =
            $this->productCase->description;

        $this->detailsOccurredOn =
            $this->productCase
                ->occurred_on
                ?->toDateString();

        $this->detailsUsabilityStatus =
            $this->productCase
                ->usability_status;

        $this->detailsAccidentalDamageDeclared =
            match (
                $this->productCase
                    ->accidental_damage_declared
            ) {
                true =>
                    '1',

                false =>
                    '0',

                default =>
                    null,
            };

        $this->detailsAccidentalDamageNotes =
            $this->productCase
                ->accidental_damage_notes;

        $this->isEditingDetails =
            true;
    }

    /**
     * Chiude il form senza scritture.
     */
    public function cancelDetailsEdit(): void
    {
        $this->authorize(
            'update',
            $this->productCase
        );

        $this->resetValidation();

        $this->detailsSuccessMessage =
            null;

        $this->resetDetailsForm();
    }

    /**
     * Elimina dalla UI le note nascoste quando il danno non è dichiarato.
     */
    public function updatedDetailsAccidentalDamageDeclared(
        ?string $value
    ): void {
        if ($value !== '1') {
            $this->detailsAccidentalDamageNotes =
                null;
        }
    }

    /**
     * Salva i dati tramite il service di dominio.
     */
    public function saveDetails(
        ProductCaseDetailsUpdater $updater
    ): void {
        $this->authorize(
            'update',
            $this->productCase
        );

        $validated = Validator::make(
            [
                'detailsTitle' =>
                    $this->detailsTitle,

                'detailsDescription' =>
                    $this->detailsDescription,

                'detailsOccurredOn' =>
                    $this->detailsOccurredOn,

                'detailsUsabilityStatus' =>
                    $this->detailsUsabilityStatus,

                'detailsAccidentalDamageDeclared' =>
                    $this
                        ->detailsAccidentalDamageDeclared,

                'detailsAccidentalDamageNotes' =>
                    $this
                        ->detailsAccidentalDamageNotes,
            ],
            [
                'detailsTitle' => [
                    'required',
                    'string',
                    'max:255',
                ],

                'detailsDescription' => [
                    'required',
                    'string',
                    'max:20000',
                ],

                'detailsOccurredOn' => [
                    'nullable',
                    'date',
                    'before_or_equal:today',
                ],

                'detailsUsabilityStatus' => [
                    'required',
                    'string',
                    Rule::in(
                        ProductCase::USABILITY_STATUSES
                    ),
                ],

                'detailsAccidentalDamageDeclared' => [
                    'nullable',
                    'string',
                    Rule::in([
                        '0',
                        '1',
                    ]),
                ],

                'detailsAccidentalDamageNotes' => [
                    'nullable',
                    'string',
                    'max:10000',
                ],
            ],
            [
                'detailsTitle.required' =>
                    'Inserisci un titolo per il problema.',

                'detailsTitle.max' =>
                    'Il titolo non può superare 255 caratteri.',

                'detailsDescription.required' =>
                    'Descrivi il problema riscontrato.',

                'detailsDescription.max' =>
                    'La descrizione è troppo lunga.',

                'detailsOccurredOn.date' =>
                    'La data del problema non è valida.',

                'detailsOccurredOn.before_or_equal' =>
                    'La data del problema non può essere futura.',

                'detailsUsabilityStatus.in' =>
                    'Seleziona uno stato di utilizzabilità valido.',

                'detailsAccidentalDamageDeclared.in' =>
                    'La dichiarazione sul danno accidentale non è valida.',

                'detailsAccidentalDamageNotes.max' =>
                    'Le note sul danno accidentale sono troppo lunghe.',
            ]
        )->validate();

        $updatedBy =
            Auth::user();

        if (! $updatedBy instanceof User) {
            throw new RuntimeException(
                'Utente autenticato non disponibile.'
            );
        }

        $damageDeclared = match (
            $validated[
                'detailsAccidentalDamageDeclared'
            ] ?? null
        ) {
            '1' =>
                true,

            '0' =>
                false,

            default =>
                null,
        };

        $updatedCase =
            $updater->update(
                productCase:
                    $this->productCase,

                updatedBy:
                    $updatedBy,

                attributes: [
                    'title' =>
                        $validated[
                            'detailsTitle'
                        ],

                    'description' =>
                        $validated[
                            'detailsDescription'
                        ],

                    'occurred_on' =>
                        $validated[
                            'detailsOccurredOn'
                        ] ?? null,

                    'usability_status' =>
                        $validated[
                            'detailsUsabilityStatus'
                        ],

                    'accidental_damage_declared' =>
                        $damageDeclared,

                    'accidental_damage_notes' =>
                        $damageDeclared === true
                            ? (
                                $validated[
                                    'detailsAccidentalDamageNotes'
                                ] ?? null
                            )
                            : null,
                ],
            );

        $this->loadProductCaseState(
            $updatedCase
        );

        $this->resetValidation();
        $this->resetDetailsForm();

        $this->detailsSuccessMessage =
            'Dati della pratica aggiornati correttamente.';
    }

    /**
     * Ripristina lo stato interno del form.
     */
    private function resetDetailsForm(): void
    {
        $this->isEditingDetails =
            false;

        $this->detailsTitle =
            '';

        $this->detailsDescription =
            '';

        $this->detailsOccurredOn =
            null;

        $this->detailsUsabilityStatus =
            ProductCase::USABILITY_UNKNOWN;

        $this->detailsAccidentalDamageDeclared =
            null;

        $this->detailsAccidentalDamageNotes =
            null;
    }

    /**
     * Ricarica modello, relazioni e snapshot derivati della pagina.
     */
    private function loadProductCaseState(
        ProductCase $productCase
    ): void {
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