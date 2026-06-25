<?php

namespace App\Console\Commands\ProductVault;

use App\Models\ProductIdentificationCandidate;
use App\Services\Documents\AssistedReview\AssistedReviewConfirmationGuard;
use Illuminate\Console\Command;

class TestAssistedReviewConfirmationGuardCommand extends Command
{
    /**
     * Nome e argomenti del comando.
     *
     * @var string
     */
    protected $signature =
        'product-vault:test-assisted-review-confirmation-guard';

    /**
     * Descrizione del comando.
     *
     * @var string
     */
    protected $description =
        'Verifica il guardrail di conferma dei candidati Assisted Review.';

    /**
     * Esegue le verifiche senza scrivere nel database.
     */
    public function handle(
        AssistedReviewConfirmationGuard $guard
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
         * Candidato storico privo di Assisted Review.
         */
        $legacyCandidate = $this->candidateWithMetadata([
            'existing_namespace' => [
                'preserve_me' => true,
            ],
        ]);

        $legacyResult = $guard->evaluate(
            $legacyCandidate
        );

        $assertSame(
            'legacy_candidate',
            'confirmation allowed',
            true,
            $legacyResult['allowed']
        );

        $assertSame(
            'legacy_candidate',
            'reason',
            'legacy_without_assisted_review',
            $legacyResult['reason']
        );

        /*
         * Contratto completo con dati presenti o decisi dall'utente.
         */
        $completeCandidate = $this->candidateWithMetadata([
            'assisted_review' => [
                'version' => 'v1',
                'needs_user_completion' => false,
                'completion_fields' => [],
                'fields' => [
                    'brand' => [
                        'state' => 'confirmed',
                    ],
                    'category' => [
                        'state' => 'present',
                    ],
                    'model' => [
                        'state' => 'declined',
                    ],
                ],
            ],
        ]);

        $completeResult = $guard->evaluate(
            $completeCandidate
        );

        $assertSame(
            'complete_candidate',
            'confirmation allowed',
            true,
            $completeResult['allowed']
        );

        $assertSame(
            'complete_candidate',
            'reason',
            'assisted_review_complete',
            $completeResult['reason']
        );

        $assertSame(
            'complete_candidate',
            'unresolved fields empty',
            [],
            $completeResult['unresolved_fields']
        );

        /*
         * Campi mancanti o suggeriti devono bloccare la conferma.
         */
        $incompleteCandidate = $this->candidateWithMetadata([
            'assisted_review' => [
                'version' => 'v1',
                'needs_user_completion' => true,
                'completion_fields' => [
                    'brand',
                    'model',
                ],
                'fields' => [
                    'brand' => [
                        'state' => 'suggested',
                    ],
                    'category' => [
                        'state' => 'present',
                    ],
                    'model' => [
                        'state' => 'missing',
                    ],
                ],
            ],
        ]);

        $incompleteResult = $guard->evaluate(
            $incompleteCandidate
        );

        $assertSame(
            'incomplete_candidate',
            'confirmation blocked',
            false,
            $incompleteResult['allowed']
        );

        $assertSame(
            'incomplete_candidate',
            'reason',
            'assisted_review_incomplete',
            $incompleteResult['reason']
        );

        $assertSame(
            'incomplete_candidate',
            'unresolved fields',
            [
                'brand',
                'model',
            ],
            $incompleteResult['unresolved_fields']
        );

        $assertSame(
            'incomplete_candidate',
            'message',
            'Completa o dichiara non disponibili i seguenti campi prima di confermare il candidato: brand, modello.',
            $incompleteResult['message']
        );

        /*
         * Il fallback completion_fields deve coprire snapshot nei quali
         * lo stato del campo non è disponibile.
         */
        $fallbackCandidate = $this->candidateWithMetadata([
            'assisted_review' => [
                'version' => 'v1',
                'needs_user_completion' => true,
                'completion_fields' => [
                    'category',
                ],
                'fields' => [],
            ],
        ]);

        $fallbackResult = $guard->evaluate(
            $fallbackCandidate
        );

        $assertSame(
            'completion_fields_fallback',
            'confirmation blocked',
            false,
            $fallbackResult['allowed']
        );

        $assertSame(
            'completion_fields_fallback',
            'unresolved category',
            [
                'category',
            ],
            $fallbackResult['unresolved_fields']
        );

        /*
         * Un flag incompleto privo di campi validi non può essere ignorato.
         */
        $unknownCandidate = $this->candidateWithMetadata([
            'assisted_review' => [
                'version' => 'v1',
                'needs_user_completion' => true,
                'completion_fields' => [],
                'fields' => [],
            ],
        ]);

        $unknownResult = $guard->evaluate(
            $unknownCandidate
        );

        $assertSame(
            'unknown_incomplete',
            'confirmation blocked',
            false,
            $unknownResult['allowed']
        );

        $assertSame(
            'unknown_incomplete',
            'unknown fallback',
            [
                'unknown',
            ],
            $unknownResult['unresolved_fields']
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
            'Assisted Review confirmation guard checks passed.'
        );

        return self::SUCCESS;
    }

    /**
     * Crea un model in memoria senza persistenza.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function candidateWithMetadata(
        array $metadata
    ): ProductIdentificationCandidate {
        $candidate =
            new ProductIdentificationCandidate();

        $candidate->metadata = $metadata;

        return $candidate;
    }
}