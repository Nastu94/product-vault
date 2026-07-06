<?php

namespace App\Services\ProductCases;

use App\Models\ProductCase;
use App\Models\Warranty;
use App\Services\Warranties\WarrantyCoverageContextResolver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RuntimeException;

final class ProductCaseReadinessResolver
{
    public const VERSION =
        'product_case_readiness_v1';

    public function __construct(
        private readonly WarrantyCoverageContextResolver
            $warrantyCoverageResolver
    ) {
    }

    /**
     * Costruisce una fotografia derivata della completezza della pratica.
     *
     * Il resolver non modifica la pratica, la garanzia o i relativi metadata.
     *
     * @return array<string, mixed>
     */
    public function resolve(
        ProductCase $productCase,
        ?CarbonInterface $referenceDate = null
    ): array {
        if (! $productCase->exists) {
            throw new RuntimeException(
                'La pratica deve essere persistita prima di calcolarne la readiness.'
            );
        }

        $productCase->loadMissing([
            'product.documents',
            'product.warranties.warrantyType',
            'documents',
        ]);

        $product = $productCase->product;

        if ($product === null) {
            throw new RuntimeException(
                'Il prodotto della pratica non è disponibile.'
            );
        }

        if (
            $productCase->team_id === null
            || $product->team_id === null
            || (int) $productCase->team_id
                !== (int) $product->team_id
        ) {
            throw new RuntimeException(
                'La pratica e il prodotto non appartengono allo stesso team.'
            );
        }

        $today = $referenceDate
            ? CarbonImmutable::instance(
                $referenceDate
            )->startOfDay()
            : CarbonImmutable::today();

        $items = [];

        /*
         |--------------------------------------------------------------------------
         | Informazioni sul problema
         |--------------------------------------------------------------------------
         */

        if (
            ! is_string($productCase->title)
            || trim($productCase->title) === ''
        ) {
            $items[] = $this->item(
                code: 'title',
                label: 'Titolo del problema',
                section: 'issue',
                isBlocking: true,
                source: 'product_case',
            );
        }

        if (
            ! is_string($productCase->description)
            || trim($productCase->description) === ''
        ) {
            $items[] = $this->item(
                code: 'description',
                label: 'Descrizione del problema',
                section: 'issue',
                isBlocking: true,
                source: 'product_case',
            );
        }

        if ($productCase->occurred_on === null) {
            $items[] = $this->item(
                code: 'occurred_on',
                label: 'Data in cui si è verificato il problema',
                section: 'issue',
                isBlocking: true,
                source: 'product_case',
            );
        } else {
            $occurredOn = CarbonImmutable::instance(
                $productCase->occurred_on
            )->startOfDay();

            if ($occurredOn->gt($today)) {
                $items[] = $this->item(
                    code: 'occurred_on_future',
                    label:
                        'La data del problema non può essere futura',
                    section: 'issue',
                    isBlocking: true,
                    source: 'product_case',
                );
            }
        }

        if (
            ! in_array(
                $productCase->usability_status,
                ProductCase::USABILITY_STATUSES,
                true
            )
            || $productCase->usability_status
                === ProductCase::USABILITY_UNKNOWN
        ) {
            $items[] = $this->item(
                code: 'usability_status',
                label: 'Stato di utilizzabilità del prodotto',
                section: 'issue',
                isBlocking: true,
                source: 'product_case',
            );
        }

        if (
            $productCase->accidental_damage_declared
                === null
        ) {
            $items[] = $this->item(
                code: 'accidental_damage_declared',
                label:
                    'Dichiarazione sulla presenza di danno accidentale',
                section: 'issue',
                isBlocking: true,
                source: 'product_case',
            );
        } elseif (
            $productCase->accidental_damage_declared
                === true
            && (
                ! is_string(
                    $productCase->accidental_damage_notes
                )
                || trim(
                    $productCase->accidental_damage_notes
                ) === ''
            )
        ) {
            $items[] = $this->item(
                code: 'accidental_damage_notes',
                label:
                    'Descrizione del possibile danno accidentale',
                section: 'issue',
                isBlocking: true,
                source: 'product_case',
            );
        }

        /*
         |--------------------------------------------------------------------------
         | Documenti selezionati
         |--------------------------------------------------------------------------
         */

        $linkedDocumentIds = $product->documents
            ->pluck('id')
            ->map(
                fn (mixed $id): int => (int) $id
            )
            ->all();

        $selectedDocumentCount =
            $productCase->documents->count();

        $validSelectedDocumentCount =
            $productCase->documents
                ->filter(
                    fn ($document): bool =>
                        (int) $document->team_id
                            === (int) $productCase->team_id
                        && in_array(
                            (int) $document->id,
                            $linkedDocumentIds,
                            true
                        )
                )
                ->count();

        $invalidSelectedDocumentCount =
            $selectedDocumentCount
            - $validSelectedDocumentCount;

        if ($invalidSelectedDocumentCount > 0) {
            $items[] = $this->item(
                code: 'invalid_selected_documents',
                label:
                    'Uno o più documenti selezionati non appartengono correttamente al prodotto',
                section: 'evidence',
                isBlocking: true,
                source: 'product_case_documents',
            );
        }

        if ($validSelectedDocumentCount === 0) {
            $items[] = $this->item(
                code: 'selected_document',
                label:
                    'Almeno un documento collegato al prodotto',
                section: 'evidence',
                isBlocking: true,
                source: 'product_case_documents',
            );
        }

        /*
         |--------------------------------------------------------------------------
         | Contesto garanzia
         |--------------------------------------------------------------------------
         */

        $warrantySelection =
            $this->selectWarrantyContext(
                warranties:
                    $product->warranties->all(),
                referenceDate: $today,
            );

        if ($warrantySelection === null) {
            $items[] = $this->item(
                code: 'warranty_context',
                label:
                    'Contesto garanzia del prodotto',
                section: 'warranty',
                isBlocking: true,
                source: 'product_warranties',
            );

            $warrantySummary = [
                'available' => false,
                'warranty_count' => 0,
                'selected_warranty_id' => null,
                'coverage_state' => null,
                'temporal_status' => null,
                'is_estimate' => null,
            ];
        } else {
            $warranty = $warrantySelection[
                'warranty'
            ];

            $coverageContext =
                $warrantySelection['context'];

            foreach (
                data_get(
                    $coverageContext,
                    'missing_information',
                    []
                ) as $missingItem
            ) {
                if (! is_array($missingItem)) {
                    continue;
                }

                $code = data_get(
                    $missingItem,
                    'code'
                );

                $label = data_get(
                    $missingItem,
                    'label'
                );

                if (
                    ! is_string($code)
                    || ! is_string($label)
                ) {
                    continue;
                }

                $items[] = $this->item(
                    code: 'warranty.' . $code,
                    label: $label,
                    section: 'warranty',
                    isBlocking:
                        data_get(
                            $missingItem,
                            'is_blocking'
                        ) === true,
                    source:
                        WarrantyCoverageContextResolver::VERSION,
                );
            }

            $coverageState = data_get(
                $coverageContext,
                'coverage_state.code'
            );

            $temporalStatus = data_get(
                $coverageContext,
                'temporal_status.code'
            );

            if ($coverageState === 'estimated') {
                $items[] = $this->item(
                    code:
                        'warranty.coverage_estimated',
                    label:
                        'La copertura selezionata è una stima da verificare',
                    section: 'warranty',
                    isBlocking: false,
                    source:
                        WarrantyCoverageContextResolver::VERSION,
                );
            } elseif ($coverageState === 'unknown') {
                $items[] = $this->item(
                    code:
                        'warranty.coverage_unknown',
                    label:
                        'Lo stato della copertura deve essere verificato',
                    section: 'warranty',
                    isBlocking: false,
                    source:
                        WarrantyCoverageContextResolver::VERSION,
                );
            } elseif ($coverageState === 'cancelled') {
                $items[] = $this->item(
                    code:
                        'warranty.coverage_cancelled',
                    label:
                        'La copertura selezionata risulta annullata',
                    section: 'warranty',
                    isBlocking: false,
                    source:
                        WarrantyCoverageContextResolver::VERSION,
                );
            }

            if ($temporalStatus === 'expired') {
                $items[] = $this->item(
                    code: 'warranty.period_expired',
                    label:
                        'Il periodo indicato della copertura risulta terminato',
                    section: 'warranty',
                    isBlocking: false,
                    source:
                        WarrantyCoverageContextResolver::VERSION,
                );
            } elseif (
                $temporalStatus === 'not_started'
            ) {
                $items[] = $this->item(
                    code:
                        'warranty.period_not_started',
                    label:
                        'Il periodo indicato della copertura non è ancora iniziato',
                    section: 'warranty',
                    isBlocking: false,
                    source:
                        WarrantyCoverageContextResolver::VERSION,
                );
            }

            $warrantySummary = [
                'available' => true,
                'warranty_count' =>
                    $product->warranties->count(),
                'selected_warranty_id' =>
                    (int) $warranty->id,
                'coverage_state' =>
                    $coverageState,
                'temporal_status' =>
                    $temporalStatus,
                'is_estimate' =>
                    data_get(
                        $coverageContext,
                        'coverage_state.is_estimate'
                    ) === true,
            ];
        }

        $blockingInformation = array_values(
            array_filter(
                $items,
                fn (array $item): bool =>
                    $item['is_blocking'] === true
            )
        );

        $advisoryInformation = array_values(
            array_filter(
                $items,
                fn (array $item): bool =>
                    $item['is_blocking'] === false
            )
        );

        return [
            'version' => self::VERSION,
            'product_case_id' =>
                (int) $productCase->id,

            /*
             * Readiness significa completezza operativa.
             * Non rappresenta una decisione sulla copertura legale.
             */
            'is_ready_to_contact' =>
                $blockingInformation === [],

            'blocking_count' =>
                count($blockingInformation),

            'advisory_count' =>
                count($advisoryInformation),

            'missing_information' => $items,

            'blocking_information' =>
                $blockingInformation,

            'advisory_information' =>
                $advisoryInformation,

            'facts' => [
                'issue' => [
                    'has_title' =>
                        is_string(
                            $productCase->title
                        )
                        && trim(
                            $productCase->title
                        ) !== '',

                    'has_description' =>
                        is_string(
                            $productCase->description
                        )
                        && trim(
                            $productCase->description
                        ) !== '',

                    'occurred_on' =>
                        $productCase->occurred_on
                            ?->toDateString(),

                    'usability_status' =>
                        $productCase
                            ->usability_status,

                    'accidental_damage_declared' =>
                        $productCase
                            ->accidental_damage_declared,
                ],

                'evidence' => [
                    'selected_document_count' =>
                        $selectedDocumentCount,

                    'valid_selected_document_count' =>
                        $validSelectedDocumentCount,

                    'invalid_selected_document_count' =>
                        $invalidSelectedDocumentCount,
                ],

                'warranty' => $warrantySummary,
            ],
        ];
    }

    /**
     * Sceglie in modo deterministico il contesto garanzia più utilizzabile.
     *
     * Priorità:
     * 1. minor numero di informazioni bloccanti;
     * 2. coperture non annullate;
     * 3. posizione temporale più utile;
     * 4. record più recente.
     *
     * @param  array<int, Warranty>  $warranties
     * @return array{
     *     warranty: Warranty,
     *     context: array<string, mixed>
     * }|null
     */
    private function selectWarrantyContext(
        array $warranties,
        CarbonInterface $referenceDate
    ): ?array {
        if ($warranties === []) {
            return null;
        }

        $entries = [];

        foreach ($warranties as $warranty) {
            $context =
                $this->warrantyCoverageResolver
                    ->resolve(
                        warranty: $warranty,
                        referenceDate: $referenceDate,
                    );

            $blockingCount = count(
                array_filter(
                    data_get(
                        $context,
                        'missing_information',
                        []
                    ),
                    fn (mixed $item): bool =>
                        is_array($item)
                        && data_get(
                            $item,
                            'is_blocking'
                        ) === true
                )
            );

            $coverageState = data_get(
                $context,
                'coverage_state.code'
            );

            $temporalStatus = data_get(
                $context,
                'temporal_status.code'
            );

            $entries[] = [
                'warranty' => $warranty,
                'context' => $context,
                'ranking' => [
                    $blockingCount,
                    $coverageState === 'cancelled'
                        ? 1
                        : 0,
                    $this->temporalPriority(
                        is_string($temporalStatus)
                            ? $temporalStatus
                            : 'unknown'
                    ),
                    -1 * (int) $warranty->id,
                ],
            ];
        }

        usort(
            $entries,
            fn (array $left, array $right): int =>
                $left['ranking']
                    <=> $right['ranking']
        );

        return [
            'warranty' =>
                $entries[0]['warranty'],
            'context' =>
                $entries[0]['context'],
        ];
    }

    private function temporalPriority(
        string $temporalStatus
    ): int {
        return match ($temporalStatus) {
            'active' => 0,
            'expiring' => 1,
            'not_started' => 2,
            'unknown' => 3,
            'expired' => 4,
            default => 5,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function item(
        string $code,
        string $label,
        string $section,
        bool $isBlocking,
        string $source
    ): array {
        return [
            'code' => $code,
            'label' => $label,
            'section' => $section,
            'is_blocking' => $isBlocking,
            'source' => $source,
        ];
    }
}