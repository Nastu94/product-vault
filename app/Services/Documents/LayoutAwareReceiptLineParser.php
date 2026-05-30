<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentLine;

class LayoutAwareReceiptLineParser
{
    public function __construct(
        private readonly DocumentOcrLayoutResolver $ocrLayoutResolver
    ) {
    }

    /**
     * Estrae righe scontrino usando le visual lines OCR.
     *
     * Strategia:
     * - usa solo documenti receipt;
     * - cerca una tabella con intestazione DESCRIZIONE / IVA / IMPORTO;
     * - crea righe da visual lines tipo:
     *   CAVO USB-C 1M NYLON NERO 22% 8,90
     *
     * Non genera prodotti: crea solo DocumentLine. La decisione prodotto/non prodotto
     * resta nel ProductCandidateGenerator.
     */
    public function parse(Document $document, ?int $lineTypeId): int
    {
        if ($document->documentType?->code !== 'receipt') {
            return 0;
        }

        $layout = $this->ocrLayoutResolver->resolve($document);

        $visualLines = $layout['layout']['visual_lines'] ?? [];

        if (! is_array($visualLines) || empty($visualLines)) {
            return 0;
        }

        $visualLines = collect($visualLines)
            ->filter(fn (array $line): bool => isset($line['text']) && trim((string) $line['text']) !== '')
            ->sortBy(fn (array $line): float => (float) ($line['center_y'] ?? $line['y1'] ?? 0))
            ->values()
            ->all();

        if (! $this->visualLinesLookLikeReceiptTable($visualLines)) {
            return 0;
        }

        $created = 0;
        $tableStarted = false;

        foreach ($visualLines as $index => $visualLine) {
            $text = $this->normalizeVisualLine((string) ($visualLine['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            if ($this->visualLineLooksLikeReceiptHeader($text)) {
                $tableStarted = true;

                continue;
            }

            if (! $tableStarted) {
                continue;
            }

            if ($this->visualLineEndsReceiptTable($text)) {
                break;
            }

            $item = $this->extractReceiptVisualLineItem($text);

            if (! $item) {
                continue;
            }

            $supportingLines = $this->findSupportingVisualLines($visualLines, $index);

            DocumentLine::query()->create([
                'document_id' => $document->id,
                'document_line_type_id' => $lineTypeId,
                'line_number' => $created + 1,
                'raw_text' => trim(implode(' ', array_filter([
                    $text,
                    ...$supportingLines,
                ]))),
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'total_price' => $item['total_price'],
                'confidence_score' => $this->estimateConfidenceScore($item, $supportingLines),
                'metadata' => [
                    'parser' => 'layout_aware_receipt_line_parser_v1',
                    'mode' => 'ocr_visual_line_receipt_table',
                    'vat_rate' => $item['vat_rate'],
                    'supporting_lines' => $supportingLines,
                    'source_visual_line_id' => $visualLine['id'] ?? null,
                    'source_item_ids' => $visualLine['item_ids'] ?? [],
                    'product_code_candidate' => $this->extractProductCodeFromSupportingLines($supportingLines),
                    'serial_number_candidate' => $this->extractSerialNumberFromSupportingLines($supportingLines),
                ],
            ]);

            $created++;
        }

        return $created;
    }

    /**
     * Verifica se il layout contiene una tabella scontrino.
     */
    private function visualLinesLookLikeReceiptTable(array $visualLines): bool
    {
        foreach ($visualLines as $visualLine) {
            $text = $this->normalizeVisualLine((string) ($visualLine['text'] ?? ''));

            if ($this->visualLineLooksLikeReceiptHeader($text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Header scontrino tipo:
     * DESCRIZIONE IVA IMPORTO
     */
    private function visualLineLooksLikeReceiptHeader(string $text): bool
    {
        $normalized = mb_strtolower($text);

        return str_contains($normalized, 'descrizione')
            && preg_match('/\biva\b/u', $normalized)
            && str_contains($normalized, 'importo');
    }

    /**
     * Fine tabella articoli scontrino.
     */
    private function visualLineEndsReceiptTable(string $text): bool
    {
        $normalized = mb_strtolower($text);

        foreach ([
            'subtotale',
            'totale complessivo',
            'totale documento',
            'pagamento',
            'bancomat',
            'contanti',
            'resto',
            'aut.',
            'pos',
            'codice fedelta',
            'ean prodotto',
            'seriale',
            'matricola',
            'grazie per aver acquistato',
            'documento di test',
        ] as $signal) {
            if (str_contains($normalized, $signal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Estrae una riga articolo scontrino:
     * DESCRIZIONE IVA IMPORTO
     */
    private function extractReceiptVisualLineItem(string $line): ?array
    {
        $line = $this->normalizeVisualLine($line);

        $amountPattern = '\-?\d{1,3}(?:[.\s]\d{3})*[,.]\d{2}|\-?\d+[,.]\d{2}';

        $pattern = '/^(?<description>.+?)\s+' .
            '(?<vat>\d{1,2}(?:[,.]\d{2})?%)\s+' .
            '(?<amount>' . $amountPattern . ')\s*$/u';

        if (! preg_match($pattern, $line, $matches)) {
            return null;
        }

        $description = trim((string) ($matches['description'] ?? ''));

        if ($description === '') {
            return null;
        }

        $amount = $this->parseMoney((string) ($matches['amount'] ?? ''));

        if ($amount === null) {
            return null;
        }

        return [
            'description' => $this->cleanDescription($description),
            'quantity' => $amount >= 0 ? 1 : null,
            'unit_price' => $amount >= 0 ? $amount : null,
            'total_price' => $amount,
            'vat_rate' => trim((string) ($matches['vat'] ?? '')),
        ];
    }

    /**
     * Cerca righe tecniche immediatamente successive alla riga articolo.
     *
     * Esempio:
     * FRIGGITRICE AD ARIA 6L 22% 89,90
     * MODELLO AIRX600 NERO OPACO
     */
    private function findSupportingVisualLines(array $visualLines, int $currentIndex): array
    {
        $supportingLines = [];

        for ($offset = 1; $offset <= 2; $offset++) {
            $index = $currentIndex + $offset;

            if (! isset($visualLines[$index])) {
                break;
            }

            $text = $this->normalizeVisualLine((string) ($visualLines[$index]['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            if ($this->visualLineEndsReceiptTable($text)) {
                break;
            }

            if ($this->extractReceiptVisualLineItem($text)) {
                break;
            }

            if (! $this->lineLooksLikeSupportingMetadata($text)) {
                break;
            }

            $supportingLines[] = $text;
        }

        return $supportingLines;
    }

    /**
     * Riconosce righe tecniche associate a un prodotto.
     */
    private function lineLooksLikeSupportingMetadata(string $line): bool
    {
        $normalized = mb_strtolower($line);

        foreach ([
            'modello',
            'seriale',
            'matricola',
            'ean',
            'barcode',
            'sn-',
        ] as $signal) {
            if (str_contains($normalized, $signal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Estrae EAN/codice prodotto da righe tecniche.
     */
    private function extractProductCodeFromSupportingLines(array $supportingLines): ?string
    {
        foreach ($supportingLines as $line) {
            if (preg_match('/\b(?:EAN|COD(?:ICE)?(?:\s+PRODOTTO)?)\D*(?<code>\d{8}|\d{12}|\d{13}|\d{14})\b/iu', $line, $matches)) {
                return $matches['code'];
            }

            if (preg_match('/\bMODELLO\s+(?<code>[A-Z0-9\-]{4,})\b/iu', $line, $matches)) {
                return trim($matches['code']);
            }

            if (preg_match('/\bMATRICOLA\s+(?<code>[A-Z0-9\-]{6,})\b/iu', $line, $matches)) {
                return trim($matches['code']);
            }
        }

        return null;
    }

    /**
     * Estrae seriale da righe tecniche.
     */
    private function extractSerialNumberFromSupportingLines(array $supportingLines): ?string
    {
        foreach ($supportingLines as $line) {
            if (preg_match('/\bSN[-\s]?(?<serial>[A-Z0-9\-]{6,})\b/iu', $line, $matches)) {
                return trim($matches['serial']);
            }

            if (preg_match('/\bSERIALE\s+(?<serial>[A-Z0-9\-]{6,})\b/iu', $line, $matches)) {
                return trim($matches['serial']);
            }

            if (preg_match('/\bMATRICOLA\s+(?<serial>[A-Z0-9\-]{6,})\b/iu', $line, $matches)) {
                return trim($matches['serial']);
            }
        }

        return null;
    }

    /**
     * Pulisce descrizioni lette da OCR.
     */
    private function cleanDescription(string $description): string
    {
        $description = trim(preg_replace('/\s+/', ' ', $description) ?: $description);

        return $description;
    }

    /**
     * Normalizza visual line OCR.
     */
    private function normalizeVisualLine(string $line): string
    {
        $line = trim(preg_replace('/\s+/', ' ', $line) ?: '');

        return $line;
    }

    /**
     * Converte importi europei in float.
     */
    private function parseMoney(string $amount): ?float
    {
        $amount = trim($amount);

        if ($amount === '') {
            return null;
        }

        if (str_contains($amount, ',')) {
            $normalized = str_replace(['.', ' '], '', $amount);
            $normalized = str_replace(',', '.', $normalized);
        } else {
            $normalized = str_replace(' ', '', $amount);
        }

        if (! is_numeric($normalized)) {
            return null;
        }

        return round((float) $normalized, 2);
    }

    /**
     * Stima confidenza base della riga ricostruita da layout OCR.
     */
    private function estimateConfidenceScore(array $item, array $supportingLines): int
    {
        $score = 70;

        if (! empty($item['description'])) {
            $score += 10;
        }

        if (! empty($item['vat_rate'])) {
            $score += 5;
        }

        if (($item['total_price'] ?? null) !== null || ($item['unit_price'] ?? null) !== null) {
            $score += 10;
        }

        if (! empty($supportingLines)) {
            $score += 5;
        }

        return min($score, 100);
    }
}