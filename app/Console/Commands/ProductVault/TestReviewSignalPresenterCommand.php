<?php

namespace App\Console\Commands\ProductVault;

use App\Services\Documents\ReviewSignals\ReviewSignalPresenter;
use Illuminate\Console\Command;

class TestReviewSignalPresenterCommand extends Command
{
    /**
     * Nome e argomenti del comando.
     *
     * @var string
     */
    protected $signature =
        'product-vault:test-review-signal-presenter';

    /**
     * Descrizione del comando.
     *
     * @var string
     */
    protected $description =
        'Verifica il mapping read-only dei segnali tecnici per la UI di revisione.';

    /**
     * Esegue i controlli senza modificare il database.
     */
    public function handle(
        ReviewSignalPresenter $presenter
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
         * Il presenter deve riconoscere sia snake_case sia la stessa
         * espressione ricevuta come testo umano.
         */
        $similarity = $presenter->present(
            signal: 'Unusable similarity match',
            source: 'python',
            kind: 'warning',
        );

        $assertSame(
            'known_warning',
            'technical code normalized',
            'unusable_similarity_match',
            $similarity['technical_code']
        );

        $assertSame(
            'known_warning',
            'known mapping',
            true,
            $similarity['known']
        );

        $assertSame(
            'known_warning',
            'group',
            'attention',
            $similarity['group']
        );

        $assertSame(
            'known_warning',
            'severity',
            'warning',
            $similarity['severity']
        );

        $assertSame(
            'known_warning',
            'human title',
            'Nessun confronto affidabile',
            $similarity['title']
        );

        $assertSame(
            'known_warning',
            'primary UI',
            true,
            $similarity[
                'show_in_primary_ui'
            ]
        );

        /*
         * Un controllo positivo non deve diventare un warning.
         */
        $amounts = $presenter->present(
            signal:
                'quantity_x_unit_price_matches_total_price',
            source: 'amount_consistency',
            kind: 'signal',
        );

        $assertSame(
            'positive_signal',
            'group',
            'positive',
            $amounts['group']
        );

        $assertSame(
            'positive_signal',
            'severity',
            'success',
            $amounts['severity']
        );

        $assertSame(
            'positive_signal',
            'field',
            'amounts',
            $amounts['field']
        );

        $assertSame(
            'positive_signal',
            'title',
            'Importi coerenti',
            $amounts['title']
        );

        /*
         * Un gap di knowledge resta diagnostico e non diventa allarme.
         */
        $knowledgeGap = $presenter->present(
            signal: 'missing_global_facts',
            source: 'python',
            kind: 'warning',
        );

        $assertSame(
            'knowledge_gap',
            'group',
            'diagnostic',
            $knowledgeGap['group']
        );

        $assertSame(
            'knowledge_gap',
            'severity',
            'neutral',
            $knowledgeGap['severity']
        );

        $assertSame(
            'knowledge_gap',
            'not primary',
            false,
            $knowledgeGap[
                'show_in_primary_ui'
            ]
        );

        /*
         * Un warning sconosciuto deve avere un fallback leggibile, ma
         * conservare il codice tecnico per audit e debug.
         */
        $unknownWarning = $presenter->present(
            signal: 'future_special_warning',
            source: 'future_analyzer',
            kind: 'warning',
        );

        $assertSame(
            'unknown_warning',
            'known false',
            false,
            $unknownWarning['known']
        );

        $assertSame(
            'unknown_warning',
            'technical code preserved',
            'future_special_warning',
            $unknownWarning[
                'technical_code'
            ]
        );

        $assertSame(
            'unknown_warning',
            'human fallback',
            'Verifica consigliata',
            $unknownWarning['title']
        );

        $assertSame(
            'unknown_warning',
            'primary UI',
            true,
            $unknownWarning[
                'show_in_primary_ui'
            ]
        );

        /*
         * Un segnale informativo sconosciuto resta nella diagnostica e
         * non affolla la card principale.
         */
        $unknownSignal = $presenter->present(
            signal: 'future_diagnostic_signal',
            source: 'future_analyzer',
            kind: 'signal',
        );

        $assertSame(
            'unknown_signal',
            'group',
            'diagnostic',
            $unknownSignal['group']
        );

        $assertSame(
            'unknown_signal',
            'primary UI',
            false,
            $unknownSignal[
                'show_in_primary_ui'
            ]
        );

        /*
         * La presentazione deve essere deterministica.
         */
        $first = $presenter->present(
            signal:
                'low_informative_token_overlap_ratio',
            source: 'python',
            kind: 'signal',
        );

        $second = $presenter->present(
            signal:
                'low_informative_token_overlap_ratio',
            source: 'python',
            kind: 'signal',
        );

        $assertSame(
            'determinism',
            'same input same output',
            $first,
            $second
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
            foreach ($failures as $failure) {
                $this->error(
                    $failure['scenario']
                    . ' / '
                    . $failure['assertion']
                );

                $this->line(
                    'Expected: '
                    . var_export(
                        $failure['expected'],
                        true
                    )
                );

                $this->line(
                    'Actual: '
                    . var_export(
                        $failure['actual'],
                        true
                    )
                );
            }

            return self::FAILURE;
        }

        $this->info(
            'Review signal presenter checks passed.'
        );

        return self::SUCCESS;
    }
}