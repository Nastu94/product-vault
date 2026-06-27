<?php

namespace App\Console\Commands\ProductVault;

use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\ProductIdentificationCandidate;
use App\Services\Documents\ProductConfirmation\ProductConfirmationFieldTransferPolicy;
use App\Services\Documents\ProductConfirmation\ProductConfirmationProvenanceSnapshotBuilder;
use Illuminate\Console\Command;

class TestProductConfirmationProvenanceSnapshotCommand extends Command
{
    /**
     * Nome e argomenti del comando.
     *
     * @var string
     */
    protected $signature =
        'product-vault:test-product-confirmation-provenance-snapshot';

    /**
     * Descrizione del comando.
     *
     * @var string
     */
    protected $description =
        'Verifica lo snapshot read-only della provenienza Candidate → Product.';

    /**
     * Esegue il test senza scrivere nel database.
     */
    public function handle(
        ProductConfirmationFieldTransferPolicy $fieldTransferPolicy,
        ProductConfirmationProvenanceSnapshotBuilder $snapshotBuilder
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
         * Documento e riga esclusivamente in memoria.
         */
        $document = new Document();
        $document->id = 501;
        $document->team_id = 10;
        $document->merchant_id = 20;
        $document->currency_id = 1;
        $document->document_type_id = 3;
        $document->original_filename =
            'provenance-test.pdf';
        $document->purchase_date = '2026-06-26';

        $line = new DocumentLine();
        $line->id = 601;
        $line->document_id = 501;
        $line->document_line_type_id = 1;
        $line->line_number = 4;
        $line->raw_text =
            'Router NetworkPro AX3000 1 x 129,90';
        $line->description =
            'Router NetworkPro AX3000';
        $line->quantity = '1.000';
        $line->unit_price = '129.90';
        $line->total_price = '129.90';
        $line->confidence_score = 91;
        $line->metadata = [
            'product_code_candidate' => 'NP-ROUTER-01',
            'serial_number_candidate' => null,
            'manual_review' => [
                'reviewed' => true,
                'reviewed_by_user_id' => 7,
            ],
            'document_line_amount_consistency' => [
                'checked' => true,
                'is_consistent' => true,
            ],
            'technical_namespace_not_needed' => [
                'preserve_elsewhere' => true,
            ],
        ];

        /*
         * Brand suggerito e modello tecnico inaffidabile restano nelle
         * evidenze, ma non vengono trasferiti nel prodotto.
         */
        $candidate =
            new ProductIdentificationCandidate();

        $candidate->id = 701;
        $candidate->document_id = 501;
        $candidate->document_line_id = 601;
        $candidate->brand_id = 90;
        $candidate->category_id = 12;
        $candidate->name =
            'Router NetworkPro AX3000';
        $candidate->model = 'AX3000';
        $candidate->serial_number = null;
        $candidate->ean_code = '8050000000701';
        $candidate->price = '129.90';
        $candidate->source = 'document_line';
        $candidate->confidence_score = 84;
        $candidate->review_status = 'pending';
        $candidate->metadata = [
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
                        'current' => [
                            'value' => 'NetworkPro',
                        ],
                        'suggestion' => [
                            'value' => 'NetworkPro',
                        ],
                    ],
                    'category' => [
                        'state' => 'present',
                        'current' => [
                            'value' => 'Networking',
                            'ref' => [
                                'id' => 12,
                            ],
                        ],
                    ],
                    'model' => [
                        'state' => 'missing',
                        'current' => [
                            'value' => 'AX3000',
                        ],
                        'issues' => [
                            'technical_specification_used_as_model',
                        ],
                    ],
                ],
            ],
            'technical_namespace' => [
                'not_copied_wholesale' => true,
            ],
        ];

        $candidate->setRelation(
            'document',
            $document
        );

        $candidate->setRelation(
            'documentLine',
            $line
        );

        $before = [
            'candidate' => $candidate->getAttributes(),
            'document' => $document->getAttributes(),
            'line' => $line->getAttributes(),
        ];

        $fieldTransfer = $fieldTransferPolicy->resolve(
            $candidate
        );

        $firstSnapshot = $snapshotBuilder->build(
            candidate: $candidate,
            fieldTransfer: $fieldTransfer,
        );

        $secondSnapshot = $snapshotBuilder->build(
            candidate: $candidate,
            fieldTransfer: $fieldTransfer,
        );

        $after = [
            'candidate' => $candidate->getAttributes(),
            'document' => $document->getAttributes(),
            'line' => $line->getAttributes(),
        ];

        $assertSame(
            'contract',
            'version',
            'product_confirmation_provenance_v1',
            $firstSnapshot['version']
        );

        $assertSame(
            'references',
            'candidate id',
            701,
            $firstSnapshot[
                'references'
            ]['candidate_id']
        );

        $assertSame(
            'references',
            'document line id',
            601,
            $firstSnapshot[
                'references'
            ]['document_line_id']
        );

        $assertSame(
            'candidate_evidence',
            'raw model preserved',
            'AX3000',
            $firstSnapshot['candidate']['model']
        );

        $assertSame(
            'resolved_values',
            'brand excluded',
            null,
            $firstSnapshot[
                'resolved_product_values'
            ]['brand_id']
        );

        $assertSame(
            'resolved_values',
            'category transferred',
            12,
            $firstSnapshot[
                'resolved_product_values'
            ]['category_id']
        );

        $assertSame(
            'resolved_values',
            'model excluded',
            null,
            $firstSnapshot[
                'resolved_product_values'
            ]['model']
        );

        $assertSame(
            'field_transfer',
            'brand reason',
            'suggestion_not_confirmed',
            $firstSnapshot[
                'field_transfer'
            ]['fields']['brand']['reason']
        );

        $assertSame(
            'field_transfer',
            'model reason',
            'optional_field_missing',
            $firstSnapshot[
                'field_transfer'
            ]['fields']['model']['reason']
        );

        $assertSame(
            'line_evidence',
            'raw text',
            'Router NetworkPro AX3000 1 x 129,90',
            $firstSnapshot[
                'document_line'
            ]['raw_text']
        );

        $assertSame(
            'line_evidence',
            'raw text hash',
            hash(
                'sha256',
                'Router NetworkPro AX3000 1 x 129,90'
            ),
            $firstSnapshot[
                'document_line'
            ]['raw_text_hash']
        );

        $assertSame(
            'line_evidence',
            'amount consistency preserved',
            true,
            $firstSnapshot[
                'document_line'
            ]['evidence'][
                'amount_consistency'
            ]['is_consistent']
        );

        $assertSame(
            'metadata_scope',
            'assisted review preserved',
            'v1',
            $firstSnapshot[
                'assisted_review'
            ]['version']
        );

        $assertSame(
            'metadata_scope',
            'unrelated candidate metadata excluded',
            false,
            array_key_exists(
                'technical_namespace',
                $firstSnapshot
            )
        );

        $assertSame(
            'idempotence',
            'same snapshot',
            $firstSnapshot,
            $secondSnapshot
        );

        $assertSame(
            'read_only',
            'models unchanged',
            $before,
            $after
        );

        /*
         * Un candidato privo di riga resta rappresentabile.
         */
        $candidateWithoutLine =
            new ProductIdentificationCandidate();

        $candidateWithoutLine->name =
            'Legacy Product';
        $candidateWithoutLine->brand_id = 4;
        $candidateWithoutLine->category_id = 5;
        $candidateWithoutLine->model = 'LEGACY-1';
        $candidateWithoutLine->metadata = [];

        $candidateWithoutLine->setRelation(
            'document',
            null
        );

        $candidateWithoutLine->setRelation(
            'documentLine',
            null
        );

        $legacyTransfer = $fieldTransferPolicy->resolve(
            $candidateWithoutLine
        );

        $legacySnapshot = $snapshotBuilder->build(
            candidate: $candidateWithoutLine,
            fieldTransfer: $legacyTransfer,
        );

        $assertSame(
            'without_line',
            'line snapshot absent',
            null,
            $legacySnapshot['document_line']
        );

        $assertSame(
            'without_line',
            'legacy mode preserved',
            'legacy_passthrough',
            $legacySnapshot[
                'field_transfer'
            ]['mode']
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
            'Product confirmation provenance snapshot checks passed.'
        );

        return self::SUCCESS;
    }
}