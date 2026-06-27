<?php

namespace App\Console\Commands\ProductVault;

use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\DocumentLineType;
use App\Models\ProductIdentificationCandidate;
use App\Services\Documents\ProductCandidateGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TestProductCandidateRegenerationProtectionCommand extends Command
{
    /**
     * Nome e argomenti del comando.
     *
     * @var string
     */
    protected $signature =
        'product-vault:test-product-candidate-regeneration-protection';

    /**
     * Descrizione del comando.
     *
     * @var string
     */
    protected $description =
        'Verifica con rollback che la rigenerazione preservi le decisioni Assisted Review.';

    /**
     * Esegue il test senza lasciare dati persistiti.
     */
    public function handle(
        ProductCandidateGenerator $generator
    ): int {
        $rows = [];
        $failures = [];

        $createdLineIds = [];
        $createdCandidateIds = [];

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

        DB::beginTransaction();

        try {
            /*
             * Usiamo un documento non receipt e non irrilevante, così
             * la rigenerazione percorre normalmente tutte le righe.
             */
            $document = Document::query()
                ->whereHas(
                    'documentType',
                    function ($query): void {
                        $query->whereNotIn(
                            'code',
                            [
                                'irrelevant',
                                'unknown',
                                'receipt',
                            ]
                        );
                    }
                )
                ->whereNotNull('team_id')
                ->orderBy('id')
                ->first();

            if ($document === null) {
                throw new RuntimeException(
                    'Nessun documento utilizzabile per il test.'
                );
            }

            $lineType = DocumentLineType::query()
                ->where('code', 'product')
                ->first()
                ?? DocumentLineType::query()
                    ->orderBy('id')
                    ->first();

            if ($lineType === null) {
                throw new RuntimeException(
                    'Nessun tipo riga utilizzabile per il test.'
                );
            }

            $baseLineNumber = (
                (int) DocumentLine::query()
                    ->where('document_id', $document->id)
                    ->max('line_number')
            ) + 1000;

            /*
             * Factory locale per creare righe sintetiche chiaramente
             * riconoscibili come prodotti durevoli.
             */
            $createLine = function (
                int $offset,
                string $description
            ) use (
                $document,
                $lineType,
                $baseLineNumber,
                &$createdLineIds
            ): DocumentLine {
                $line = DocumentLine::query()->create([
                    'document_id' => $document->id,
                    'document_line_type_id' =>
                        $lineType->id,
                    'line_number' =>
                        $baseLineNumber + $offset,
                    'raw_text' =>
                        $description . ' 1 x 199,90',
                    'description' => $description,
                    'quantity' => 1,
                    'unit_price' => 199.90,
                    'total_price' => 199.90,
                    'confidence_score' => 95,
                    'metadata' => [
                        'product_code_candidate' =>
                            'PV-REGEN-' . $offset,
                        'amount_consistency' => [
                            'checked' => true,
                            'is_consistent' => true,
                        ],
                    ],
                ]);

                $createdLineIds[] = (int) $line->id;

                return $line;
            };

            $confirmedLine = $createLine(
                1,
                'Notebook Regeneration Confirmed '
                    . Str::uuid()
            );

            $modifiedLine = $createLine(
                2,
                'Monitor Regeneration Modified '
                    . Str::uuid()
            );

            $declinedLine = $createLine(
                3,
                'Stampante Regeneration Declined '
                    . Str::uuid()
            );

            $automaticLine = $createLine(
                4,
                'Notebook Regeneration Automatic '
                    . Str::uuid()
            );

            /*
             * Factory locale dei candidati.
             */
            $createCandidate = function (
                DocumentLine $line,
                string $name,
                array $metadata
            ) use (
                $document,
                &$createdCandidateIds
            ): ProductIdentificationCandidate {
                $candidate =
                    ProductIdentificationCandidate::query()
                        ->create([
                            'document_id' =>
                                $document->id,
                            'document_line_id' =>
                                $line->id,
                            'product_id' => null,
                            'brand_id' => null,
                            'category_id' => null,
                            'name' => $name,
                            'model' => null,
                            'serial_number' => null,
                            'ean_code' => null,
                            'price' => 199.90,
                            'source' =>
                                'transactional_test',
                            'confidence_score' => 80,
                            'is_selected' => false,
                            'review_status' => 'pending',
                            'metadata' => $metadata,
                        ]);

                $createdCandidateIds[] =
                    (int) $candidate->id;

                return $candidate;
            };

            /*
             * Scenario 1: suggerimento accettato.
             */
            $confirmedCandidate = $createCandidate(
                line: $confirmedLine,
                name: 'Confirmed Candidate',
                metadata: [
                    'assisted_review' => [
                        'version' => 'v1',
                        'needs_user_completion' => false,
                        'completion_fields' => [],
                        'fields' => [
                            'brand' => [
                                'state' => 'confirmed',
                                'decision' => [
                                    'action' =>
                                        'accepted_suggestion',
                                    'decided_by_user_id' => 1,
                                ],
                            ],
                            'category' => [
                                'state' => 'present',
                            ],
                            'model' => [
                                'state' => 'present',
                            ],
                        ],
                    ],
                ],
            );

            /*
             * Scenario 2: valore manuale.
             */
            $modifiedCandidate = $createCandidate(
                line: $modifiedLine,
                name: 'Modified Candidate',
                metadata: [
                    'assisted_review' => [
                        'version' => 'v1',
                        'needs_user_completion' => false,
                        'completion_fields' => [],
                        'fields' => [
                            'brand' => [
                                'state' => 'present',
                            ],
                            'category' => [
                                'state' => 'present',
                            ],
                            'model' => [
                                'state' => 'modified',
                                'current' => [
                                    'value' => 'USER-MODEL',
                                ],
                                'decision' => [
                                    'action' => 'manual_value',
                                    'decided_by_user_id' => 1,
                                ],
                            ],
                        ],
                    ],
                ],
            );

            /*
             * Scenario 3: campo dichiarato non disponibile.
             */
            $declinedCandidate = $createCandidate(
                line: $declinedLine,
                name: 'Declined Candidate',
                metadata: [
                    'assisted_review' => [
                        'version' => 'v1',
                        'needs_user_completion' => false,
                        'completion_fields' => [],
                        'fields' => [
                            'brand' => [
                                'state' => 'declined',
                                'decision' => [
                                    'action' =>
                                        'marked_unavailable',
                                    'decided_by_user_id' => 1,
                                ],
                            ],
                            'category' => [
                                'state' => 'present',
                            ],
                            'model' => [
                                'state' => 'present',
                            ],
                        ],
                    ],
                ],
            );

            /*
             * Scenario 4: candidato ancora completamente automatico.
             *
             * Questo deve essere eliminato e può essere ricreato dal
             * generator usando la riga corrente.
             */
            $automaticCandidate = $createCandidate(
                line: $automaticLine,
                name: 'Automatic Candidate',
                metadata: [
                    'generator' =>
                        'product_candidate_generator_v1',
                    'assisted_review' => [
                        'version' => 'v1',
                        'needs_user_completion' => true,
                        'completion_fields' => [
                            'brand',
                            'category',
                            'model',
                        ],
                        'fields' => [
                            'brand' => [
                                'state' => 'suggested',
                            ],
                            'category' => [
                                'state' => 'missing',
                            ],
                            'model' => [
                                'state' => 'missing',
                            ],
                        ],
                    ],
                ],
            );

            $confirmedMetadataBefore =
                $confirmedCandidate->metadata;

            $modifiedMetadataBefore =
                $modifiedCandidate->metadata;

            $declinedMetadataBefore =
                $declinedCandidate->metadata;

            $automaticCandidateId =
                (int) $automaticCandidate->id;

            /*
             * Esegue la vera rigenerazione.
             */
            $generator->generate(
                $document->fresh([
                    'documentType',
                ])
            );

            $confirmedAfter =
                ProductIdentificationCandidate::query()
                    ->find($confirmedCandidate->id);

            $modifiedAfter =
                ProductIdentificationCandidate::query()
                    ->find($modifiedCandidate->id);

            $declinedAfter =
                ProductIdentificationCandidate::query()
                    ->find($declinedCandidate->id);

            $automaticAfter =
                ProductIdentificationCandidate::query()
                    ->find($automaticCandidateId);

            $assertSame(
                'confirmed_decision',
                'candidate preserved',
                true,
                $confirmedAfter !== null
            );

            $assertSame(
                'confirmed_decision',
                'metadata semantically unchanged',
                $this->canonicalizeMetadata(
                    $confirmedMetadataBefore
                ),
                $this->canonicalizeMetadata(
                    $confirmedAfter?->metadata
                )
            );

            $assertSame(
                'confirmed_decision',
                'single candidate on line',
                1,
                ProductIdentificationCandidate::query()
                    ->where(
                        'document_line_id',
                        $confirmedLine->id
                    )
                    ->count()
            );

            $assertSame(
                'modified_decision',
                'candidate preserved',
                true,
                $modifiedAfter !== null
            );

            $assertSame(
                'modified_decision',
                'metadata semantically unchanged',
                $this->canonicalizeMetadata(
                    $modifiedMetadataBefore
                ),
                $this->canonicalizeMetadata(
                    $modifiedAfter?->metadata
                )
            );

            $assertSame(
                'modified_decision',
                'single candidate on line',
                1,
                ProductIdentificationCandidate::query()
                    ->where(
                        'document_line_id',
                        $modifiedLine->id
                    )
                    ->count()
            );

            $assertSame(
                'declined_decision',
                'candidate preserved',
                true,
                $declinedAfter !== null
            );

            $assertSame(
                'declined_decision',
                'metadata semantically unchanged',
                $this->canonicalizeMetadata(
                    $declinedMetadataBefore
                ),
                $this->canonicalizeMetadata(
                    $declinedAfter?->metadata
                )
            );

            $assertSame(
                'declined_decision',
                'single candidate on line',
                1,
                ProductIdentificationCandidate::query()
                    ->where(
                        'document_line_id',
                        $declinedLine->id
                    )
                    ->count()
            );

            $assertSame(
                'automatic_candidate',
                'old candidate removed',
                null,
                $automaticAfter
            );

            $replacementCandidate =
                ProductIdentificationCandidate::query()
                    ->where(
                        'document_line_id',
                        $automaticLine->id
                    )
                    ->whereKeyNot(
                        $automaticCandidateId
                    )
                    ->first();

            $assertSame(
                'automatic_candidate',
                'replacement generated',
                true,
                $replacementCandidate !== null
            );

            /*
             * Un secondo giro non deve duplicare i candidati protetti.
             */
            $generator->generate(
                $document->fresh([
                    'documentType',
                ])
            );

            $assertSame(
                'second_regeneration',
                'confirmed candidate not duplicated',
                1,
                ProductIdentificationCandidate::query()
                    ->where(
                        'document_line_id',
                        $confirmedLine->id
                    )
                    ->count()
            );

            $assertSame(
                'second_regeneration',
                'modified candidate not duplicated',
                1,
                ProductIdentificationCandidate::query()
                    ->where(
                        'document_line_id',
                        $modifiedLine->id
                    )
                    ->count()
            );

            $assertSame(
                'second_regeneration',
                'declined candidate not duplicated',
                1,
                ProductIdentificationCandidate::query()
                    ->where(
                        'document_line_id',
                        $declinedLine->id
                    )
                    ->count()
            );
        } catch (Throwable $exception) {
            $rows[] = [
                'runtime',
                'regeneration protection completed',
                'FAIL',
            ];

            $failures[] = [
                'scenario' => 'runtime',
                'assertion' =>
                    'regeneration protection completed',
                'expected' => 'no exception',
                'actual' => $exception::class
                    . ': '
                    . $exception->getMessage(),
            ];
        } finally {
            DB::rollBack();
        }

        foreach ($createdCandidateIds as $candidateId) {
            $assertSame(
                'rollback',
                "candidate {$candidateId} removed",
                false,
                ProductIdentificationCandidate::query()
                    ->whereKey($candidateId)
                    ->exists()
            );
        }

        foreach ($createdLineIds as $lineId) {
            $assertSame(
                'rollback',
                "line {$lineId} removed",
                false,
                DocumentLine::query()
                    ->whereKey($lineId)
                    ->exists()
            );
        }

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
            'Product candidate regeneration protection checks passed.'
        );

        return self::SUCCESS;
    }

    /**
     * Normalizza ricorsivamente l'ordine delle chiavi associative.
     *
     * Gli oggetti JSON non possiedono un ordine significativo delle chiavi.
     * MySQL può quindi restituirle in un ordine differente senza aver
     * modificato il contenuto.
     *
     * Gli array numerici mantengono invece il proprio ordine, perché possono
     * rappresentare sequenze semanticamente significative.
     */
    private function canonicalizeMetadata(
        mixed $value
    ): mixed {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed =>
                    $this->canonicalizeMetadata($item),
                $value
            );
        }

        $normalized = [];

        foreach ($value as $key => $item) {
            $normalized[$key] =
                $this->canonicalizeMetadata($item);
        }

        ksort($normalized);

        return $normalized;
    }
}