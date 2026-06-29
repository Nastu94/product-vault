<?php

namespace App\Services\ProductCases;

use App\Models\Document;
use App\Models\ProductCase;
use App\Models\Warranty;
use Carbon\CarbonInterface;
use DateTimeInterface;
use JsonException;
use RuntimeException;

final class ProductCaseRequestDraftBuilder
{
    public const VERSION =
        'product_case_request_draft_v1';

    public function __construct(
        private readonly ProductCaseReadinessResolver
            $readinessResolver
    ) {
    }

    /**
     * Costruisce una bozza deterministica senza modificare la pratica.
     *
     * @return array{
     *     version: string,
     *     subject: string,
     *     body: string,
     *     body_sha256: string,
     *     source_fingerprint: string,
     *     readiness: array<string, mixed>
     * }
     *
     * @throws JsonException
     */
    public function build(
        ProductCase $productCase
    ): array {
        if (! $productCase->exists) {
            throw new RuntimeException(
                'La pratica deve essere persistita prima di generare una bozza.'
            );
        }

        /*
         * Il readiness resolver verifica anche la coerenza tra team,
         * pratica e prodotto.
         */
        $readiness =
            $this->readinessResolver->resolve(
                $productCase
            );

        $productCase->loadMissing([
            'product.brand',
            'product.merchant',
            'product.warranties.warrantyType',
            'documents.merchant',
        ]);

        $product = $productCase->product;

        if ($product === null) {
            throw new RuntimeException(
                'Il prodotto della pratica non è disponibile.'
            );
        }

        $documents = $productCase->documents
            ->sortBy('id')
            ->values();

        $photoCount = $productCase
            ->getMedia(
                ProductCase::MEDIA_COLLECTION_ISSUE_PHOTOS
            )
            ->count();

        $selectedWarrantyId = data_get(
            $readiness,
            'facts.warranty.selected_warranty_id'
        );

        $selectedWarranty = $product
            ->warranties
            ->first(function (
                Warranty $warranty
            ) use ($selectedWarrantyId): bool {
                return $selectedWarrantyId !== null
                    && (int) $warranty->id
                        === (int) $selectedWarrantyId;
            });

        $productName = $this->text(
            $product->name,
            'Prodotto non identificato'
        );

        $subject =
            'Richiesta di assistenza – '
            . $productName;

        $documentRows = $documents
            ->map(
                fn (Document $document): array =>
                    $this->documentSourceRow(
                        $document
                    )
            )
            ->all();

        $blockingItems = $this->normalizeItems(
            data_get(
                $readiness,
                'blocking_information',
                []
            )
        );

        $advisoryItems = $this->normalizeItems(
            data_get(
                $readiness,
                'advisory_information',
                []
            )
        );

        $warrantySource = [
            'available' => data_get(
                $readiness,
                'facts.warranty.available'
            ) === true,

            'selected_warranty_id' =>
                $selectedWarranty
                    ? (int) $selectedWarranty->id
                    : null,

            'type' =>
                $selectedWarranty?->warrantyType?->name,

            'starts_at' =>
                $this->date(
                    $selectedWarranty?->starts_at
                ),

            'ends_at' =>
                $this->date(
                    $selectedWarranty?->ends_at
                ),

            'duration_months' =>
                $selectedWarranty?->duration_months,

            'source' =>
                $selectedWarranty?->source,

            'coverage_state' => data_get(
                $readiness,
                'facts.warranty.coverage_state'
            ),

            'temporal_status' => data_get(
                $readiness,
                'facts.warranty.temporal_status'
            ),

            'is_estimate' => data_get(
                $readiness,
                'facts.warranty.is_estimate'
            ) === true,
        ];

        /*
         * Snapshot normalizzato delle sole informazioni che influenzano
         * il testo. Il relativo hash permette di verificare se le sorgenti
         * sono cambiate tra due generazioni.
         */
        $source = [
            'version' => self::VERSION,

            'product_case' => [
                'id' => (int) $productCase->id,
                'title' =>
                    $this->nullableText(
                        $productCase->title
                    ),
                'description' =>
                    $this->nullableText(
                        $productCase->description
                    ),
                'occurred_on' =>
                    $this->date(
                        $productCase->occurred_on
                    ),
                'usability_status' =>
                    $productCase->usability_status,
                'accidental_damage_declared' =>
                    $productCase
                        ->accidental_damage_declared,
                'accidental_damage_notes' =>
                    $this->nullableText(
                        $productCase
                            ->accidental_damage_notes
                    ),
            ],

            'product' => [
                'id' => (int) $product->id,
                'name' =>
                    $this->nullableText(
                        $product->name
                    ),
                'brand' =>
                    $this->nullableText(
                        $product->brand?->name
                    ),
                'model' =>
                    $this->nullableText(
                        $product->model
                    ),
                'serial_number' =>
                    $this->nullableText(
                        $product->serial_number
                    ),
                'ean_code' =>
                    $this->nullableText(
                        $product->ean_code
                    ),
                'merchant' =>
                    $this->nullableText(
                        $product->merchant?->name
                    ),
                'purchase_date' =>
                    $this->date(
                        $product->purchase_date
                    ),
            ],

            'documents' => $documentRows,

            'photo_count' => $photoCount,

            'warranty' => $warrantySource,

            'blocking_codes' => array_column(
                $blockingItems,
                'code'
            ),

            'advisory_codes' => array_column(
                $advisoryItems,
                'code'
            ),
        ];

        $body = $this->buildBody(
            subject: $subject,
            productCase: $productCase,
            documents: $documents->all(),
            photoCount: $photoCount,
            selectedWarranty: $selectedWarranty,
            warrantySource: $warrantySource,
            blockingItems: $blockingItems,
            advisoryItems: $advisoryItems,
        );

        $sourceJson = json_encode(
            $source,
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
        );

        return [
            'version' => self::VERSION,
            'subject' => $subject,
            'body' => $body,
            'body_sha256' =>
                hash('sha256', $body),
            'source_fingerprint' =>
                hash('sha256', $sourceJson),

            'readiness' => [
                'is_ready_to_contact' =>
                    data_get(
                        $readiness,
                        'is_ready_to_contact'
                    ) === true,

                'blocking_codes' =>
                    array_column(
                        $blockingItems,
                        'code'
                    ),

                'advisory_codes' =>
                    array_column(
                        $advisoryItems,
                        'code'
                    ),
            ],
        ];
    }

    /**
     * @param  array<int, Document>  $documents
     * @param  array<string, mixed>  $warrantySource
     * @param  list<array{code: string, label: string}>  $blockingItems
     * @param  list<array{code: string, label: string}>  $advisoryItems
     */
    private function buildBody(
        string $subject,
        ProductCase $productCase,
        array $documents,
        int $photoCount,
        ?Warranty $selectedWarranty,
        array $warrantySource,
        array $blockingItems,
        array $advisoryItems
    ): string {
        $product = $productCase->product;

        if ($product === null) {
            throw new RuntimeException(
                'Il prodotto della pratica non è disponibile.'
            );
        }

        $lines = [
            'Oggetto: ' . $subject,
            '',
            'Buongiorno,',
            '',
            'desidero richiedere assistenza per il seguente prodotto.',
            '',
            'PRODOTTO',
            '- Nome: '
                . $this->text(
                    $product->name
                ),
            '- Marca: '
                . $this->text(
                    $product->brand?->name
                ),
            '- Modello: '
                . $this->text(
                    $product->model
                ),
            '- Numero seriale: '
                . $this->text(
                    $product->serial_number
                ),
            '- Codice EAN: '
                . $this->text(
                    $product->ean_code
                ),
            '- Venditore: '
                . $this->text(
                    $product->merchant?->name
                ),
            '- Data di acquisto: '
                . $this->text(
                    $this->date(
                        $product->purchase_date
                    )
                ),
            '',
            'PROBLEMA',
            '- Titolo: '
                . $this->text(
                    $productCase->title
                ),
            '- Descrizione: '
                . $this->text(
                    $productCase->description
                ),
            '- Data del problema: '
                . $this->text(
                    $this->date(
                        $productCase->occurred_on
                    )
                ),
            '- Utilizzabilità attuale: '
                . $this->usabilityLabel(
                    $productCase->usability_status
                ),
            '- Danno accidentale dichiarato: '
                . $this->booleanLabel(
                    $productCase
                        ->accidental_damage_declared
                ),
        ];

        if (
            $productCase
                ->accidental_damage_declared === true
        ) {
            $lines[] =
                '- Dettagli del danno accidentale: '
                . $this->text(
                    $productCase
                        ->accidental_damage_notes
                );
        }

        $lines[] = '';
        $lines[] = 'DOCUMENTAZIONE';

        if ($documents === []) {
            $lines[] =
                '- Nessun documento selezionato.';
        } else {
            foreach ($documents as $document) {
                $parts = [
                    $this->text(
                        $document->original_filename,
                        'Documento #'
                            . $document->id
                    ),
                ];

                if ($document->purchase_date !== null) {
                    $parts[] =
                        'data: '
                        . $this->date(
                            $document->purchase_date
                        );
                }

                if (
                    $this->nullableText(
                        $document->merchant?->name
                    ) !== null
                ) {
                    $parts[] =
                        'venditore: '
                        . $this->text(
                            $document->merchant?->name
                        );
                }

                $pivotNotes = $this->nullableText(
                    $document->pivot?->notes
                );

                if ($pivotNotes !== null) {
                    $parts[] =
                        'nota: '
                        . $pivotNotes;
                }

                $lines[] =
                    '- '
                    . implode(
                        ' | ',
                        $parts
                    );
            }
        }

        $lines[] =
            '- Fotografie allegate: '
            . $photoCount;

        $lines[] = '';
        $lines[] = 'GARANZIA';

        if (
            ($warrantySource['available'] ?? false)
                !== true
            || $selectedWarranty === null
        ) {
            $lines[] =
                '- Contesto garanzia non disponibile.';
        } else {
            $lines[] =
                '- Tipo: '
                . $this->text(
                    $selectedWarranty
                        ->warrantyType?->name
                );

            $lines[] =
                '- Stato della copertura: '
                . $this->coverageStateLabel(
                    $warrantySource[
                        'coverage_state'
                    ] ?? null
                );

            $lines[] =
                '- Stato temporale: '
                . $this->temporalStatusLabel(
                    $warrantySource[
                        'temporal_status'
                    ] ?? null
                );

            $lines[] =
                '- Periodo indicato: '
                . $this->periodLabel(
                    $selectedWarranty
                );

            $lines[] =
                '- Origine del dato: '
                . $this->text(
                    $selectedWarranty->source
                );
        }

        $lines[] = '';
        $lines[] = 'INFORMAZIONI DA VERIFICARE';

        if ($blockingItems === []) {
            $lines[] =
                '- Nessuna informazione bloccante.';
        } else {
            foreach ($blockingItems as $item) {
                $lines[] =
                    '- Da completare: '
                    . $item['label'];
            }
        }

        foreach ($advisoryItems as $item) {
            $lines[] =
                '- Avvertenza: '
                . $item['label'];
        }

        $lines[] = '';
        $lines[] = 'RICHIESTA';
        $lines[] =
            'Chiedo di indicarmi le modalità disponibili per la verifica del problema e per la relativa assistenza.';
        $lines[] = '';
        $lines[] =
            'Questa è una bozza modificabile. Il riepilogo della garanzia ha finalità informativa e non costituisce una decisione automatica sulla copertura.';
        $lines[] = '';
        $lines[] = 'Cordiali saluti';

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    private function documentSourceRow(
        Document $document
    ): array {
        return [
            'id' => (int) $document->id,
            'filename' =>
                $this->nullableText(
                    $document->original_filename
                ),
            'purchase_date' =>
                $this->date(
                    $document->purchase_date
                ),
            'merchant' =>
                $this->nullableText(
                    $document->merchant?->name
                ),
            'notes' =>
                $this->nullableText(
                    $document->pivot?->notes
                ),
        ];
    }

    /**
     * @param  mixed  $items
     * @return list<array{code: string, label: string}>
     */
    private function normalizeItems(
        mixed $items
    ): array {
        if (! is_array($items)) {
            return [];
        }

        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $code = $this->nullableText(
                $item['code'] ?? null
            );

            $label = $this->nullableText(
                $item['label'] ?? null
            );

            if (
                $code === null
                || $label === null
            ) {
                continue;
            }

            $normalized[] = [
                'code' => $code,
                'label' => $label,
            ];
        }

        usort(
            $normalized,
            fn (array $left, array $right): int =>
                $left['code']
                    <=> $right['code']
        );

        return $normalized;
    }

    private function usabilityLabel(
        mixed $value
    ): string {
        return match ($value) {
            ProductCase::USABILITY_USABLE =>
                'Utilizzabile',

            ProductCase::USABILITY_PARTIALLY_USABLE =>
                'Parzialmente utilizzabile',

            ProductCase::USABILITY_UNUSABLE =>
                'Non utilizzabile',

            default =>
                'Da indicare',
        };
    }

    private function booleanLabel(
        ?bool $value
    ): string {
        return match ($value) {
            true => 'Sì',
            false => 'No',
            null => 'Da indicare',
        };
    }

    private function coverageStateLabel(
        mixed $value
    ): string {
        return match ($value) {
            'estimated' =>
                'Copertura stimata',

            'declared' =>
                'Copertura dichiarata',

            'user_confirmed' =>
                'Copertura confermata dall’utente',

            'verified' =>
                'Copertura verificata',

            'cancelled' =>
                'Copertura annullata',

            default =>
                'Copertura da verificare',
        };
    }

    private function temporalStatusLabel(
        mixed $value
    ): string {
        return match ($value) {
            'active' =>
                'Nel periodo indicato',

            'expiring' =>
                'In scadenza',

            'expired' =>
                'Periodo terminato',

            'not_started' =>
                'Non ancora iniziata',

            default =>
                'Periodo non calcolabile',
        };
    }

    private function periodLabel(
        Warranty $warranty
    ): string {
        $startsAt = $this->date(
            $warranty->starts_at
        );

        $endsAt = $this->date(
            $warranty->ends_at
        );

        if (
            $startsAt === null
            && $endsAt === null
        ) {
            return 'Non disponibile';
        }

        return $this->text($startsAt)
            . ' – '
            . $this->text($endsAt);
    }

    private function text(
        mixed $value,
        string $fallback =
            'Non disponibile'
    ): string {
        return $this->nullableText($value)
            ?? $fallback;
    }

    private function nullableText(
        mixed $value
    ): ?string {
        if (
            $value === null
            || is_array($value)
            || is_object($value)
        ) {
            return null;
        }

        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        $normalized = preg_replace(
            '/\s+/u',
            ' ',
            $text
        );

        return is_string($normalized)
            && $normalized !== ''
                ? $normalized
                : $text;
    }

    private function date(
        mixed $value
    ): ?string {
        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return $this->nullableText($value);
    }
}