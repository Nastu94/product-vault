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
            return $this->parseExplicitQuantityReceiptVisualLines(
                document: $document,
                lineTypeId: $lineTypeId,
                visualLines: $visualLines
            );
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
     * Estrae prodotti da scontrini OCR senza intestazione tabellare.
     *
     * Struttura supportata:
     *
     * DESCRIZIONE PRODOTTO
     * 1 x 79.90
     * 79.90
     *
     * La sequenza di tre righe e la coerenza quantità × unitario = totale
     * costituiscono il gate strutturale della strategia.
     */
    private function parseExplicitQuantityReceiptVisualLines(
        Document $document,
        ?int $lineTypeId,
        array $visualLines
    ): int {
        $created = 0;
        $linesCount = count($visualLines);

        for ($index = 0; $index < $linesCount; $index++) {
            $descriptionVisualLine = $visualLines[$index];

            $rawDescription = $this->normalizeVisualLine(
                (string) ($descriptionVisualLine['text'] ?? '')
            );

            if ($rawDescription === '') {
                continue;
            }

            /*
            * Dopo il totale dello scontrino non cerchiamo altri prodotti.
            */
            if ($this->visualLineEndsExplicitReceiptItems($rawDescription)) {
                break;
            }

            if (! isset(
                $visualLines[$index + 1],
                $visualLines[$index + 2]
            )) {
                continue;
            }

            $quantityVisualLine = $visualLines[$index + 1];
            $totalVisualLine = $visualLines[$index + 2];

            $quantityLine = $this->normalizeVisualLine(
                (string) ($quantityVisualLine['text'] ?? '')
            );

            $totalLine = $this->normalizeVisualLine(
                (string) ($totalVisualLine['text'] ?? '')
            );

            $item = $this->extractExplicitQuantityReceiptItem(
                description: $rawDescription,
                quantityLine: $quantityLine,
                totalLine: $totalLine
            );

            if ($item === null) {
                continue;
            }

            DocumentLine::query()->create([
                'document_id' => $document->id,
                'document_line_type_id' => $lineTypeId,
                'line_number' => $created + 1,
                'raw_text' => trim(implode(' ', [
                    $rawDescription,
                    $quantityLine,
                    $totalLine,
                ])),
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'total_price' => $item['total_price'],
                'confidence_score' => $this->estimateConfidenceScore(
                    $item,
                    []
                ),
                'metadata' => [
                    'parser' => 'layout_aware_receipt_line_parser_v2',
                    'mode' => 'ocr_visual_line_quantity_x_unit_total',
                    'vat_rate' => null,
                    'supporting_lines' => [
                        $quantityLine,
                        $totalLine,
                    ],
                    'source_visual_line_id' =>
                        $descriptionVisualLine['id'] ?? null,
                    'source_item_ids' => array_values(
                        array_unique(
                            array_filter([
                                ...($descriptionVisualLine['item_ids'] ?? []),
                                ...($quantityVisualLine['item_ids'] ?? []),
                                ...($totalVisualLine['item_ids'] ?? []),
                            ], fn ($itemId): bool => $itemId !== null)
                        )
                    ),
                    'product_code_candidate' => null,
                    'serial_number_candidate' => null,
                    'ocr_description_normalization' => [
                        'original' => $rawDescription,
                        'normalized' => $item['description'],
                        'zero_to_letter_o_applied' =>
                            $rawDescription !== $item['description'],
                    ],
                ],
            ]);

            $created++;

            /*
            * Le due righe successive appartengono già al prodotto appena creato.
            */
            $index += 2;
        }

        return $created;
    }

    /**
     * Interpreta una sequenza:
     *
     * DESCRIZIONE
     * QUANTITÀ x PREZZO_UNITARIO
     * TOTALE
     *
     * @return array{
     *     description:string,
     *     quantity:float,
     *     unit_price:float,
     *     total_price:float,
     *     vat_rate:null
     * }|null
     */
    private function extractExplicitQuantityReceiptItem(
        string $description,
        string $quantityLine,
        string $totalLine
    ): ?array {
        $amountPattern =
            '(?:\d{1,3}(?:[.\s]\d{3})*[,.]\d{2}|\d+[,.]\d{2})';

        $quantityPattern = '/^'
            . '(?<quantity>\d+(?:[,.]\d{1,3})?)'
            . '\s*[x×]\s*'
            . '(?<unit_price>' . $amountPattern . ')'
            . '\s*$/iu';

        if (! preg_match($quantityPattern, $quantityLine, $matches)) {
            return null;
        }

        if (! preg_match(
            '/^(?<total_price>' . $amountPattern . ')\s*$/u',
            $totalLine,
            $totalMatches
        )) {
            return null;
        }

        $quantity = $this->parseReceiptQuantity(
            (string) ($matches['quantity'] ?? '')
        );

        $unitPrice = $this->parseMoney(
            (string) ($matches['unit_price'] ?? '')
        );

        $totalPrice = $this->parseMoney(
            (string) ($totalMatches['total_price'] ?? '')
        );

        if (
            $quantity === null
            || $unitPrice === null
            || $totalPrice === null
            || $quantity <= 0
            || $unitPrice <= 0
            || $totalPrice <= 0
        ) {
            return null;
        }

        /*
        * Evita associazioni casuali tra descrizioni e numeri vicini.
        */
        $expectedTotal = round($quantity * $unitPrice, 2);

        if (abs($expectedTotal - $totalPrice) > 0.02) {
            return null;
        }

        $description = $this->normalizeReceiptOcrDescription(
            $description
        );

        if (
            $description === ''
            || ! preg_match('/[\p{L}]/u', $description)
        ) {
            return null;
        }

        return [
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total_price' => $totalPrice,
            'vat_rate' => null,
        ];
    }

    /**
     * Converte una quantità OCR in float.
     */
    private function parseReceiptQuantity(string $quantity): ?float
    {
        $normalized = str_replace(',', '.', trim($quantity));

        if (! is_numeric($normalized)) {
            return null;
        }

        return round((float) $normalized, 3);
    }

    /**
     * Corregge confusioni OCR molto circoscritte tra zero e lettera O.
     *
     * Vengono modificati solo token formati da lettere e zero:
     * - R0UTER  → ROUTER
     * - AER0NET → AERONET
     *
     * Token tecnici contenenti altre cifre restano invariati:
     * - AX1800
     * - 1080P
     * - 1TB
     */
    private function normalizeReceiptOcrDescription(
        string $description
    ): string {
        $description = $this->cleanDescription($description);

        return preg_replace_callback(
            '/\b[\p{L}0]{4,}\b/u',
            function (array $matches): string {
                $token = (string) ($matches[0] ?? '');

                if (
                    ! str_contains($token, '0')
                    || ! preg_match('/\p{L}/u', $token)
                ) {
                    return $token;
                }

                return str_replace('0', 'O', $token);
            },
            $description
        ) ?? $description;
    }

    /**
     * Individua la fine della sezione prodotti negli scontrini senza header.
     */
    private function visualLineEndsExplicitReceiptItems(
        string $line
    ): bool {
        $normalized = mb_strtolower(
            $this->normalizeVisualLine($line)
        );

        if (preg_match(
            '/^(?:subtotale|totale|pagamento)\b/u',
            $normalized
        )) {
            return true;
        }

        return str_contains($normalized, 'documento commerciale')
            || str_contains($normalized, 'documento di test');
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

        /*
        |--------------------------------------------------------------------------
        | Righe non prodotto
        |--------------------------------------------------------------------------
        |
        | In una tabella scontrino, importi zero o negativi rappresentano
        | normalmente sconti, coupon, storni, omaggi, arrotondamenti o righe
        | informative. Non sono prodotti acquistati da mostrare nella revisione.
        */
        if ($amount <= 0) {
            return null;
        }

        return [
            'description' => $this->cleanDescription($description),
            'quantity' => 1,
            'unit_price' => $amount,
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