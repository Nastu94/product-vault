<?php

namespace App\Livewire\Products;

use App\Models\Product;
use App\Models\ProductCase;
use App\Models\User;
use App\Models\Warranty;
use App\Models\WarrantyType;
use App\Services\ProductCases\ProductCaseCreator;
use App\Services\Products\ProductLifecycleEventRecorder;
use App\Services\Warranties\ManualWarrantyCoverageContextBuilder;
use App\Services\Warranties\WarrantyCoverageContextResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Component;
use RuntimeException;

class ProductShow extends Component
{
    use AuthorizesRequests;

    /**
     * Prodotto mostrato nella pagina dettaglio.
     */
    public Product $product;

    /*
    |--------------------------------------------------------------------------
    | Apertura guidata pratica prodotto
    |--------------------------------------------------------------------------
    */

    public bool $isCreatingProductCase = false;

    public string $productCaseTitle = '';

    public string $productCaseDescription = '';

    public ?string $productCaseOccurredOn = null;

    public string $productCaseUsabilityStatus =
        ProductCase::USABILITY_UNKNOWN;

    /**
     * Valori UI:
     * - null: non specificato;
     * - "0": nessun danno accidentale dichiarato;
     * - "1": danno accidentale dichiarato.
     */
    public ?string $productCaseAccidentalDamageDeclared =
        null;

    public ?string $productCaseAccidentalDamageNotes =
        null;

    /**
     * Stato form modifica garanzia.
     */
    public bool $isEditingWarranty = false;

    /**
     * Stato form creazione garanzia manuale.
     */
    public bool $isCreatingWarranty = false;

    /**
     * Campi editabili della garanzia principale.
     */
    public ?string $warrantyStartsAt = null;

    public ?string $warrantyEndsAt = null;

    public ?string $warrantyDurationMonths = null;

    public ?string $warrantyNotes = null;

    /**
     * Contesto usato per qualificare la copertura.
     */
    public string $warrantyPurchaseUse = 'unknown';

    public string $warrantySellerType = 'unknown';

    public string $warrantyProductCondition = 'unknown';

    public ?string $warrantyCountryCode = null;

    public ?string $warrantyDeliveredAt = null;

    /**
     * Valori ammessi:
     * - null: informazione non specificata;
     * - "1": copertura dichiarata nel documento;
     * - "0": copertura non dichiarata nel documento.
     */
    public ?string $warrantyDeclaredCoverage = null;

    /**
     * Inizializza il componente con route model binding.
     */
    public function mount(Product $product): void
    {
        $this->authorize('view', $product);

        $this->product = $product->load([
            'merchant',
            'currency',
            'identificationStatus',
            'category',
            'brand',
            'createdBy',
            'cases' => fn ($query) => $query
                ->with('openedBy')
                ->orderByDesc('opened_at')
                ->orderByDesc('id'),
            'documents.documentType',
            'documents.merchant',
            'warranties.warrantyType',
            'warranties.sourceDocument.documentType',
            'events.productEventType',
            'events.document',
            'events.createdBy',
        ]);
    }

    /**
     * Mostra il form iniziale per l’apertura della pratica.
     */
    public function startProductCaseCreation(): void
    {
        $this->authorize(
            'create',
            [
                ProductCase::class,
                $this->product,
            ]
        );

        $this->resetValidation();
        $this->resetProductCaseForm();

        $this->isCreatingProductCase = true;
    }

    /**
     * Chiude il form senza creare alcuna pratica.
     */
    public function cancelProductCaseCreation(): void
    {
        $this->resetValidation();
        $this->resetProductCaseForm();
    }

    /**
     * Crea la pratica iniziale in stato draft.
     */
    public function createProductCase(
        ProductCaseCreator $creator
    ): mixed {
        $this->authorize(
            'create',
            [
                ProductCase::class,
                $this->product,
            ]
        );

        $validated = Validator::make(
            [
                'productCaseTitle' =>
                    $this->productCaseTitle,

                'productCaseDescription' =>
                    $this->productCaseDescription,

                'productCaseOccurredOn' =>
                    $this->productCaseOccurredOn,

                'productCaseUsabilityStatus' =>
                    $this->productCaseUsabilityStatus,

                'productCaseAccidentalDamageDeclared' =>
                    $this
                        ->productCaseAccidentalDamageDeclared,

                'productCaseAccidentalDamageNotes' =>
                    $this
                        ->productCaseAccidentalDamageNotes,
            ],
            [
                'productCaseTitle' => [
                    'required',
                    'string',
                    'max:255',
                ],

                'productCaseDescription' => [
                    'required',
                    'string',
                    'max:20000',
                ],

                'productCaseOccurredOn' => [
                    'nullable',
                    'date',
                    'before_or_equal:today',
                ],

                'productCaseUsabilityStatus' => [
                    'required',
                    'string',
                    Rule::in(
                        ProductCase::USABILITY_STATUSES
                    ),
                ],

                'productCaseAccidentalDamageDeclared' => [
                    'nullable',
                    'string',
                    Rule::in([
                        '0',
                        '1',
                    ]),
                ],

                'productCaseAccidentalDamageNotes' => [
                    'nullable',
                    'string',
                    'max:10000',
                ],
            ],
            [
                'productCaseTitle.required' =>
                    'Inserisci un titolo per il problema.',

                'productCaseTitle.max' =>
                    'Il titolo non può superare 255 caratteri.',

                'productCaseDescription.required' =>
                    'Descrivi il problema riscontrato.',

                'productCaseDescription.max' =>
                    'La descrizione è troppo lunga.',

                'productCaseOccurredOn.date' =>
                    'La data del problema non è valida.',

                'productCaseOccurredOn.before_or_equal' =>
                    'La data del problema non può essere futura.',

                'productCaseUsabilityStatus.in' =>
                    'Seleziona uno stato di utilizzabilità valido.',

                'productCaseAccidentalDamageDeclared.in' =>
                    'La dichiarazione sul danno accidentale non è valida.',

                'productCaseAccidentalDamageNotes.max' =>
                    'Le note sul danno accidentale sono troppo lunghe.',
            ]
        )->validate();

        $openedBy = Auth::user();

        if (! $openedBy instanceof User) {
            throw new RuntimeException(
                'Utente autenticato non disponibile.'
            );
        }

        $accidentalDamageDeclared = match (
            $validated[
                'productCaseAccidentalDamageDeclared'
            ] ?? null
        ) {
            '1' => true,
            '0' => false,
            default => null,
        };

        /*
         * Le note vengono conservate soltanto quando l’utente
         * dichiara esplicitamente un possibile danno accidentale.
         */
        $accidentalDamageNotes =
            $accidentalDamageDeclared === true
                ? (
                    $validated[
                        'productCaseAccidentalDamageNotes'
                    ] ?? null
                )
                : null;

        $productCase = $creator->create(
            product:
                $this->product,

            openedBy:
                $openedBy,

            attributes: [
                'title' =>
                    $validated[
                        'productCaseTitle'
                    ],

                'description' =>
                    $validated[
                        'productCaseDescription'
                    ],

                'occurred_on' =>
                    $validated[
                        'productCaseOccurredOn'
                    ] ?? null,

                'usability_status' =>
                    $validated[
                        'productCaseUsabilityStatus'
                    ],

                'accidental_damage_declared' =>
                    $accidentalDamageDeclared,

                'accidental_damage_notes' =>
                    $accidentalDamageNotes,
            ],
        );

        $this->resetProductCaseForm();

        return redirect()->route(
            'product-cases.show',
            [
                'productCase' =>
                    $productCase,
            ]
        );
    }

    /**
     * Ripristina lo stato iniziale del form pratica.
     */
    private function resetProductCaseForm(): void
    {
        $this->isCreatingProductCase = false;
        $this->productCaseTitle = '';
        $this->productCaseDescription = '';
        $this->productCaseOccurredOn = null;

        $this->productCaseUsabilityStatus =
            ProductCase::USABILITY_UNKNOWN;

        $this->productCaseAccidentalDamageDeclared =
            null;

        $this->productCaseAccidentalDamageNotes =
            null;
    }

    /**
     * Riepilogo read-only delle pratiche associate al prodotto.
     *
     * @return list<array<string, mixed>>
     */
    public function getProductCaseSummariesProperty(): array
    {
        return $this->product
            ->cases
            ->filter(
                fn (ProductCase $productCase): bool =>
                    (int) $productCase->team_id
                        === (int) $this->product->team_id
            )
            ->map(
                fn (ProductCase $productCase): array => [
                    'id' =>
                        (int) $productCase->id,

                    'title' =>
                        $productCase->title,

                    'status' =>
                        $productCase->status,

                    'status_label' =>
                        $this->productCaseStatusLabel(
                            $productCase->status
                        ),

                    'status_badge_classes' =>
                        $this->productCaseStatusBadgeClasses(
                            $productCase->status
                        ),

                    'opened_at' =>
                        $productCase
                            ->opened_at
                            ?->toISOString(),

                    'opened_at_label' =>
                        $productCase
                            ->opened_at
                            ?->format(
                                'd/m/Y H:i'
                            )
                        ?? '—',

                    'occurred_on' =>
                        $productCase
                            ->occurred_on
                            ?->toDateString(),

                    'occurred_on_label' =>
                        $productCase
                            ->occurred_on
                            ?->format(
                                'd/m/Y'
                            )
                        ?? '—',

                    'opened_by_user_id' =>
                        $productCase
                            ->opened_by_user_id !== null
                                ? (int) $productCase
                                    ->opened_by_user_id
                                : null,

                    'opened_by_name' =>
                        $productCase
                            ->openedBy
                            ?->name
                        ?? 'Utente non disponibile',
                ]
            )
            ->values()
            ->all();
    }

    private function productCaseStatusLabel(
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

    private function productCaseStatusBadgeClasses(
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

    /**
     * Etichetta leggibile dello stato di identificazione.
     */
    public function getIdentificationStatusLabelProperty(): string
    {
        return match ($this->product->identificationStatus?->code) {
            'unknown' => 'Sconosciuto',
            'partial' => 'Parziale',
            'probable' => 'Probabile',
            'user_confirmed' => 'Confermato dall’utente',
            'merchant_verified' => 'Verificato dal venditore',
            default => $this->product->identificationStatus?->name ?? '—',
        };
    }

    /**
     * Classi CSS del badge affidabilità.
     */
    public function getReliabilityBadgeClassesProperty(): string
    {
        $score = $this->product->reliability_score;

        if ($score === null) {
            return 'bg-gray-100 text-gray-700 ring-gray-500/20';
        }

        if ($score >= 80) {
            return 'bg-green-50 text-green-700 ring-green-600/20';
        }

        if ($score >= 50) {
            return 'bg-yellow-50 text-yellow-800 ring-yellow-600/20';
        }

        return 'bg-red-50 text-red-700 ring-red-600/20';
    }

    /**
     * Garanzia principale da mostrare nella scheda prodotto.
     */
    public function getPrimaryWarrantyProperty(): ?\App\Models\Warranty
    {
        return $this->product->warranties
            ->sortByDesc(fn ($warranty) => $warranty->warrantyType?->code === 'legal' ? 1 : 0)
            ->sortBy('ends_at')
            ->first();
    }

    /**
     * Contesto normalizzato della copertura principale.
     *
     * Stato della copertura e stato temporale restano concetti
     * distinti e provengono entrambi dal resolver centralizzato.
     *
     * @return array<string, mixed>|null
     */
    public function getWarrantyCoverageContextProperty(): ?array
    {
        $warranty = $this->primaryWarranty;

        if (! $warranty) {
            return null;
        }

        return app(
            WarrantyCoverageContextResolver::class
        )->resolve($warranty);
    }

    /**
     * Etichetta dello stato temporale.
     *
     * Il nome legacy della proprietà viene mantenuto per non rompere
     * la vista attuale durante la transizione.
     */
    public function getWarrantyStatusLabelProperty(): string
    {
        return (string) data_get(
            $this->warrantyCoverageContext,
            'temporal_status.label',
            'Non calcolabile'
        );
    }

    /**
     * Classi del badge dello stato temporale.
     */
    public function getWarrantyStatusBadgeClassesProperty(): string
    {
        return match (
            data_get(
                $this->warrantyCoverageContext,
                'temporal_status.code'
            )
        ) {
            'active' =>
                'bg-green-50 text-green-700 ring-green-600/20',

            'expiring' =>
                'bg-yellow-50 text-yellow-800 ring-yellow-600/20',

            'not_started' =>
                'bg-blue-50 text-blue-700 ring-blue-600/20',

            'expired' =>
                'bg-red-50 text-red-700 ring-red-600/20',

            default =>
                'bg-gray-100 text-gray-700 ring-gray-500/20',
        };
    }

    /**
     * Etichetta dello stato della copertura.
     */
    public function getWarrantyCoverageStateLabelProperty(): string
    {
        return (string) data_get(
            $this->warrantyCoverageContext,
            'coverage_state.label',
            'Copertura non determinata'
        );
    }

    /**
     * Classi del badge dello stato della copertura.
     *
     * I colori non rappresentano lo stato temporale e non devono
     * comunicare implicitamente che la copertura sia legalmente valida.
     */
    public function getWarrantyCoverageStateBadgeClassesProperty(): string
    {
        return match (
            data_get(
                $this->warrantyCoverageContext,
                'coverage_state.code'
            )
        ) {
            'estimated' =>
                'bg-yellow-50 text-yellow-800 ring-yellow-600/20',

            'declared' =>
                'bg-blue-50 text-blue-700 ring-blue-600/20',

            'user_confirmed' =>
                'bg-indigo-50 text-indigo-700 ring-indigo-600/20',

            'verified' =>
                'bg-green-50 text-green-700 ring-green-600/20',

            'cancelled' =>
                'bg-red-50 text-red-700 ring-red-600/20',

            default =>
                'bg-gray-100 text-gray-700 ring-gray-500/20',
        };
    }

    /**
     * Informazioni mancanti per qualificare meglio la copertura.
     *
     * @return list<array<string, mixed>>
     */
    public function getWarrantyMissingInformationProperty(): array
    {
        $missingInformation = data_get(
            $this->warrantyCoverageContext,
            'missing_information',
            []
        );

        return is_array($missingInformation)
            ? array_values($missingInformation)
            : [];
    }

    /**
     * Etichetta leggibile dell’uso dell’acquisto.
     */
    public function getWarrantyPurchaseUseLabelProperty(): string
    {
        return match (
            data_get(
                $this->warrantyCoverageContext,
                'context.purchase_use'
            )
        ) {
            'personal' => 'Uso personale',
            'business' => 'Uso professionale o aziendale',
            default => 'Non specificato',
        };
    }

    /**
     * Etichetta leggibile del tipo di venditore.
     */
    public function getWarrantySellerTypeLabelProperty(): string
    {
        return match (
            data_get(
                $this->warrantyCoverageContext,
                'context.seller_type'
            )
        ) {
            'professional' => 'Venditore professionale',
            'private' => 'Venditore privato',
            default => 'Non specificato',
        };
    }

    /**
     * Etichetta leggibile della condizione del prodotto.
     */
    public function getWarrantyProductConditionLabelProperty(): string
    {
        return match (
            data_get(
                $this->warrantyCoverageContext,
                'context.product_condition'
            )
        ) {
            'new' => 'Nuovo',
            'used' => 'Usato',
            'refurbished' => 'Ricondizionato',
            default => 'Non specificata',
        };
    }

    /**
     * Etichetta della copertura dichiarata nel documento.
     */
    public function getWarrantyDeclaredCoverageLabelProperty(): string
    {
        return match (
            data_get(
                $this->warrantyCoverageContext,
                'context.declared_coverage'
            )
        ) {
            true => 'Indicata nel documento',
            false => 'Non indicata nel documento',
            default => 'Non specificata',
        };
    }

    /**
     * Giorni residui alla scadenza della garanzia.
     */
    public function getWarrantyRemainingDaysProperty(): ?int
    {
        $warranty = $this->primaryWarranty;

        if (! $warranty || ! $warranty->ends_at) {
            return null;
        }

        return now()->startOfDay()->diffInDays($warranty->ends_at, false);
    }

    /**
     * Avvia la modifica manuale della garanzia principale.
     */
    public function editWarranty(): void
    {
        $this->authorize('update', $this->product);

        $warranty = $this->primaryWarranty;

        if (! $warranty) {
            return;
        }

        $this->resetValidation();

        $this->warrantyStartsAt = $warranty->starts_at?->format('Y-m-d');
        $this->warrantyEndsAt = $warranty->ends_at?->format('Y-m-d');
        $this->warrantyDurationMonths = $warranty->duration_months !== null
            ? (string) $warranty->duration_months
            : null;
        $this->warrantyNotes = $warranty->notes;

        $this->fillWarrantyCoverageFormFrom(
            warranty: $warranty,
        );

        $this->isEditingWarranty = true;
    }

    /**
     * Annulla la modifica manuale della garanzia.
     */
    public function cancelWarrantyEdit(): void
    {
        $this->resetValidation();

        $this->isEditingWarranty = false;
        $this->isCreatingWarranty = false;
        $this->warrantyStartsAt = null;
        $this->warrantyEndsAt = null;
        $this->warrantyDurationMonths = null;
        $this->warrantyNotes = null;
        $this->resetWarrantyCoverageForm();
    }

    /**
     * Salva una garanzia:
     * - se isCreatingWarranty = true, crea una nuova garanzia manuale;
     * - altrimenti modifica la garanzia principale esistente.
     */
    public function saveWarranty(): void
    {
        $this->authorize('update', $this->product);

        $this->validate([
            'warrantyStartsAt' => [
                'nullable',
                'date',
            ],

            'warrantyEndsAt' => [
                'nullable',
                'date',
                'after_or_equal:warrantyStartsAt',
            ],

            'warrantyDurationMonths' => [
                'nullable',
                'integer',
                'min:1',
                'max:600',
            ],

            'warrantyNotes' => [
                'nullable',
                'string',
                'max:5000',
            ],

            'warrantyPurchaseUse' => [
                'required',
                'in:personal,business,unknown',
            ],

            'warrantySellerType' => [
                'required',
                'in:professional,private,unknown',
            ],

            'warrantyProductCondition' => [
                'required',
                'in:new,used,refurbished,unknown',
            ],

            'warrantyCountryCode' => [
                'nullable',
                'string',
                'size:2',
                'alpha',
            ],

            'warrantyDeliveredAt' => [
                'nullable',
                'date',
            ],

            'warrantyDeclaredCoverage' => [
                'nullable',
                'in:1,0',
            ],
        ], [
            'warrantyEndsAt.after_or_equal' =>
                'La data di scadenza deve essere successiva o uguale alla data di inizio.',

            'warrantyDurationMonths.integer' =>
                'La durata deve essere un numero intero di mesi.',

            'warrantyDurationMonths.min' =>
                'La durata deve essere almeno di 1 mese.',

            'warrantyDurationMonths.max' =>
                'La durata non può superare 600 mesi.',

            'warrantyCountryCode.size' =>
                'Il paese deve essere indicato con un codice di 2 lettere.',

            'warrantyCountryCode.alpha' =>
                'Il codice paese può contenere soltanto lettere.',

            'warrantyDeliveredAt.date' =>
                'La data di consegna non è valida.',

            'warrantyDeclaredCoverage.in' =>
                'Il valore della copertura dichiarata non è valido.',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Creazione manuale garanzia
        |--------------------------------------------------------------------------
        |
        | Questo ramo deve stare prima della modifica, perché quando stiamo creando
        | una garanzia il prodotto non ha ancora una primaryWarranty.
        */
        if ($this->isCreatingWarranty) {
            if ($this->primaryWarranty) {
                $this->addError('warranty', 'Questo prodotto ha già una garanzia principale.');

                return;
            }

            $warrantyType = WarrantyType::query()
                ->where('code', 'legal')
                ->where('is_active', true)
                ->first();

            if (! $warrantyType) {
                $this->addError('warranty', 'Tipo garanzia legale non disponibile.');

                return;
            }

            $sourceDocument = $this->product->documents()
                ->orderByPivot('created_at')
                ->first();

            $manualTimestamp = now()->toISOString();

            $createdMetadata = [
                'creator' => 'manual_warranty_creation_v1',
                'created_from' => 'product_show',
                'created_at' => $manualTimestamp,
                'created_by_user_id' => auth()->id(),
            ];

            $createdMetadata['coverage_context'] = app(
                ManualWarrantyCoverageContextBuilder::class
            )->build(
                product: $this->product,
                metadata: $createdMetadata,
                userId: (int) auth()->id(),
                confirmedAt: $manualTimestamp,
                input: $this->warrantyCoverageInput(),
            );

            $createdWarranty = Warranty::query()->create([
                'product_id' => $this->product->id,
                'warranty_type_id' => $warrantyType->id,
                'source_document_id' => $sourceDocument?->id,
                'starts_at' => $this->warrantyStartsAt ?: null,
                'ends_at' => $this->warrantyEndsAt ?: null,
                'duration_months' => filled($this->warrantyDurationMonths)
                    ? (int) $this->warrantyDurationMonths
                    : null,
                'source' => 'manual',
                'confidence_score' => 90,
                'notes' => $this->warrantyNotes ?: null,
                'metadata' => $createdMetadata,
            ]);

            app(ProductLifecycleEventRecorder::class)->recordManualWarrantyCreated(
                warranty: $createdWarranty,
                userId: auth()->id(),
            );

            $this->refreshProduct();

            $this->cancelWarrantyEdit();

            session()->flash('status', 'Garanzia creata manualmente.');

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Modifica manuale garanzia esistente
        |--------------------------------------------------------------------------
        */
        $warranty = $this->primaryWarranty;

        if (! $warranty) {
            $this->addError('warranty', 'Nessuna garanzia da modificare.');
            return;
        }

        $previousValues = [
            'starts_at' => $warranty->starts_at?->format('Y-m-d'),
            'ends_at' => $warranty->ends_at?->format('Y-m-d'),
            'duration_months' => $warranty->duration_months,
            'source' => $warranty->source,
            'confidence_score' => $warranty->confidence_score,
            'notes' => $warranty->notes,
        ];

        $metadata = is_array($warranty->metadata)
            ? $warranty->metadata
            : [];

        $previousCoverageContext = data_get(
            $metadata,
            'coverage_context'
        );

        $manualTimestamp = now()->toISOString();

        $metadata['manual_override'] = [
            'applied' => true,
            'previous_values' => $previousValues,
            'previous_coverage_context' =>
                $previousCoverageContext,
            'updated_at' => $manualTimestamp,
            'updated_by_user_id' => auth()->id(),
        ];

        $metadata['coverage_context'] = app(
            ManualWarrantyCoverageContextBuilder::class
        )->build(
            product: $this->product,
            metadata: $metadata,
            userId: (int) auth()->id(),
            confirmedAt: $manualTimestamp,
            input: $this->warrantyCoverageInput(),
        );

        $warranty->update([
            'starts_at' => $this->warrantyStartsAt ?: null,
            'ends_at' => $this->warrantyEndsAt ?: null,
            'duration_months' => filled($this->warrantyDurationMonths)
                ? (int) $this->warrantyDurationMonths
                : null,
            'source' => 'manual',
            'confidence_score' => 90,
            'notes' => $this->warrantyNotes ?: null,
            'metadata' => $metadata,
        ]);

        $warranty->refresh();

        app(ProductLifecycleEventRecorder::class)->recordManualWarrantyUpdated(
            warranty: $warranty,
            previousValues: $previousValues,
            userId: auth()->id(),
        );

        $this->refreshProduct();

        $this->cancelWarrantyEdit();

        session()->flash('status', 'Garanzia aggiornata manualmente.');
    }

    /**
     * Avvia la creazione manuale di una garanzia.
     */
    public function createWarranty(): void
    {
        $this->authorize('update', $this->product);

        if ($this->primaryWarranty) {
            $this->addError('warranty', 'Questo prodotto ha già una garanzia principale.');

            return;
        }

        $this->resetValidation();

        $this->warrantyStartsAt = $this->product->purchase_date?->format('Y-m-d');
        $this->warrantyDurationMonths = '24';

        $this->warrantyEndsAt = $this->product->purchase_date
            ? $this->product->purchase_date->copy()->addMonthsNoOverflow(24)->format('Y-m-d')
            : null;

        $this->warrantyNotes = null;
        $this->resetWarrantyCoverageForm();

        $this->isCreatingWarranty = true;
        $this->isEditingWarranty = false;
    }

    /**
     * Carica nel form il contesto normalizzato della garanzia.
     */
    private function fillWarrantyCoverageFormFrom(
        Warranty $warranty
    ): void {
        $resolvedContext = app(
            WarrantyCoverageContextResolver::class
        )->resolve($warranty);

        $this->warrantyPurchaseUse = (string) data_get(
            $resolvedContext,
            'context.purchase_use',
            'unknown'
        );

        $this->warrantySellerType = (string) data_get(
            $resolvedContext,
            'context.seller_type',
            'unknown'
        );

        $this->warrantyProductCondition = (string) data_get(
            $resolvedContext,
            'context.product_condition',
            'unknown'
        );

        $countryCode = data_get(
            $resolvedContext,
            'context.country_code'
        );

        $this->warrantyCountryCode =
            is_string($countryCode)
                ? $countryCode
                : null;

        $deliveryDate = data_get(
            $resolvedContext,
            'context.delivery_date'
        );

        $this->warrantyDeliveredAt =
            is_string($deliveryDate)
                ? $deliveryDate
                : null;

        $declaredCoverage = data_get(
            $resolvedContext,
            'context.declared_coverage'
        );

        $this->warrantyDeclaredCoverage =
            is_bool($declaredCoverage)
                ? ($declaredCoverage ? '1' : '0')
                : null;
    }

    /**
     * Ripristina i campi contestuali del form.
     */
    private function resetWarrantyCoverageForm(): void
    {
        $this->warrantyPurchaseUse = 'unknown';
        $this->warrantySellerType = 'unknown';
        $this->warrantyProductCondition = 'unknown';
        $this->warrantyCountryCode = null;
        $this->warrantyDeliveredAt = null;
        $this->warrantyDeclaredCoverage = null;
    }

    /**
     * Restituisce l’input contestuale nel formato atteso dal builder.
     *
     * @return array<string, mixed>
     */
    private function warrantyCoverageInput(): array
    {
        return [
            'purchase_use' =>
                $this->warrantyPurchaseUse,

            'seller_type' =>
                $this->warrantySellerType,

            'product_condition' =>
                $this->warrantyProductCondition,

            'country_code' =>
                $this->warrantyCountryCode,

            'delivered_at' =>
                $this->warrantyDeliveredAt,

            'declared_coverage' =>
                $this->warrantyDeclaredCoverage,
        ];
    }

    /**
     * Ricarica il prodotto con tutte le relazioni usate nella pagina dettaglio.
     */
    private function refreshProduct(): void
    {
        $this->product = $this->product->fresh([
            'merchant',
            'currency',
            'identificationStatus',
            'category',
            'brand',
            'createdBy',
            'cases' => fn ($query) => $query
                ->with('openedBy')
                ->orderByDesc('opened_at')
                ->orderByDesc('id'),
            'documents.documentType',
            'documents.merchant',
            'warranties.warrantyType',
            'warranties.sourceDocument.documentType',
            'events.productEventType',
            'events.document',
            'events.createdBy',
        ]);
    }

    /**
     * Renderizza il dettaglio prodotto.
     */
    public function render(): View
    {
        return view('livewire.products.product-show')
            ->layout('layouts.app');
    }
}
