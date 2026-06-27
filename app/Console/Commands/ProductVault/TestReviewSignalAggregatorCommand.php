<?php

namespace App\Console\Commands\ProductVault;

use App\Services\Documents\ReviewSignals\ReviewSignalAggregator;
use Illuminate\Console\Command;

class TestReviewSignalAggregatorCommand extends Command
{
    /**
     * Nome del comando.
     *
     * @var string
     */
    protected $signature =
        'product-vault:test-review-signal-aggregator';

    /**
     * Descrizione del comando.
     *
     * @var string
     */
    protected $description =
        'Verifica raggruppamento, priorità e deduplica dei segnali di revisione.';

    /**
     * Esegue test deterministici e read-only sull'aggregatore.
     */
    public function handle(
        ReviewSignalAggregator $aggregator
    ): int {
        $rows = [];
        $failures = [];

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
         * Due codici distinti descrivono lo stesso problema semantico.
         *
         * Il warning deve rappresentare il problema nella UI primaria,
         * anche quando arriva dopo un normale segnale.
         *
         * La diagnostica deve conservare entrambi i codici originali.
         */
        $identityReferenceResult = $aggregator->aggregate([
            [
                'source' => 'python',
                'kind' => 'signal',
                'values' => [
                    'similarity_below_min_score',
                ],
            ],
            [
                'source' => 'python',
                'kind' => 'warning',
                'values' => [
                    'unusable_similarity_match',
                ],
            ],
        ]);

        $assertSame(
            'identity_reference_deduplication',
            'aggregation version',
            ReviewSignalAggregator::VERSION,
            $identityReferenceResult['version'] ?? null
        );

        $assertSame(
            'identity_reference_deduplication',
            'diagnostics preserve both codes',
            [
                'similarity_below_min_score',
                'unusable_similarity_match',
            ],
            array_column(
                $identityReferenceResult['diagnostics']['items'] ?? [],
                'technical_code'
            )
        );

        $assertSame(
            'identity_reference_deduplication',
            'single primary item',
            1,
            count(
                $identityReferenceResult['primary']['items'] ?? []
            )
        );

        $assertSame(
            'identity_reference_deduplication',
            'warning wins semantic duplicate',
            'unusable_similarity_match',
            data_get(
                $identityReferenceResult,
                'primary.items.0.technical_code'
            )
        );

        $assertSame(
            'identity_reference_deduplication',
            'warning kind preserved',
            'warning',
            data_get(
                $identityReferenceResult,
                'primary.items.0.kind'
            )
        );

        $assertSame(
            'identity_reference_deduplication',
            'source preserved',
            'python',
            data_get(
                $identityReferenceResult,
                'primary.items.0.source'
            )
        );

        $assertSame(
            'identity_reference_deduplication',
            'semantic key preserved',
            'identity_reference_not_reliable',
            data_get(
                $identityReferenceResult,
                'primary.items.0.deduplication_key'
            )
        );

        $assertSame(
            'identity_reference_deduplication',
            'duplicate suppression recorded',
            1,
            data_get(
                $identityReferenceResult,
                'counts.suppressed_duplicates'
            )
        );

        $assertSame(
            'identity_reference_deduplication',
            'kept duplicate reference',
            'unusable_similarity_match',
            data_get(
                $identityReferenceResult,
                'diagnostics.suppressed_duplicates.0.kept.technical_code'
            )
        );

        $assertSame(
            'identity_reference_deduplication',
            'suppressed duplicate reference',
            'similarity_below_min_score',
            data_get(
                $identityReferenceResult,
                'diagnostics.suppressed_duplicates.0.suppressed.technical_code'
            )
        );

        /*
         * Seconda famiglia di segnali semanticamente equivalenti.
         *
         * A parità di priorità viene mantenuto il primo elemento ricevuto.
         */
        $nameDistinctivenessResult = $aggregator->aggregate([
            [
                'source' => 'python',
                'kind' => 'warning',
                'values' => [
                    'insufficient_informative_token_overlap',
                    'low_informative_token_overlap_ratio',
                ],
            ],
        ]);

        $assertSame(
            'name_distinctiveness_deduplication',
            'diagnostics preserve both codes',
            [
                'insufficient_informative_token_overlap',
                'low_informative_token_overlap_ratio',
            ],
            array_column(
                $nameDistinctivenessResult['diagnostics']['items'] ?? [],
                'technical_code'
            )
        );

        $assertSame(
            'name_distinctiveness_deduplication',
            'single primary item',
            1,
            count(
                $nameDistinctivenessResult['primary']['items'] ?? []
            )
        );

        $assertSame(
            'name_distinctiveness_deduplication',
            'first equivalent item retained',
            'insufficient_informative_token_overlap',
            data_get(
                $nameDistinctivenessResult,
                'primary.items.0.technical_code'
            )
        );

        $assertSame(
            'name_distinctiveness_deduplication',
            'semantic key',
            'identity_name_not_distinctive',
            data_get(
                $nameDistinctivenessResult,
                'primary.items.0.deduplication_key'
            )
        );

        /*
         * I gruppi devono restare distinti.
         *
         * missing_global_facts è diagnostica di completezza e non deve
         * occupare la UI primaria.
         */
        $groupingResult = $aggregator->aggregate([
            [
                'source' => 'python',
                'kind' => 'warning',
                'values' => [
                    'low_similarity_to_global_canonical_name',
                ],
            ],
            [
                'source' => 'amount_consistency',
                'kind' => 'signal',
                'values' => [
                    'quantity_x_unit_price_matches_total_price',
                    'missing_quantity',
                ],
            ],
            [
                'source' => 'global_facts',
                'kind' => 'signal',
                'values' => [
                    'missing_global_facts',
                ],
            ],
        ]);

        $assertSame(
            'semantic_grouping',
            'primary attention count',
            1,
            data_get(
                $groupingResult,
                'counts.primary_by_group.attention'
            )
        );

        $assertSame(
            'semantic_grouping',
            'primary positive count',
            1,
            data_get(
                $groupingResult,
                'counts.primary_by_group.positive'
            )
        );

        $assertSame(
            'semantic_grouping',
            'primary missing count',
            0,
            data_get(
                $groupingResult,
                'counts.primary_by_group.missing'
            )
        );

        $assertSame(
            'semantic_grouping',
            'diagnostic missing count',
            1,
            data_get(
                $groupingResult,
                'counts.diagnostics_by_group.missing'
            )
        );

        $assertSame(
            'semantic_grouping',
            'missing signal retained in diagnostics',
            'missing_quantity',
            data_get(
                $groupingResult,
                'diagnostics.groups.missing.0.technical_code'
            )
        );

        $assertSame(
            'semantic_grouping',
            'primary diagnostic count',
            0,
            data_get(
                $groupingResult,
                'counts.primary_by_group.diagnostic'
            )
        );

        $assertSame(
            'semantic_grouping',
            'diagnostic item retained',
            1,
            data_get(
                $groupingResult,
                'counts.diagnostics_by_group.diagnostic'
            )
        );

        $assertSame(
            'semantic_grouping',
            'diagnostic technical code retained',
            'missing_global_facts',
            data_get(
                $groupingResult,
                'diagnostics.groups.diagnostic.0.technical_code'
            )
        );

        $assertSame(
            'semantic_grouping',
            'primary order follows semantic priority',
            [
                'low_similarity_to_global_canonical_name',
                'quantity_x_unit_price_matches_total_price',
            ],
            array_column(
                $groupingResult['primary']['items'] ?? [],
                'technical_code'
            )
        );

        /*
         * Un warning sconosciuto deve essere leggibile e visibile nella
         * UI primaria, conservando codice, sorgente e natura tecnica.
         */
        $unknownWarningResult = $aggregator->aggregate([
            [
                'source' => 'feedback',
                'kind' => 'warning',
                'values' => [
                    'brand_reference_needs_manual_check',
                ],
            ],
        ]);

        $assertSame(
            'unknown_warning',
            'unknown warning is primary',
            1,
            data_get(
                $unknownWarningResult,
                'counts.primary'
            )
        );

        $assertSame(
            'unknown_warning',
            'known flag false',
            false,
            data_get(
                $unknownWarningResult,
                'primary.items.0.known'
            )
        );

        $assertSame(
            'unknown_warning',
            'technical code preserved',
            'brand_reference_needs_manual_check',
            data_get(
                $unknownWarningResult,
                'primary.items.0.technical_code'
            )
        );

        $assertSame(
            'unknown_warning',
            'raw value preserved',
            'brand_reference_needs_manual_check',
            data_get(
                $unknownWarningResult,
                'primary.items.0.raw_value'
            )
        );

        $assertSame(
            'unknown_warning',
            'source preserved',
            'feedback',
            data_get(
                $unknownWarningResult,
                'primary.items.0.source'
            )
        );

        $assertSame(
            'unknown_warning',
            'kind preserved',
            'warning',
            data_get(
                $unknownWarningResult,
                'primary.items.0.kind'
            )
        );

        /*
         * Un normale segnale sconosciuto deve restare disponibile nella
         * diagnostica senza affollare la UI primaria.
         */
        $unknownSignalResult = $aggregator->aggregate([
            [
                'source' => 'python',
                'kind' => 'signal',
                'values' => [
                    'experimental_similarity_trace',
                ],
            ],
        ]);

        $assertSame(
            'unknown_signal',
            'not shown in primary',
            0,
            data_get(
                $unknownSignalResult,
                'counts.primary'
            )
        );

        $assertSame(
            'unknown_signal',
            'retained in diagnostics',
            1,
            data_get(
                $unknownSignalResult,
                'counts.received'
            )
        );

        $assertSame(
            'unknown_signal',
            'diagnostic group',
            'diagnostic',
            data_get(
                $unknownSignalResult,
                'diagnostics.items.0.group'
            )
        );

        /*
         * Il presenter deve continuare a normalizzare i messaggi testuali
         * nei relativi codici tecnici.
         */
        $textNormalizationResult = $aggregator->aggregate([
            [
                'source' => 'python',
                'kind' => 'warning',
                'values' => [
                    'Unusable similarity match',
                ],
            ],
        ]);

        $assertSame(
            'text_normalization',
            'technical code normalized',
            'unusable_similarity_match',
            data_get(
                $textNormalizationResult,
                'primary.items.0.technical_code'
            )
        );

        $assertSame(
            'text_normalization',
            'raw value preserved',
            'Unusable similarity match',
            data_get(
                $textNormalizationResult,
                'primary.items.0.raw_value'
            )
        );

        /*
         * Valori vuoti o non testuali devono essere ignorati.
         *
         * L'array di input non deve essere modificato dal servizio.
         */
        $normalizationInput = [
            [
                'source' => 'amount_consistency',
                'kind' => 'signal',
                'values' => [
                    null,
                    '',
                    '   ',
                    false,
                    123,
                    'missing_total_price',
                ],
            ],
            'invalid_collection',
        ];

        $normalizationInputSnapshot = $normalizationInput;

        $normalizationResult = $aggregator->aggregate(
            $normalizationInput
        );

        $assertSame(
            'input_normalization',
            'only valid textual signal received',
            1,
            data_get(
                $normalizationResult,
                'counts.received'
            )
        );

        $assertSame(
            'input_normalization',
            'valid code retained',
            'missing_total_price',
            data_get(
                $normalizationResult,
                'diagnostics.items.0.technical_code'
            )
        );

        $assertSame(
            'input_normalization',
            'input remains unchanged',
            $normalizationInputSnapshot,
            $normalizationInput
        );

        /*
         * Il contratto vuoto deve mantenere tutti i gruppi previsti.
         */
        $emptyResult = $aggregator->aggregate([]);

        $assertSame(
            'empty_contract',
            'received count',
            0,
            data_get(
                $emptyResult,
                'counts.received'
            )
        );

        $assertSame(
            'empty_contract',
            'primary groups always present',
            [
                'attention',
                'positive',
                'missing',
                'diagnostic',
            ],
            array_keys(
                $emptyResult['primary']['groups'] ?? []
            )
        );

        $assertSame(
            'empty_contract',
            'diagnostic groups always present',
            [
                'attention',
                'positive',
                'missing',
                'diagnostic',
            ],
            array_keys(
                $emptyResult['diagnostics']['groups'] ?? []
            )
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
            $this->newLine();
            $this->error(
                'Review signal aggregator checks failed.'
            );

            foreach ($failures as $failure) {
                $this->newLine();

                $this->line(
                    '<fg=red>'.$failure['scenario']
                    .' — '.$failure['assertion'].'</>'
                );

                $this->line(
                    'Expected: '.$this->formatValue(
                        $failure['expected']
                    )
                );

                $this->line(
                    'Actual:   '.$this->formatValue(
                        $failure['actual']
                    )
                );
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->info(
            'Review signal aggregator checks passed.'
        );

        return self::SUCCESS;
    }

    /**
     * Formatta i valori delle assertion fallite.
     */
    private function formatValue(mixed $value): string
    {
        $encoded = json_encode(
            $value,
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
        );

        return $encoded !== false
            ? $encoded
            : var_export($value, true);
    }
}