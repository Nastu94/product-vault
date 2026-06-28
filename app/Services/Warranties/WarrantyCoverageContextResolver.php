<?php

namespace App\Services\Warranties;

use App\Models\Warranty;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Throwable;

final class WarrantyCoverageContextResolver
{
    public const VERSION = 'warranty_coverage_context_v1';

    /**
     * Stati che descrivono l'affidabilità e la provenienza concettuale
     * della copertura, non la sua posizione temporale.
     *
     * @var array<string, string>
     */
    private const COVERAGE_STATE_LABELS = [
        'estimated' => 'Copertura stimata',
        'declared' => 'Copertura dichiarata',
        'user_confirmed' => 'Copertura confermata dall’utente',
        'verified' => 'Copertura verificata',
        'cancelled' => 'Copertura annullata',
        'unknown' => 'Copertura da verificare',
    ];

    /**
     * Stati derivati esclusivamente dalle date.
     *
     * @var array<string, string>
     */
    private const TEMPORAL_STATUS_LABELS = [
        'not_started' => 'Non ancora iniziata',
        'active' => 'Nel periodo indicato',
        'expiring' => 'In scadenza',
        'expired' => 'Periodo terminato',
        'unknown' => 'Periodo non calcolabile',
    ];

    /**
     * Valori ammessi per il contesto dell'acquisto.
     *
     * @var array<string, list<string>>
     */
    private const CONTEXT_VALUES = [
        'purchase_use' => [
            'personal',
            'business',
            'unknown',
        ],
        'seller_type' => [
            'professional',
            'private',
            'unknown',
        ],
        'product_condition' => [
            'new',
            'used',
            'refurbished',
            'unknown',
        ],
    ];

    /**
     * Costruisce una rappresentazione leggibile e stabile del contesto
     * della copertura senza modificare Warranty o i suoi metadata.
     *
     * @return array<string, mixed>
     */
    public function resolve(
        Warranty $warranty,
        ?CarbonInterface $referenceDate = null
    ): array {
        $warranty->loadMissing('warrantyType');

        $metadata = is_array($warranty->metadata)
            ? $warranty->metadata
            : [];

        $storedContext = data_get(
            $metadata,
            'coverage_context',
            []
        );

        $storedContext = is_array($storedContext)
            ? $storedContext
            : [];

        $coverageStateResult = $this->resolveCoverageState(
            warranty: $warranty,
            storedContext: $storedContext,
        );

        $coverageState = $coverageStateResult['code'];

        $today = $referenceDate
            ? CarbonImmutable::instance($referenceDate)->startOfDay()
            : CarbonImmutable::today();

        $temporalStatus = $this->resolveTemporalStatus(
            warranty: $warranty,
            referenceDate: $today,
        );

        $purchaseUse = $this->normalizeContextValue(
            field: 'purchase_use',
            value: data_get(
                $storedContext,
                'purchase.use'
            ),
        );

        $sellerType = $this->normalizeContextValue(
            field: 'seller_type',
            value: data_get(
                $storedContext,
                'purchase.seller_type'
            ),
        );

        $productCondition = $this->normalizeContextValue(
            field: 'product_condition',
            value: data_get(
                $storedContext,
                'product.condition'
            ),
        );

        $countryCode = $this->resolveCountryCode(
            storedContext: $storedContext,
            metadata: $metadata,
        );

        $deliveryDate = $this->normalizeDate(
            data_get(
                $storedContext,
                'dates.delivered_at'
            )
        );

        $declaredCoverage = $this->normalizeNullableBoolean(
            data_get(
                $storedContext,
                'declared_coverage.present'
            )
        );

        $confirmation = $this->resolveConfirmation(
            coverageState: $coverageState,
            storedContext: $storedContext,
        );

        $basis = $this->resolveBasis(
            warranty: $warranty,
            metadata: $metadata,
            countryCode: $countryCode,
        );

        $context = [
            'purchase_use' => $purchaseUse,
            'seller_type' => $sellerType,
            'product_condition' => $productCondition,
            'country_code' => $countryCode,
            'delivery_date' => $deliveryDate,
            'declared_coverage' => $declaredCoverage,
        ];

        $missingInformation = $this->missingInformation(
            warranty: $warranty,
            context: $context,
            confirmation: $confirmation,
        );

        return [
            'version' => self::VERSION,

            'coverage_state' => [
                'code' => $coverageState,
                'label' => self::COVERAGE_STATE_LABELS[
                    $coverageState
                ],
                'source' => $coverageStateResult['source'],
                'is_estimate' => $coverageState === 'estimated',
            ],

            'temporal_status' => [
                'code' => $temporalStatus,
                'label' => self::TEMPORAL_STATUS_LABELS[
                    $temporalStatus
                ],
                'reference_date' => $today->toDateString(),
            ],

            'coverage_type' => [
                'code' => $warranty->warrantyType?->code
                    ?? 'unknown',
                'label' => $warranty->warrantyType?->name
                    ?? 'Tipo non disponibile',
            ],

            'source' => [
                'code' => $warranty->source ?: 'unknown',
                'label' => $this->sourceLabel(
                    $warranty->source
                ),
            ],

            'period' => [
                'starts_at' => $warranty->starts_at?->toDateString(),
                'ends_at' => $warranty->ends_at?->toDateString(),
                'duration_months' => $warranty->duration_months,
            ],

            'basis' => $basis,

            'context' => $context,

            'confirmation' => $confirmation,

            'missing_information' => $missingInformation,

            'actions' => [
                'can_confirm' => in_array(
                    $coverageState,
                    [
                        'estimated',
                        'declared',
                        'unknown',
                    ],
                    true
                ),
                'can_edit' => $coverageState !== 'cancelled',
            ],

            'stored_context_version' => data_get(
                $storedContext,
                'version'
            ),
        ];
    }

    /**
     * @param array<string, mixed> $storedContext
     *
     * @return array{code: string, source: string}
     */
    private function resolveCoverageState(
        Warranty $warranty,
        array $storedContext
    ): array {
        $storedState = $this->normalizeCoverageState(
            data_get(
                $storedContext,
                'state'
            )
        );

        if ($storedState !== null) {
            return [
                'code' => $storedState,
                'source' => 'metadata',
            ];
        }

        $confirmationApplied = $this->normalizeNullableBoolean(
            data_get(
                $storedContext,
                'confirmation.applied'
            )
        );

        if ($confirmationApplied === true) {
            return [
                'code' => 'user_confirmed',
                'source' => 'metadata_confirmation',
            ];
        }

        $source = strtolower(
            trim((string) $warranty->source)
        );

        $inferredState = match ($source) {
            'calculated' => 'estimated',

            'document_text',
            'merchant',
            'manufacturer' => 'declared',

            'manual',
            'user' => 'user_confirmed',

            default => 'unknown',
        };

        return [
            'code' => $inferredState,
            'source' => 'warranty_source_fallback',
        ];
    }

    /**
     * Restituisce esclusivamente lo stato derivato dalle date.
     */
    private function resolveTemporalStatus(
        Warranty $warranty,
        CarbonImmutable $referenceDate
    ): string {
        if (! $warranty->starts_at || ! $warranty->ends_at) {
            return 'unknown';
        }

        $startsAt = CarbonImmutable::instance(
            $warranty->starts_at
        )->startOfDay();

        $endsAt = CarbonImmutable::instance(
            $warranty->ends_at
        )->startOfDay();

        if ($referenceDate->lt($startsAt)) {
            return 'not_started';
        }

        if ($referenceDate->gt($endsAt)) {
            return 'expired';
        }

        $remainingDays = (int) $referenceDate->diffInDays(
            $endsAt,
            false
        );

        if ($remainingDays <= 30) {
            return 'expiring';
        }

        return 'active';
    }

    /**
     * @param array<string, mixed> $storedContext
     * @param array<string, mixed> $metadata
     */
    private function resolveCountryCode(
        array $storedContext,
        array $metadata
    ): ?string {
        $value = data_get(
            $storedContext,
            'jurisdiction.country_code',
            data_get(
                $metadata,
                'country_code'
            )
        );

        if (! is_string($value)) {
            return null;
        }

        $value = strtoupper(trim($value));

        return $value !== ''
            ? $value
            : null;
    }

    /**
     * @param array<string, mixed> $storedContext
     *
     * @return array<string, mixed>
     */
    private function resolveConfirmation(
        string $coverageState,
        array $storedContext
    ): array {
        $explicitApplied = $this->normalizeNullableBoolean(
            data_get(
                $storedContext,
                'confirmation.applied'
            )
        );

        $isConfirmed = $explicitApplied
            ?? in_array(
                $coverageState,
                [
                    'user_confirmed',
                    'verified',
                ],
                true
            );

        return [
            'is_confirmed' => $isConfirmed,
            'confirmed_at' => $this->normalizeDateTime(
                data_get(
                    $storedContext,
                    'confirmation.confirmed_at'
                )
            ),
            'confirmed_by_user_id' => $this->normalizeNullableInteger(
                data_get(
                    $storedContext,
                    'confirmation.confirmed_by_user_id'
                )
            ),
            'source' => $explicitApplied !== null
                ? 'metadata'
                : 'coverage_state_fallback',
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    private function resolveBasis(
        Warranty $warranty,
        array $metadata,
        ?string $countryCode
    ): array {
        $startsAtSource = data_get(
            $metadata,
            'calculation.starts_at_source'
        );

        $durationSource = data_get(
            $metadata,
            'calculation.duration_months_source'
        );

        $reason = match ($warranty->source) {
            'calculated' => $this->calculatedReason(
                countryCode: $countryCode,
                startsAtSource: is_string($startsAtSource)
                    ? $startsAtSource
                    : null,
            ),

            'manual' => 'Copertura inserita o modificata manualmente dall’utente.',

            'document_text' => 'Copertura ricavata dalle informazioni presenti nel documento.',

            'merchant' => 'Copertura dichiarata dal venditore.',

            'manufacturer' => 'Copertura dichiarata dal produttore.',

            default => 'Origine della copertura non completamente disponibile.',
        };

        return [
            'reason' => $reason,
            'rule_id' => $this->normalizeNullableInteger(
                data_get(
                    $metadata,
                    'rule_id'
                )
            ),
            'rule_type' => data_get(
                $metadata,
                'rule_type'
            ),
            'rule_priority' => $this->normalizeNullableInteger(
                data_get(
                    $metadata,
                    'rule_priority'
                )
            ),
            'source_note' => data_get(
                $metadata,
                'source_note'
            ),
            'country_code' => $countryCode,
            'starts_at_source' => $startsAtSource,
            'duration_months_source' => $durationSource,
        ];
    }

    private function calculatedReason(
        ?string $countryCode,
        ?string $startsAtSource
    ): string {
        $parts = [
            'Copertura calcolata automaticamente',
        ];

        if ($startsAtSource === 'product.purchase_date') {
            $parts[] = 'usando la data di acquisto';
        }

        if ($countryCode !== null) {
            $parts[] = 'e una regola configurata per il paese '.$countryCode;
        } else {
            $parts[] = 'e una regola generale configurata';
        }

        return implode(' ', $parts).'.';
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $confirmation
     *
     * @return list<array<string, mixed>>
     */
    private function missingInformation(
        Warranty $warranty,
        array $context,
        array $confirmation
    ): array {
        $items = [];

        if ($context['purchase_use'] === 'unknown') {
            $items[] = $this->missingItem(
                code: 'purchase_use',
                label: 'Uso personale o aziendale',
            );
        }

        if ($context['seller_type'] === 'unknown') {
            $items[] = $this->missingItem(
                code: 'seller_type',
                label: 'Venditore professionale o privato',
            );
        }

        if ($context['product_condition'] === 'unknown') {
            $items[] = $this->missingItem(
                code: 'product_condition',
                label: 'Prodotto nuovo, usato o ricondizionato',
            );
        }

        if ($context['country_code'] === null) {
            $items[] = $this->missingItem(
                code: 'country_code',
                label: 'Paese rilevante per la copertura',
            );
        }

        if ($context['delivery_date'] === null) {
            $items[] = $this->missingItem(
                code: 'delivery_date',
                label: 'Data di consegna',
            );
        }

        if ($context['declared_coverage'] === null) {
            $items[] = $this->missingItem(
                code: 'declared_coverage',
                label: 'Copertura dichiarata in un documento',
            );
        }

        if (! $warranty->starts_at) {
            $items[] = $this->missingItem(
                code: 'starts_at',
                label: 'Data iniziale della copertura',
                isBlocking: true,
            );
        }

        if (! $warranty->ends_at) {
            $items[] = $this->missingItem(
                code: 'ends_at',
                label: 'Data finale della copertura',
                isBlocking: true,
            );
        }

        if (! $confirmation['is_confirmed']) {
            $items[] = $this->missingItem(
                code: 'user_confirmation',
                label: 'Conferma dell’utente',
            );
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function missingItem(
        string $code,
        string $label,
        bool $isBlocking = false
    ): array {
        return [
            'code' => $code,
            'label' => $label,
            'is_blocking' => $isBlocking,
        ];
    }

    private function normalizeCoverageState(
        mixed $value
    ): ?string {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        return array_key_exists(
            $value,
            self::COVERAGE_STATE_LABELS
        )
            ? $value
            : null;
    }

    private function normalizeContextValue(
        string $field,
        mixed $value
    ): string {
        if (! is_string($value)) {
            return 'unknown';
        }

        $value = strtolower(trim($value));

        return in_array(
            $value,
            self::CONTEXT_VALUES[$field],
            true
        )
            ? $value
            : 'unknown';
    }

    private function normalizeNullableBoolean(
        mixed $value
    ): ?bool {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === 1 || $value === '1') {
            return true;
        }

        if ($value === 0 || $value === '0') {
            return false;
        }

        return null;
    }

    private function normalizeNullableInteger(
        mixed $value
    ): ?int {
        if (is_int($value)) {
            return $value;
        }

        if (
            is_string($value)
            && ctype_digit($value)
        ) {
            return (int) $value;
        }

        return null;
    }

    private function normalizeDate(
        mixed $value
    ): ?string {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance(
                $value
            )->toDateString();
        }

        if (
            ! is_string($value)
            || trim($value) === ''
        ) {
            return null;
        }

        try {
            return CarbonImmutable::parse(
                $value
            )->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeDateTime(
        mixed $value
    ): ?string {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance(
                $value
            )->toISOString();
        }

        if (
            ! is_string($value)
            || trim($value) === ''
        ) {
            return null;
        }

        try {
            return CarbonImmutable::parse(
                $value
            )->toISOString();
        } catch (Throwable) {
            return null;
        }
    }

    private function sourceLabel(
        ?string $source
    ): string {
        return match ($source) {
            'calculated' => 'Calcolata da Product Vault',
            'manual' => 'Inserita o modificata manualmente',
            'user' => 'Dichiarata dall’utente',
            'document_text' => 'Ricavata dal documento',
            'merchant' => 'Dichiarata dal venditore',
            'manufacturer' => 'Dichiarata dal produttore',
            default => 'Fonte non disponibile',
        };
    }
}