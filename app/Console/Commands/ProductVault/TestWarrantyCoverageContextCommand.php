<?php

namespace App\Console\Commands\ProductVault;

use App\Models\Warranty;
use App\Models\WarrantyType;
use App\Services\Warranties\WarrantyCoverageContextResolver;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class TestWarrantyCoverageContextCommand extends Command
{
    /**
     * Nome del comando Artisan.
     *
     * @var string
     */
    protected $signature =
        'product-vault:test-warranty-coverage-context';

    /**
     * Descrizione del comando.
     *
     * @var string
     */
    protected $description =
        'Run controlled Warranty coverage context checks';

    /**
     * Esegue gli scenari controllati del coverage context resolver.
     */
    public function handle(
        WarrantyCoverageContextResolver $resolver
    ): int {
        $rows = [];
        $failures = [];

        $record = function (
            string $scenario,
            string $assertion,
            bool $passed,
            mixed $expected = null,
            mixed $actual = null
        ) use (&$rows, &$failures): void {
            $rows[] = [
                $scenario,
                $assertion,
                $passed ? 'OK' : 'FAIL',
            ];

            if ($passed) {
                return;
            }

            $failures[] = [
                'scenario' => $scenario,
                'assertion' => $assertion,
                'expected' => $expected,
                'actual' => $actual,
            ];
        };

        $assertSame = function (
            string $scenario,
            string $assertion,
            mixed $expected,
            mixed $actual
        ) use ($record): void {
            $record(
                scenario: $scenario,
                assertion: $assertion,
                passed: $expected === $actual,
                expected: $expected,
                actual: $actual,
            );
        };

        $assertTrue = function (
            string $scenario,
            string $assertion,
            bool $actual
        ) use ($record): void {
            $record(
                scenario: $scenario,
                assertion: $assertion,
                passed: $actual === true,
                expected: true,
                actual: $actual,
            );
        };

        $assertContains = function (
            string $scenario,
            string $assertion,
            mixed $expected,
            array $actual
        ) use ($record): void {
            $record(
                scenario: $scenario,
                assertion: $assertion,
                passed: in_array(
                    $expected,
                    $actual,
                    true
                ),
                expected: $expected,
                actual: $actual,
            );
        };

        $assertNotContains = function (
            string $scenario,
            string $assertion,
            mixed $unexpected,
            array $actual
        ) use ($record): void {
            $record(
                scenario: $scenario,
                assertion: $assertion,
                passed: ! in_array(
                    $unexpected,
                    $actual,
                    true
                ),
                expected: 'value not present: '.$unexpected,
                actual: $actual,
            );
        };

        /*
        |--------------------------------------------------------------------------
        | Scenario 1: copertura calcolata senza metadata contestuali
        |--------------------------------------------------------------------------
        */
        $calculatedWarranty = $this->makeWarranty([
            'starts_at' => '2026-06-10',
            'ends_at' => '2028-06-10',
            'duration_months' => 24,
            'source' => 'calculated',
            'confidence_score' => 70,
            'metadata' => [
                'creator' => 'default_warranty_creator_v1',
                'rule_id' => 10,
                'rule_type' => 'legal_estimate',
                'rule_priority' => 10,
                'country_code' => 'IT',
                'source_note' =>
                    'Regola italiana generale di test.',
                'calculation' => [
                    'starts_at_source' =>
                        'product.purchase_date',
                    'duration_months_source' =>
                        'warranty_rule',
                    'ends_at_formula' =>
                        'starts_at + duration_months',
                ],
            ],
        ]);

        $calculatedMetadataBefore =
            $calculatedWarranty->metadata;

        $calculatedResult = $resolver->resolve(
            warranty: $calculatedWarranty,
            referenceDate: CarbonImmutable::parse(
                '2026-06-20'
            ),
        );

        $calculatedMissingCodes =
            $this->missingCodes($calculatedResult);

        $assertSame(
            'calculated_estimate',
            'contract version',
            WarrantyCoverageContextResolver::VERSION,
            data_get(
                $calculatedResult,
                'version'
            ),
        );

        $assertSame(
            'calculated_estimate',
            'coverage state',
            'estimated',
            data_get(
                $calculatedResult,
                'coverage_state.code'
            ),
        );

        $assertSame(
            'calculated_estimate',
            'coverage state source',
            'warranty_source_fallback',
            data_get(
                $calculatedResult,
                'coverage_state.source'
            ),
        );

        $assertSame(
            'calculated_estimate',
            'estimate flag',
            true,
            data_get(
                $calculatedResult,
                'coverage_state.is_estimate'
            ),
        );

        $assertSame(
            'calculated_estimate',
            'temporal status',
            'active',
            data_get(
                $calculatedResult,
                'temporal_status.code'
            ),
        );

        $assertSame(
            'calculated_estimate',
            'country fallback',
            'IT',
            data_get(
                $calculatedResult,
                'context.country_code'
            ),
        );

        $assertSame(
            'calculated_estimate',
            'basis reason',
            'Copertura calcolata automaticamente usando la data di acquisto e una regola configurata per il paese IT.',
            data_get(
                $calculatedResult,
                'basis.reason'
            ),
        );

        $assertContains(
            'calculated_estimate',
            'purchase use missing',
            'purchase_use',
            $calculatedMissingCodes,
        );

        $assertContains(
            'calculated_estimate',
            'user confirmation missing',
            'user_confirmation',
            $calculatedMissingCodes,
        );

        $assertNotContains(
            'calculated_estimate',
            'country not missing',
            'country_code',
            $calculatedMissingCodes,
        );

        $assertSame(
            'calculated_estimate',
            'can confirm estimate',
            true,
            data_get(
                $calculatedResult,
                'actions.can_confirm'
            ),
        );

        $assertSame(
            'calculated_estimate',
            'metadata unchanged',
            $calculatedMetadataBefore,
            $calculatedWarranty->metadata,
        );

        $assertSame(
            'calculated_estimate',
            'model remains clean',
            [],
            $calculatedWarranty->getDirty(),
        );

        /*
        |--------------------------------------------------------------------------
        | Scenario 2: stati temporali
        |--------------------------------------------------------------------------
        */
        $notStartedResult = $resolver->resolve(
            warranty: $this->makeWarranty([
                'starts_at' => '2026-07-01',
                'ends_at' => '2028-07-01',
                'source' => 'calculated',
            ]),
            referenceDate: CarbonImmutable::parse(
                '2026-06-20'
            ),
        );

        $assertSame(
            'temporal_statuses',
            'not started',
            'not_started',
            data_get(
                $notStartedResult,
                'temporal_status.code'
            ),
        );

        $expiringResult = $resolver->resolve(
            warranty: $this->makeWarranty([
                'starts_at' => '2024-07-20',
                'ends_at' => '2026-07-20',
                'source' => 'calculated',
            ]),
            referenceDate: CarbonImmutable::parse(
                '2026-06-20'
            ),
        );

        $assertSame(
            'temporal_statuses',
            'thirty days is expiring',
            'expiring',
            data_get(
                $expiringResult,
                'temporal_status.code'
            ),
        );

        $expiredResult = $resolver->resolve(
            warranty: $this->makeWarranty([
                'starts_at' => '2024-06-19',
                'ends_at' => '2026-06-19',
                'source' => 'calculated',
            ]),
            referenceDate: CarbonImmutable::parse(
                '2026-06-20'
            ),
        );

        $assertSame(
            'temporal_statuses',
            'expired',
            'expired',
            data_get(
                $expiredResult,
                'temporal_status.code'
            ),
        );

        $unknownPeriodResult = $resolver->resolve(
            warranty: $this->makeWarranty([
                'starts_at' => null,
                'ends_at' => null,
                'duration_months' => null,
                'source' => 'manual',
            ]),
            referenceDate: CarbonImmutable::parse(
                '2026-06-20'
            ),
        );

        $unknownPeriodMissingCodes =
            $this->missingCodes($unknownPeriodResult);

        $assertSame(
            'temporal_statuses',
            'unknown period',
            'unknown',
            data_get(
                $unknownPeriodResult,
                'temporal_status.code'
            ),
        );

        $assertContains(
            'temporal_statuses',
            'missing start date',
            'starts_at',
            $unknownPeriodMissingCodes,
        );

        $assertContains(
            'temporal_statuses',
            'missing end date',
            'ends_at',
            $unknownPeriodMissingCodes,
        );

        $assertTrue(
            'temporal_statuses',
            'start date is blocking',
            $this->missingItemIsBlocking(
                result: $unknownPeriodResult,
                code: 'starts_at',
            ),
        );

        $assertTrue(
            'temporal_statuses',
            'end date is blocking',
            $this->missingItemIsBlocking(
                result: $unknownPeriodResult,
                code: 'ends_at',
            ),
        );

        /*
        |--------------------------------------------------------------------------
        | Scenario 3: contesto completo e verificato
        |--------------------------------------------------------------------------
        */
        $verifiedWarranty = $this->makeWarranty([
            'starts_at' => '2026-06-12',
            'ends_at' => '2028-06-12',
            'duration_months' => 24,
            'source' => 'calculated',
            'metadata' => [
                'country_code' => 'IT',
                'coverage_context' => [
                    'version' => 'v1',
                    'state' => 'verified',

                    'purchase' => [
                        'use' => 'personal',
                        'seller_type' => 'professional',
                    ],

                    'product' => [
                        'condition' => 'new',
                    ],

                    'jurisdiction' => [
                        'country_code' => 'it',
                    ],

                    'dates' => [
                        'delivered_at' => '2026-06-12',
                    ],

                    'declared_coverage' => [
                        'present' => true,
                    ],

                    'confirmation' => [
                        'applied' => true,
                        'confirmed_at' =>
                            '2026-06-21T10:30:00+02:00',
                        'confirmed_by_user_id' => '42',
                    ],
                ],
            ],
        ]);

        $verifiedMetadataBefore =
            $verifiedWarranty->metadata;

        $verifiedResult = $resolver->resolve(
            warranty: $verifiedWarranty,
            referenceDate: CarbonImmutable::parse(
                '2026-06-20'
            ),
        );

        $assertSame(
            'verified_context',
            'coverage state',
            'verified',
            data_get(
                $verifiedResult,
                'coverage_state.code'
            ),
        );

        $assertSame(
            'verified_context',
            'state comes from metadata',
            'metadata',
            data_get(
                $verifiedResult,
                'coverage_state.source'
            ),
        );

        $assertSame(
            'verified_context',
            'purchase use',
            'personal',
            data_get(
                $verifiedResult,
                'context.purchase_use'
            ),
        );

        $assertSame(
            'verified_context',
            'seller type',
            'professional',
            data_get(
                $verifiedResult,
                'context.seller_type'
            ),
        );

        $assertSame(
            'verified_context',
            'product condition',
            'new',
            data_get(
                $verifiedResult,
                'context.product_condition'
            ),
        );

        $assertSame(
            'verified_context',
            'country normalized',
            'IT',
            data_get(
                $verifiedResult,
                'context.country_code'
            ),
        );

        $assertSame(
            'verified_context',
            'delivery date',
            '2026-06-12',
            data_get(
                $verifiedResult,
                'context.delivery_date'
            ),
        );

        $assertSame(
            'verified_context',
            'declared coverage',
            true,
            data_get(
                $verifiedResult,
                'context.declared_coverage'
            ),
        );

        $assertSame(
            'verified_context',
            'confirmation applied',
            true,
            data_get(
                $verifiedResult,
                'confirmation.is_confirmed'
            ),
        );

        $assertSame(
            'verified_context',
            'confirming user normalized',
            42,
            data_get(
                $verifiedResult,
                'confirmation.confirmed_by_user_id'
            ),
        );

        $assertTrue(
            'verified_context',
            'confirmation timestamp normalized',
            is_string(
                data_get(
                    $verifiedResult,
                    'confirmation.confirmed_at'
                )
            ),
        );

        $assertSame(
            'verified_context',
            'no missing information',
            [],
            data_get(
                $verifiedResult,
                'missing_information'
            ),
        );

        $assertSame(
            'verified_context',
            'cannot reconfirm verified coverage',
            false,
            data_get(
                $verifiedResult,
                'actions.can_confirm'
            ),
        );

        $assertSame(
            'verified_context',
            'can edit verified coverage',
            true,
            data_get(
                $verifiedResult,
                'actions.can_edit'
            ),
        );

        $assertSame(
            'verified_context',
            'stored context version',
            'v1',
            data_get(
                $verifiedResult,
                'stored_context_version'
            ),
        );

        $assertSame(
            'verified_context',
            'metadata unchanged',
            $verifiedMetadataBefore,
            $verifiedWarranty->metadata,
        );

        $assertSame(
            'verified_context',
            'model remains clean',
            [],
            $verifiedWarranty->getDirty(),
        );

        /*
        |--------------------------------------------------------------------------
        | Scenario 4: fallback dalla fonte
        |--------------------------------------------------------------------------
        */
        $declaredResult = $resolver->resolve(
            warranty: $this->makeWarranty([
                'source' => 'document_text',
            ]),
            referenceDate: CarbonImmutable::parse(
                '2026-06-20'
            ),
        );

        $assertSame(
            'source_fallbacks',
            'document text becomes declared',
            'declared',
            data_get(
                $declaredResult,
                'coverage_state.code'
            ),
        );

        $assertSame(
            'source_fallbacks',
            'declared basis',
            'Copertura ricavata dalle informazioni presenti nel documento.',
            data_get(
                $declaredResult,
                'basis.reason'
            ),
        );

        $manualResult = $resolver->resolve(
            warranty: $this->makeWarranty([
                'source' => 'manual',
            ]),
            referenceDate: CarbonImmutable::parse(
                '2026-06-20'
            ),
        );

        $assertSame(
            'source_fallbacks',
            'manual becomes user confirmed',
            'user_confirmed',
            data_get(
                $manualResult,
                'coverage_state.code'
            ),
        );

        $assertSame(
            'source_fallbacks',
            'manual confirmation fallback',
            true,
            data_get(
                $manualResult,
                'confirmation.is_confirmed'
            ),
        );

        $assertSame(
            'source_fallbacks',
            'manual cannot be reconfirmed',
            false,
            data_get(
                $manualResult,
                'actions.can_confirm'
            ),
        );

        /*
        |--------------------------------------------------------------------------
        | Scenario 5: stato annullato
        |--------------------------------------------------------------------------
        */
        $cancelledResult = $resolver->resolve(
            warranty: $this->makeWarranty([
                'source' => 'manual',
                'metadata' => [
                    'coverage_context' => [
                        'version' => 'v1',
                        'state' => 'cancelled',
                    ],
                ],
            ]),
            referenceDate: CarbonImmutable::parse(
                '2026-06-20'
            ),
        );

        $assertSame(
            'cancelled_coverage',
            'coverage state',
            'cancelled',
            data_get(
                $cancelledResult,
                'coverage_state.code'
            ),
        );

        $assertSame(
            'cancelled_coverage',
            'cannot confirm',
            false,
            data_get(
                $cancelledResult,
                'actions.can_confirm'
            ),
        );

        $assertSame(
            'cancelled_coverage',
            'cannot edit',
            false,
            data_get(
                $cancelledResult,
                'actions.can_edit'
            ),
        );

        /*
        |--------------------------------------------------------------------------
        | Scenario 6: metadata non validi
        |--------------------------------------------------------------------------
        */
        $invalidMetadataWarranty = $this->makeWarranty([
            'starts_at' => null,
            'ends_at' => null,
            'source' => 'unsupported_source',
            'metadata' => [
                'coverage_context' => [
                    'version' => 'future-invalid',
                    'state' => 'not-a-state',

                    'purchase' => [
                        'use' => 'consumer-ish',
                        'seller_type' => 123,
                    ],

                    'product' => [
                        'condition' => 'almost-new',
                    ],

                    'jurisdiction' => [
                        'country_code' => [],
                    ],

                    'dates' => [
                        'delivered_at' => 'not-a-date',
                    ],

                    'declared_coverage' => [
                        'present' => 'maybe',
                    ],

                    'confirmation' => [
                        'applied' => 'maybe',
                        'confirmed_at' => 'not-a-date',
                        'confirmed_by_user_id' => 'not-an-id',
                    ],
                ],
            ],
        ]);

        $invalidMetadataBefore =
            $invalidMetadataWarranty->metadata;

        $invalidResult = $resolver->resolve(
            warranty: $invalidMetadataWarranty,
            referenceDate: CarbonImmutable::parse(
                '2026-06-20'
            ),
        );

        $invalidMissingCodes =
            $this->missingCodes($invalidResult);

        $assertSame(
            'invalid_metadata',
            'unknown coverage state',
            'unknown',
            data_get(
                $invalidResult,
                'coverage_state.code'
            ),
        );

        $assertSame(
            'invalid_metadata',
            'unknown purchase use',
            'unknown',
            data_get(
                $invalidResult,
                'context.purchase_use'
            ),
        );

        $assertSame(
            'invalid_metadata',
            'unknown seller type',
            'unknown',
            data_get(
                $invalidResult,
                'context.seller_type'
            ),
        );

        $assertSame(
            'invalid_metadata',
            'unknown product condition',
            'unknown',
            data_get(
                $invalidResult,
                'context.product_condition'
            ),
        );

        $assertSame(
            'invalid_metadata',
            'invalid country becomes null',
            null,
            data_get(
                $invalidResult,
                'context.country_code'
            ),
        );

        $assertSame(
            'invalid_metadata',
            'invalid delivery date becomes null',
            null,
            data_get(
                $invalidResult,
                'context.delivery_date'
            ),
        );

        $assertSame(
            'invalid_metadata',
            'invalid declared coverage becomes null',
            null,
            data_get(
                $invalidResult,
                'context.declared_coverage'
            ),
        );

        $assertSame(
            'invalid_metadata',
            'invalid confirmation is not applied',
            false,
            data_get(
                $invalidResult,
                'confirmation.is_confirmed'
            ),
        );

        $assertContains(
            'invalid_metadata',
            'country reported missing',
            'country_code',
            $invalidMissingCodes,
        );

        $assertContains(
            'invalid_metadata',
            'delivery date reported missing',
            'delivery_date',
            $invalidMissingCodes,
        );

        $assertContains(
            'invalid_metadata',
            'confirmation reported missing',
            'user_confirmation',
            $invalidMissingCodes,
        );

        $assertSame(
            'invalid_metadata',
            'metadata unchanged',
            $invalidMetadataBefore,
            $invalidMetadataWarranty->metadata,
        );

        $assertSame(
            'invalid_metadata',
            'model remains clean',
            [],
            $invalidMetadataWarranty->getDirty(),
        );

        /*
        |--------------------------------------------------------------------------
        | Risultato
        |--------------------------------------------------------------------------
        */
        $this->table(
            [
                'Scenario',
                'Assertion',
                'Status',
            ],
            $rows,
        );

        if ($failures !== []) {
            $this->error(
                'Warranty coverage context checks failed.'
            );

            foreach ($failures as $failure) {
                $this->newLine();

                $this->warn(
                    $failure['scenario']
                    .' / '
                    .$failure['assertion']
                );

                $this->line(
                    'Expected: '
                    .json_encode(
                        $failure['expected'],
                        JSON_UNESCAPED_UNICODE
                    )
                );

                $this->line(
                    'Actual:   '
                    .json_encode(
                        $failure['actual'],
                        JSON_UNESCAPED_UNICODE
                    )
                );
            }

            return self::FAILURE;
        }

        $this->info(
            'Warranty coverage context checks passed.'
        );

        return self::SUCCESS;
    }

    /**
     * Crea una Warranty non persistita con relazione tipo già caricata.
     *
     * In questo modo il comando verifica il resolver senza scrivere nel
     * database e senza dipendere da fixture preesistenti.
     *
     * @param array<string, mixed> $attributes
     */
    private function makeWarranty(
        array $attributes = []
    ): Warranty {
        $warranty = new Warranty();

        $warranty->forceFill(
            array_merge(
                [
                    'product_id' => 1,
                    'warranty_type_id' => 1,
                    'source_document_id' => null,
                    'starts_at' => '2026-06-10',
                    'ends_at' => '2028-06-10',
                    'duration_months' => 24,
                    'source' => 'calculated',
                    'confidence_score' => 70,
                    'notes' => null,
                    'metadata' => [],
                ],
                $attributes,
            )
        );

        $warrantyType = new WarrantyType();

        $warrantyType->forceFill([
            'code' => 'legal',
            'name' => 'Garanzia legale',
            'is_active' => true,
        ]);

        $warranty->setRelation(
            'warrantyType',
            $warrantyType
        );

        /*
         * Stabilisce il contenuto corrente come baseline originale.
         * Dopo resolve(), getDirty() deve restare vuoto.
         */
        $warranty->syncOriginal();

        return $warranty;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return list<string>
     */
    private function missingCodes(array $result): array
    {
        $items = data_get(
            $result,
            'missing_information',
            []
        );

        if (! is_array($items)) {
            return [];
        }

        $codes = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $code = $item['code'] ?? null;

            if (is_string($code)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function missingItemIsBlocking(
        array $result,
        string $code
    ): bool {
        $items = data_get(
            $result,
            'missing_information',
            []
        );

        if (! is_array($items)) {
            return false;
        }

        foreach ($items as $item) {
            if (
                is_array($item)
                && ($item['code'] ?? null) === $code
            ) {
                return ($item['is_blocking'] ?? false)
                    === true;
            }
        }

        return false;
    }
}