<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\DocumentTextExtraction;

class LayoutAwareInvoiceLineParser
{
    public function __construct(
        private readonly DocumentOcrLayoutResolver $ocrLayoutResolver
    ) {
    }

    /**
     * Estrae righe fattura usando coordinate OCR.
     *
     * Questo parser non sostituisce il parser testuale:
     * viene usato solo quando sono disponibili ocr_items.
     */
    public function parse(Document $document, ?int $lineTypeId): int
    {
        $layout = $this->ocrLayoutResolver->resolve($document);

        $items = $layout['items'] ?? [];
        $metadata = $layout['metadata'] ?? [];

        if (! is_array($items) || empty($items)) {
            return 0;
        }

        $visualLineCreated = $this->parseCompactInvoiceVisualLines(
            document: $document,
            lineTypeId: $lineTypeId,
            visualLines: $layout['layout']['visual_lines'] ?? []
        );

        if ($visualLineCreated > 0) {
            return $visualLineCreated;
        }

        $columns = $this->detectColumns($items, $metadata);

        if (! $columns) {
            return 0;
        }

        $bounds = $this->detectTableBounds($items, $columns);

        $codeItems = $this->findInvoiceCodeItems($items, $columns, $bounds);

        if (empty($codeItems)) {
            return 0;
        }

        $amountYOffset = $this->detectAmountYOffset(
            items: $items,
            codeItems: $codeItems,
            columns: $columns,
            bounds: $bounds
        );

        $created = 0;

        foreach ($codeItems as $index => $codeItem) {
            if ($this->codeShouldBeIgnored((string) ($codeItem['text'] ?? ''))) {
                continue;
            }

            $rowBounds = $this->rowBoundsForCodeItem($codeItems, $index, $bounds);

            $row = $this->buildRowFromCodeItem(
                items: $items,
                codeItem: $codeItem,
                columns: $columns,
                bounds: $bounds,
                rowBounds: $rowBounds,
                amountYOffset: $amountYOffset
            );

            if (! $row) {
                continue;
            }

            DocumentLine::query()->create([
                'document_id' => $document->id,
                'document_line_type_id' => $lineTypeId,
                'line_number' => $created + 1,
                'raw_text' => $row['raw_text'],
                'description' => $row['description'],
                'quantity' => $row['quantity'],
                'unit_price' => $row['unit_price'],
                'total_price' => $row['total_price'],
                'confidence_score' => $row['confidence_score'],
                'metadata' => [
                    'parser' => 'layout_aware_invoice_line_parser_v1',
                    'mode' => 'ocr_items_columns',
                    'invoice_code' => $row['invoice_code'],
                    'product_code_candidate' => $row['product_code'],
                    'serial_number_candidate' => $row['serial_number'] ?? null,
                    'discount_amount' => $row['discount_amount'],
                    'vat_rate' => $row['vat_rate'],
                    'supporting_lines' => $row['supporting_lines'],
                    'source_item_ids' => $row['source_item_ids'],
                    'columns' => $row['columns'],
                ],
            ]);

            $created++;
        }

        return $created;
    }

    /**
     * Prova a parsare fatture compatte direttamente dalle visual lines OCR.
     *
     * Strategia generale per layout:
     * CODICE DESCRIZIONE QTA PREZZO IVA TOTALE
     *
     * È un parser ibrido: usa il layout OCR solo per ricostruire righe visive,
     * poi applica parsing testuale tabellare.
     */
    private function parseCompactInvoiceVisualLines(Document $document, ?int $lineTypeId, array $visualLines): int
    {
        if (empty($visualLines)) {
            return 0;
        }

        $visualLines = collect($visualLines)
            ->filter(fn (array $line): bool => isset($line['text']) && trim((string) $line['text']) !== '')
            ->sortBy(fn (array $line): float => (float) ($line['center_y'] ?? $line['y1'] ?? 0))
            ->values()
            ->all();

        if (! $this->visualLinesLookLikeCompactInvoiceTable($visualLines)) {
            return 0;
        }

        $created = 0;
        $tableStarted = false;

        foreach ($visualLines as $index => $visualLine) {
            $text = $this->normalizeCompactOcrLine((string) ($visualLine['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            if ($this->visualLineLooksLikeCompactInvoiceHeader($text)) {
                $tableStarted = true;
                continue;
            }

            if (! $tableStarted) {
                continue;
            }

            if ($this->visualLineEndsCompactInvoiceTable($text)) {
                break;
            }

            $item = $this->extractCompactInvoiceVisualLineItem($text);
            $standaloneCodeLine = null;

            /*
            |--------------------------------------------------------------------------
            | Riga OCR senza codice e senza IVA
            |--------------------------------------------------------------------------
            |
            | Alcune scansioni vengono ricostruite in questo ordine:
            |
            | ROBOT LAVAPAVIMENTI AQUABOT X2 1 549.00 549.00
            | AB-X2
            |
            | La visual line contiene descrizione, quantità, unitario e totale,
            | mentre il codice si trova nella riga immediatamente successiva.
            |
            */
            if (! $item) {
                $standaloneCodeLine = $this->findFollowingStandaloneInvoiceCodeLine(
                    visualLines: $visualLines,
                    currentIndex: $index
                );

                if ($standaloneCodeLine !== null) {
                    $item = $this->extractCompactInvoiceVisualLineItemWithoutInlineCodeAndVat(
                        line: $text,
                        invoiceCode: $standaloneCodeLine['code']
                    );
                }
            }

            if (! $item) {
                continue;
            }

            $supportingLines = $this->findSupportingVisualLines(
                $visualLines,
                $index
            );

            if (! empty($supportingLines)) {
                $item['supporting_lines'] = $supportingLines;

                $ean = $this->extractEanFromSupportingLines($supportingLines);

                if ($ean) {
                    $item['product_code'] = $ean;
                }
            }

            DocumentLine::query()->create([
                'document_id' => $document->id,
                'document_line_type_id' => $lineTypeId,
                'line_number' => $created + 1,
                'raw_text' => trim(implode(' ', array_filter([
                    $standaloneCodeLine['code'] ?? null,
                    $text,
                    ...($supportingLines ?? []),
                ]))),
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'total_price' => $item['total_price'],
                'confidence_score' => $this->estimateConfidenceScore(
                    description: $item['description'],
                    invoiceCode: $item['invoice_code'],
                    quantity: $item['quantity'],
                    unitPrice: $item['unit_price'],
                    totalPrice: $item['total_price'],
                    vatRate: $item['vat_rate']
                ),
                'metadata' => [
                    'parser' => 'layout_aware_invoice_line_parser_v2',
                    'mode' => 'ocr_visual_line_compact',
                    'invoice_code' => $item['invoice_code'],
                    'product_code_candidate' => $item['product_code'],
                    'serial_number_candidate' => $this->extractSerialNumberFromSupportingLines($supportingLines ?? []),
                    'discount_amount' => $item['discount_amount'],
                    'vat_rate' => $item['vat_rate'],
                    'supporting_lines' => $supportingLines ?? [],
                    'source_visual_line_id' => $visualLine['id'] ?? null,
                    'source_item_ids' => array_values(array_unique(array_filter([
                        ...($visualLine['item_ids'] ?? []),
                        ...($standaloneCodeLine['item_ids'] ?? []),
                    ], fn ($itemId): bool => $itemId !== null))),
                    'source_code_visual_line_id' => $standaloneCodeLine['id'] ?? null,
                    'inference' => $item['inference'] ?? null,
                ],
            ]);

            $created++;
        }

        return $created;
    }

    /**
     * Capisce se le visual lines contengono una tabella compatta.
     */
    private function visualLinesLookLikeCompactInvoiceTable(array $visualLines): bool
    {
        foreach ($visualLines as $visualLine) {
            $text = $this->normalizeCompactOcrLine((string) ($visualLine['text'] ?? ''));

            if ($this->visualLineLooksLikeCompactInvoiceHeader($text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Esclude righe contabili/note dal parser layout-aware.
     */
    private function invoiceCodeShouldBeSkippedAsDocumentLine(?string $code): bool
    {
        $code = mb_strtoupper(trim((string) $code));

        if ($code === '') {
            return false;
        }

        $blockedCodes = [
            'SCONTO',
            'NOTA',
            'NOTE',
            'ACCONTO',
            'TOTALE',
            'SUBTOTALE',
            'RIEPILOGO',
            'IMPORTO',
        ];

        return in_array($code, $blockedCodes, true);
    }

    /**
     * Header compatto tipo:
     * CODICE DESCRIZIONE QTA PREZZO IVA TOTALE
     */
    private function visualLineLooksLikeCompactInvoiceHeader(string $text): bool
    {
        $normalized = mb_strtolower($text);

        if (! str_contains($normalized, 'codice')) {
            return false;
        }

        if (! str_contains($normalized, 'descrizione')) {
            return false;
        }

        $columnSignals = 0;

        foreach ([
            'qta',
            'qtà',
            'quantita',
            'quantità',
            'prezzo',
            'iva',
            'totale',
            'imponibile',
        ] as $signal) {
            if (str_contains($normalized, $signal)) {
                $columnSignals++;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Header OCR parziale
        |--------------------------------------------------------------------------
        |
        | Su foto inclinate o layout compatti PaddleOCR può spezzare l'header
        | e restituire solo "CODICE DESCRIZIONE QTA".
        |
        | Non pretendiamo tutte le colonne: ci basta capire che siamo all'inizio
        | di una tabella articoli. Le singole righe saranno comunque validate
        | dai parser riga.
        |
        */
        return $columnSignals >= 1;
    }

    /**
     * Riconosce la fine della tabella prodotti.
     */
    private function visualLineEndsCompactInvoiceTable(string $text): bool
    {
        $normalized = mb_strtolower($text);

        foreach ([
            'riepilogo',
            'subtotale',
            'totale iva',
            'totale documento',
            'totale fattura',
            'importo pagato',
            'netto a pagare',
            'documento di test',
            'non utilizzabile',
        ] as $signal) {
            if (str_contains($normalized, $signal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Estrae item da una visual line compatta.
     */
    private function extractCompactInvoiceVisualLineItem(string $line): ?array
    {
        $line = $this->normalizeCompactOcrLine($line);

        return $this->extractCompactInvoiceVisualLineItemFull($line)
            ?? $this->extractCompactInvoiceVisualLineItemMissingTotal($line)
            ?? $this->extractCompactInvoiceVisualLineItemMissingQuantity($line);
    }

    /**
     * Caso completo:
     * CODE DESCRIPTION QTA UNIT VAT TOTAL
     */
    private function extractCompactInvoiceVisualLineItemFull(string $line): ?array
    {
        $amountPattern = $this->ocrAmountPattern();

        $pattern = '/^(?<code>' . $this->ocrInvoiceCodePattern() . ')\s+' .
            '(?<description>.+?)\s+' .
            '(?<quantity>\d+(?:[,.]\d+)?)\s+' .
            '(?<unit_price>' . $amountPattern . ')\s+' .
            '(?<vat>\d{1,2}(?:[,.]\d{2})?%)\s+' .
            '(?<total_price>' . $amountPattern . ')\s*$/u';

        if (! preg_match($pattern, $line, $matches)) {
            return null;
        }

        return $this->buildCompactInvoiceItemFromMatches($matches, [
            'type' => 'full',
        ]);
    }

    /**
     * Caso OCR senza totale finale:
     * CODE DESCRIPTION QTA UNIT VAT
     *
     * Se quantità e prezzo unitario sono presenti, il totale viene inferito.
     */
    private function extractCompactInvoiceVisualLineItemMissingTotal(string $line): ?array
    {
        $amountPattern = $this->ocrAmountPattern();

        $pattern = '/^(?<code>' . $this->ocrInvoiceCodePattern() . ')\s+' .
            '(?<description>.+?)\s+' .
            '(?<quantity>\d+(?:[,.]\d+)?)\s+' .
            '(?<unit_price>' . $amountPattern . ')\s+' .
            '(?<vat>\d{1,2}(?:[,.]\d{2})?%)\s*$/u';

        if (! preg_match($pattern, $line, $matches)) {
            return null;
        }

        $quantity = $this->parseQuantity($matches['quantity']);
        $unitPrice = $this->parseMoney($matches['unit_price']);

        if ($quantity === null || $unitPrice === null) {
            return null;
        }

        $matches['total_price'] = (string) round($quantity * $unitPrice, 2);

        return $this->buildCompactInvoiceItemFromMatches($matches, [
            'type' => 'total_inferred_from_quantity_x_unit_price',
        ]);
    }

    /**
     * Caso OCR senza quantità:
     * CODE DESCRIPTION UNIT VAT TOTAL
     *
     * Se il rapporto totale/prezzo unitario è plausibile, inferiamo la quantità.
     */
    private function extractCompactInvoiceVisualLineItemMissingQuantity(string $line): ?array
    {
        $amountPattern = $this->ocrAmountPattern();

        $pattern = '/^(?<code>' . $this->ocrInvoiceCodePattern() . ')\s+' .
            '(?<description>.+?)\s+' .
            '(?<unit_price>' . $amountPattern . ')\s+' .
            '(?<vat>\d{1,2}(?:[,.]\d{2})?%)\s+' .
            '(?<total_price>' . $amountPattern . ')\s*$/u';

        if (! preg_match($pattern, $line, $matches)) {
            return null;
        }

        $unitPrice = $this->parseMoney($matches['unit_price']);
        $totalPrice = $this->parseMoney($matches['total_price']);

        if ($unitPrice === null || $unitPrice == 0.0 || $totalPrice === null) {
            return null;
        }

        $quantity = round($totalPrice / $unitPrice, 3);

        /*
        |--------------------------------------------------------------------------
        | Inferenza prudente
        |--------------------------------------------------------------------------
        |
        | Accettiamo quantità inferite solo se vicine a un intero o comunque
        | ragionevoli. Evita di inventare quantità da righe riepilogo o note.
        |
        */
        if ($quantity <= 0 || $quantity > 999) {
            return null;
        }

        $matches['quantity'] = (string) $quantity;

        return $this->buildCompactInvoiceItemFromMatches($matches, [
            'type' => 'quantity_inferred_from_total_divided_by_unit_price',
        ]);
    }

    /**
     * Cerca un codice articolo nella visual line immediatamente successiva.
     *
     * La ricerca è volutamente limitata a una sola riga per evitare di
     * associare al prodotto corrente il codice di un prodotto più distante.
     *
     * @return array{
     *     code:string,
     *     id:mixed,
     *     item_ids:array<int, mixed>
     * }|null
     */
    private function findFollowingStandaloneInvoiceCodeLine(
        array $visualLines,
        int $currentIndex
    ): ?array {
        $nextIndex = $currentIndex + 1;

        if (! isset($visualLines[$nextIndex])) {
            return null;
        }

        $visualLine = $visualLines[$nextIndex];

        $text = $this->normalizeCompactOcrLine(
            (string) ($visualLine['text'] ?? '')
        );

        if ($text === '') {
            return null;
        }

        if (! preg_match(
            '/^(?<code>' . $this->ocrInvoiceCodePattern() . ')$/u',
            $text,
            $matches
        )) {
            return null;
        }

        $code = $this->normalizeInvoiceCode(
            (string) ($matches['code'] ?? '')
        );

        if (
            $code === ''
            || $this->invoiceCodeShouldBeSkippedAsDocumentLine($code)
        ) {
            return null;
        }

        return [
            'code' => $code,
            'id' => $visualLine['id'] ?? null,
            'item_ids' => $visualLine['item_ids'] ?? [],
        ];
    }

    /**
     * Estrae una riga OCR compatta priva di codice inline e colonna IVA.
     *
     * Struttura:
     * DESCRIZIONE QTA PREZZO_UNITARIO TOTALE
     *
     * Il codice viene recuperato dalla visual line immediatamente successiva.
     */
    private function extractCompactInvoiceVisualLineItemWithoutInlineCodeAndVat(
        string $line,
        string $invoiceCode
    ): ?array {
        $line = $this->normalizeCompactOcrLine($line);
        $amountPattern = '(?:' . $this->ocrAmountPattern() . ')';

        $pattern = '/^(?<description>.+?)\s+'
            . '(?<quantity>\d+(?:[,.]\d+)?)\s+'
            . '(?<unit_price>' . $amountPattern . ')\s+'
            . '(?<total_price>' . $amountPattern . ')'
            . '\s*$/u';

        if (! preg_match($pattern, $line, $matches)) {
            return null;
        }

        $description = trim((string) ($matches['description'] ?? ''));
        $quantity = $this->parseQuantity(
            (string) ($matches['quantity'] ?? '')
        );
        $unitPrice = $this->parseMoney(
            (string) ($matches['unit_price'] ?? '')
        );
        $totalPrice = $this->parseMoney(
            (string) ($matches['total_price'] ?? '')
        );

        if (
            $description === ''
            || $this->descriptionShouldBeIgnored($description)
            || $quantity === null
            || $unitPrice === null
            || $totalPrice === null
            || $quantity <= 0
            || $unitPrice <= 0
            || $totalPrice <= 0
        ) {
            return null;
        }

        /*
        * La coerenza economica è il gate che impedisce di interpretare
        * come prodotto una generica sequenza descrizione + numeri.
        */
        $expectedTotal = round($quantity * $unitPrice, 2);

        if (abs($expectedTotal - $totalPrice) > 0.02) {
            return null;
        }

        $invoiceCode = $this->normalizeInvoiceCode($invoiceCode);

        if ($this->invoiceCodeShouldBeSkippedAsDocumentLine($invoiceCode)) {
            return null;
        }

        return [
            'invoice_code' => $invoiceCode,
            'product_code' => $invoiceCode,
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'discount_amount' => null,
            'vat_rate' => null,
            'total_price' => $totalPrice,
            'inference' => [
                'type' => 'invoice_code_from_following_visual_line',
                'amount_layout' => 'description_quantity_unit_total',
            ],
        ];
    }

    /**
     * Normalizza match regex in item fattura.
     */
    private function buildCompactInvoiceItemFromMatches(array $matches, array $inference): ?array
    {
        $description = trim((string) ($matches['description'] ?? ''));

        if ($description === '' || $this->descriptionShouldBeIgnored($description)) {
            return null;
        }

        $invoiceCode = $this->normalizeInvoiceCode((string) ($matches['code'] ?? ''));

        if ($this->invoiceCodeShouldBeSkippedAsDocumentLine($invoiceCode)) {
            return null;
        }

        return [
            'invoice_code' => $invoiceCode,
            'product_code' => $invoiceCode,
            'description' => $description,
            'quantity' => $this->parseQuantity((string) ($matches['quantity'] ?? '')),
            'unit_price' => $this->parseMoney((string) ($matches['unit_price'] ?? '')),
            'discount_amount' => null,
            'vat_rate' => trim((string) ($matches['vat'] ?? '')),
            'total_price' => $this->parseMoney((string) ($matches['total_price'] ?? '')),
            'inference' => $inference,
        ];
    }

    /**
     * Cerca righe visuali di supporto subito dopo la riga prodotto.
     */
    private function findSupportingVisualLines(array $visualLines, int $currentIndex): array
    {
        $supportingLines = [];

        for ($offset = 1; $offset <= 2; $offset++) {
            $nextIndex = $currentIndex + $offset;

            if (! isset($visualLines[$nextIndex])) {
                break;
            }

            $text = $this->normalizeCompactOcrLine((string) ($visualLines[$nextIndex]['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            if ($this->visualLineEndsCompactInvoiceTable($text)) {
                break;
            }

            if ($this->extractCompactInvoiceVisualLineItem($text)) {
                break;
            }

            if (! $this->looksLikeSupportingLine($text)) {
                break;
            }

            $supportingLines[] = $text;
        }

        return $supportingLines;
    }

    /**
     * Pattern importo OCR.
     *
     * Supporta sia 2,99 sia 2.99.
     */
    private function ocrAmountPattern(): string
    {
        return '\-?\d{1,3}(?:[.\s]\d{3})*[,.]\d{2}|\-?\d+[,.]\d{2}';
    }

    /**
     * Pattern codice fattura OCR-tolerant.
     */
    private function ocrInvoiceCodePattern(): string
    {
        return '(?:[A-Z]{2,}(?:\s*-\s*[A-Z0-9]+)+|[A-Z]{2,}\d[A-Z0-9\-\/\.]*|[A-Z]{3,})';
    }

    /**
     * Normalizza spazi OCR dentro codici tipo FOOD- PASTA o CLEAN - SAN.
     */
    private function normalizeCompactOcrLine(string $line): string
    {
        $line = trim(preg_replace('/\s+/', ' ', $line) ?: '');

        /*
        |--------------------------------------------------------------------------
        | Normalizzazione solo sul codice iniziale
        |--------------------------------------------------------------------------
        |
        | Non tocchiamo trattini nella descrizione, es. "SCHERMO - MANODOPERA".
        |
        */
        $line = preg_replace(
            '/^([A-Z]{2,})\s*-\s*([A-Z0-9]+)/u',
            '$1-$2',
            $line
        ) ?: $line;

        return trim($line);
    }

    /**
     * Normalizza codice fattura.
     */
    private function normalizeInvoiceCode(string $code): string
    {
        $code = trim(preg_replace('/\s+/', ' ', $code) ?: '');
        $code = preg_replace('/\s*-\s*/u', '-', $code) ?: $code;

        return mb_strtoupper($code);
    }

    private function detectColumns(array $items, array $metadata): ?array
    {
        $imageWidth = $this->floatOrNull($metadata['image_width'] ?? null)
            ?: $this->maxX($items);

        if (! $imageWidth) {
            return null;
        }

        $headers = [
            'code' => $this->findHeaderItem($items, ['codice']),
            'description' => $this->findHeaderItem($items, ['descrizione']),
            'quantity' => $this->findHeaderItem($items, ['qta', 'qtà', 'quantita', 'quantità']),
            'unit_price' => $this->findHeaderItem($items, ['prezzo']),
            'discount' => $this->findHeaderItem($items, ['sconto']),
            'vat' => $this->findHeaderItem($items, ['iva']),
            'total' => $this->findHeaderItem($items, ['imponibile', 'totale']),
        ];

        return [
            'code' => [
                'x' => $this->centerX($headers['code']) ?? ($imageWidth * 0.14),
                'header' => $headers['code'],
            ],
            'description' => [
                'x' => $this->centerX($headers['description']) ?? ($imageWidth * 0.25),
                'header' => $headers['description'],
            ],
            'quantity' => [
                'x' => $this->centerX($headers['quantity']) ?? ($imageWidth * 0.52),
                'header' => $headers['quantity'],
            ],
            'unit_price' => [
                'x' => $this->centerX($headers['unit_price']) ?? ($imageWidth * 0.58),
                'header' => $headers['unit_price'],
            ],
            'discount' => [
                'x' => $this->centerX($headers['discount']) ?? ($imageWidth * 0.66),
                'header' => $headers['discount'],
            ],
            'vat' => [
                'x' => $this->centerX($headers['vat']) ?? ($imageWidth * 0.72),
                'header' => $headers['vat'],
            ],
            'total' => [
                'x' => $this->centerX($headers['total']) ?? ($imageWidth * 0.79),
                'header' => $headers['total'],
            ],
        ];
    }

    private function detectTableBounds(array $items, array $columns): array
    {
        $headerY2Values = [];

        foreach ($columns as $column) {
            if (isset($column['header']['y2']) && is_numeric($column['header']['y2'])) {
                $headerY2Values[] = (float) $column['header']['y2'];
            }
        }

        $startY = ! empty($headerY2Values)
            ? max(0.0, max($headerY2Values) - 50.0)
            : 0.0;

        $summaryY = null;

        foreach ($items as $item) {
            $text = mb_strtolower(trim((string) ($item['text'] ?? '')));

            if ($text === '') {
                continue;
            }

            if (! $this->itemIsBelowY($item, $startY)) {
                continue;
            }

            foreach ([
                'riepilogo iva',
                'totale imponibile',
                'totale iva',
                'totale fattura',
                'netto a pagare',
                'acconto',
                'nota:',
                'documento di test',
            ] as $signal) {
                if (str_contains($text, $signal)) {
                    $itemY1 = $this->floatOrNull($item['y1'] ?? null);

                    if ($itemY1 !== null) {
                        $summaryY = $summaryY === null
                            ? $itemY1
                            : min($summaryY, $itemY1);
                    }
                }
            }
        }

        return [
            'start_y' => $startY,
            'end_y' => $summaryY,
        ];
    }

    private function findInvoiceCodeItems(array $items, array $columns, array $bounds): array
    {
        $codeItems = [];

        foreach ($items as $item) {
            $text = trim((string) ($item['text'] ?? ''));

            if (! $this->looksLikeInvoiceCode($text)) {
                continue;
            }

            if (! $this->itemInsideTableBounds($item, $bounds)) {
                continue;
            }

            $centerX = $this->floatOrNull($item['center_x'] ?? null);

            if ($centerX === null) {
                continue;
            }

            $codeColumnX = $columns['code']['x'];
            $descriptionColumnX = $columns['description']['x'];

            if ($centerX > ($descriptionColumnX - 20)) {
                continue;
            }

            if (abs($centerX - $codeColumnX) > 160) {
                continue;
            }

            $codeItems[] = $item;
        }

        usort($codeItems, fn (array $a, array $b): int => ($a['center_y'] ?? 0) <=> ($b['center_y'] ?? 0));

        return $codeItems;
    }

    private function buildRowFromCodeItem(
        array $items,
        array $codeItem,
        array $columns,
        array $bounds,
        array $rowBounds,
        float $amountYOffset
    ): ?array
    {
        $descriptionItem = $this->findDescriptionItem($items, $codeItem, $columns, $bounds, $rowBounds);

        if (! $descriptionItem) {
            return null;
        }

        $rowCenterY = $this->rowCenterY($codeItem, $descriptionItem);
        $amountTargetY = $this->amountTargetY(
            codeItem: $codeItem,
            descriptionItem: $descriptionItem,
            amountYOffset: $amountYOffset
        );
        $quantityItem = $this->findNearestColumnItem($items, $columns['quantity']['x'], $amountTargetY, $bounds, $rowBounds, 'quantity');
        $unitPriceItem = $this->findNearestColumnItem($items, $columns['unit_price']['x'], $amountTargetY, $bounds, $rowBounds, 'money');
        $discountItem = $this->findNearestColumnItem($items, $columns['discount']['x'], $amountTargetY, $bounds, $rowBounds, 'money');
        $vatItem = $this->findNearestColumnItem($items, $columns['vat']['x'], $amountTargetY, $bounds, $rowBounds, 'vat');
        $totalItem = $this->findNearestColumnItem($items, $columns['total']['x'], $amountTargetY, $bounds, $rowBounds, 'money');

        $description = trim((string) ($descriptionItem['text'] ?? ''));

        if ($description === '' || $this->descriptionShouldBeIgnored($description)) {
            return null;
        }

        $invoiceCode = trim((string) ($codeItem['text'] ?? ''));
        $quantity = $quantityItem ? $this->parseQuantity((string) $quantityItem['text']) : null;
        $unitPrice = $unitPriceItem ? $this->parseMoney((string) $unitPriceItem['text']) : null;
        $discountAmount = $discountItem ? $this->parseMoney((string) $discountItem['text']) : null;
        $vatRate = $vatItem ? trim((string) $vatItem['text']) : null;
        $totalPrice = $totalItem ? $this->parseMoney((string) $totalItem['text']) : null;

        /*
        |--------------------------------------------------------------------------
        | Quantità implicita
        |--------------------------------------------------------------------------
        |
        | Alcuni OCR saltano la quantità quando è "1".
        | Se prezzo unitario e totale coincidono, è ragionevole dedurre quantità 1.
        |
        */
        if ($quantity === null && $unitPrice !== null && $totalPrice !== null && abs($unitPrice - $totalPrice) < 0.01) {
            $quantity = 1.0;
        }

        if ($totalPrice === null) {
            return null;
        }

        $supportingLines = $this->findSupportingLines($items, $descriptionItem, $bounds);

        $rawTextParts = array_values(array_filter([
            $invoiceCode,
            $description,
            $quantity !== null ? $this->formatNumberForRawText($quantity) : null,
            $unitPrice !== null ? $this->formatMoneyForRawText($unitPrice) : null,
            $discountAmount !== null ? $this->formatMoneyForRawText($discountAmount) : null,
            $vatRate,
            $this->formatMoneyForRawText($totalPrice),
            ...$supportingLines,
        ]));

        $sourceItems = array_values(array_filter([
            $codeItem,
            $descriptionItem,
            $quantityItem,
            $unitPriceItem,
            $discountItem,
            $vatItem,
            $totalItem,
        ]));

        return [
            'invoice_code' => $invoiceCode,
            'product_code' => $this->extractEanFromSupportingLines($supportingLines) ?: $invoiceCode,
            'serial_number' => $this->extractSerialNumberFromSupportingLines($supportingLines),
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'discount_amount' => $discountAmount,
            'vat_rate' => $vatRate,
            'total_price' => $totalPrice,
            'raw_text' => trim(implode(' ', $rawTextParts)),
            'supporting_lines' => $supportingLines,
            'source_item_ids' => array_map(
                fn (array $item): mixed => $item['id'] ?? null,
                $sourceItems
            ),
            'columns' => [
                'quantity_item_id' => $quantityItem['id'] ?? null,
                'unit_price_item_id' => $unitPriceItem['id'] ?? null,
                'discount_item_id' => $discountItem['id'] ?? null,
                'vat_item_id' => $vatItem['id'] ?? null,
                'total_item_id' => $totalItem['id'] ?? null,
            ],
            'confidence_score' => $this->estimateConfidenceScore(
                description: $description,
                invoiceCode: $invoiceCode,
                quantity: $quantity,
                unitPrice: $unitPrice,
                totalPrice: $totalPrice,
                vatRate: $vatRate
            ),
        ];
    }

    private function findDescriptionItem(
        array $items,
        array $codeItem,
        array $columns,
        array $bounds,
        array $rowBounds
    ): ?array
    {
        $codeY = $this->floatOrNull($codeItem['center_y'] ?? null);

        if ($codeY === null) {
            return null;
        }

        $descriptionX = $columns['description']['x'];
        $quantityX = $columns['quantity']['x'];

        $candidates = [];

        foreach ($items as $item) {
            if (! $this->itemInsideTableBounds($item, $bounds)) {
                continue;
            }

            if (! $this->itemInsideRowBounds($item, $rowBounds)) {
                continue;
            }

            $text = trim((string) ($item['text'] ?? ''));

            if ($text === '' || $this->looksLikeInvoiceCode($text) || $this->looksLikeMoney($text) || $this->looksLikeVat($text)) {
                continue;
            }

            if ($this->descriptionShouldBeIgnored($text)) {
                continue;
            }

            $centerX = $this->floatOrNull($item['center_x'] ?? null);
            $centerY = $this->floatOrNull($item['center_y'] ?? null);

            if ($centerX === null || $centerY === null) {
                continue;
            }

            if ($centerX < ($descriptionX - 120) || $centerX > ($quantityX - 50)) {
                continue;
            }

            $verticalDistance = abs($centerY - $codeY);

            if ($verticalDistance > 42) {
                continue;
            }

            $candidates[] = [
                'item' => $item,
                'distance' => $verticalDistance,
                'x_distance' => abs($centerX - $descriptionX),
            ];
        }

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, function (array $a, array $b): int {
            $distanceComparison = $a['distance'] <=> $b['distance'];

            if ($distanceComparison !== 0) {
                return $distanceComparison;
            }

            return $a['x_distance'] <=> $b['x_distance'];
        });

        return $candidates[0]['item'];
    }

    private function findNearestColumnItem(
        array $items,
        float $columnX,
        float $rowCenterY,
        array $bounds,
        array $rowBounds,
        string $kind
    ): ?array {
        $candidates = [];

        foreach ($items as $item) {
            if (! $this->itemInsideTableBounds($item, $bounds)) {
                continue;
            }

            if (! $this->itemInsideRowBounds($item, $rowBounds)) {
                continue;
            }

            $text = trim((string) ($item['text'] ?? ''));

            if (! $this->itemMatchesKind($text, $kind)) {
                continue;
            }

            $centerX = $this->floatOrNull($item['center_x'] ?? null);
            $centerY = $this->floatOrNull($item['center_y'] ?? null);

            if ($centerX === null || $centerY === null) {
                continue;
            }

            $verticalDistance = abs($centerY - $rowCenterY);
            $horizontalDistance = abs($centerX - $columnX);

            if ($horizontalDistance > 95) {
                continue;
            }

            $candidates[] = [
                'item' => $item,
                'vertical_distance' => $verticalDistance,
                'horizontal_distance' => $horizontalDistance,
            ];
        }

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, function (array $a, array $b): int {
            $verticalComparison = $a['vertical_distance'] <=> $b['vertical_distance'];

            if ($verticalComparison !== 0) {
                return $verticalComparison;
            }

            return $a['horizontal_distance'] <=> $b['horizontal_distance'];
        });

        return $candidates[0]['item'];
    }

    private function findSupportingLines(array $items, array $descriptionItem, array $bounds): array
    {
        $descriptionY = $this->floatOrNull($descriptionItem['center_y'] ?? null);

        if ($descriptionY === null) {
            return [];
        }

        $supportingLines = [];

        foreach ($items as $item) {
            if (! $this->itemInsideTableBounds($item, $bounds)) {
                continue;
            }

            $text = trim((string) ($item['text'] ?? ''));

            if (! $this->looksLikeSupportingLine($text)) {
                continue;
            }

            $centerY = $this->floatOrNull($item['center_y'] ?? null);

            if ($centerY === null) {
                continue;
            }

            if ($centerY <= $descriptionY || ($centerY - $descriptionY) > 55) {
                continue;
            }

            $supportingLines[] = $text;
        }

        return $supportingLines;
    }

    private function findHeaderItem(array $items, array $signals): ?array
    {
        foreach ($items as $item) {
            $text = mb_strtolower(trim((string) ($item['text'] ?? '')));

            foreach ($signals as $signal) {
                if ($text === $signal || str_contains($text, $signal)) {
                    return $item;
                }
            }
        }

        return null;
    }

    private function itemMatchesKind(string $text, string $kind): bool
    {
        return match ($kind) {
            'money' => $this->looksLikeMoney($text),
            'vat' => $this->looksLikeVat($text),
            'quantity' => $this->looksLikeQuantity($text),
            default => false,
        };
    }

    private function looksLikeInvoiceCode(string $text): bool
    {
        return preg_match('/^(?:[A-Z]{2,}(?:-[A-Z0-9]+)+|[A-Z]{2,}\d[A-Z0-9\-\/\.]*)$/u', trim($text)) === 1;
    }

    private function looksLikeMoney(string $text): bool
    {
        return preg_match('/^\-?\d{1,3}(?:[.\s]\d{3})*,\d{2}$|^\-?\d+,\d{2}$/u', trim($text)) === 1;
    }

    private function looksLikeVat(string $text): bool
    {
        return preg_match('/^\d{1,2}(?:,\d{2})?%$/u', trim($text)) === 1;
    }

    private function looksLikeQuantity(string $text): bool
    {
        $text = trim($text);

        if ($text === '') {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Evita di scambiare prezzi per quantità
        |--------------------------------------------------------------------------
        |
        | Importi come 39,99 o 1,20 sono prezzi, non quantità.
        | Per ora accettiamo quantità intere oppure decimali con punto.
        |
        */
        if ($this->looksLikeMoney($text)) {
            return false;
        }

        return preg_match('/^\d+(?:\.\d+)?$/u', $text) === 1;
    }

    private function looksLikeSupportingLine(string $text): bool
    {
        $normalized = mb_strtolower(trim($text));

        if ($normalized === '') {
            return false;
        }

        foreach (['imei', 'seriale', 'serial', 'sn-', 'cod. bar', 'barcode', 'ean'] as $signal) {
            if (str_contains($normalized, $signal)) {
                return true;
            }
        }

        return preg_match('/\b\d{8}|\d{12}|\d{13}|\d{14}\b/u', $text) === 1;
    }

    private function descriptionShouldBeIgnored(string $description): bool
    {
        $normalized = mb_strtolower(trim($description));

        foreach ([
            'codice',
            'descrizione',
            'cliente',
            'fattura',
            'totale',
            'riepilogo',
            'iva',
            'acconto',
            'netto a pagare',
            'spese di trasporto',
            'trasporto',
            'nota',
            'documento di test',
            'non utilizzabile',
        ] as $signal) {
            if (str_contains($normalized, $signal)) {
                return true;
            }
        }

        return false;
    }

    private function codeShouldBeIgnored(string $code): bool
    {
        return str_starts_with(mb_strtoupper(trim($code)), 'SERV-');
    }

    private function itemInsideTableBounds(array $item, array $bounds): bool
    {
        $centerY = $this->floatOrNull($item['center_y'] ?? null);

        if ($centerY === null) {
            return false;
        }

        if ($centerY <= ($bounds['start_y'] ?? 0)) {
            return false;
        }

        if (($bounds['end_y'] ?? null) !== null && $centerY >= $bounds['end_y']) {
            return false;
        }

        return true;
    }

    private function itemIsBelowY(array $item, float $y): bool
    {
        $centerY = $this->floatOrNull($item['center_y'] ?? null);

        return $centerY !== null && $centerY > $y;
    }

    private function rowCenterY(array $codeItem, array $descriptionItem): float
    {
        $codeY = $this->floatOrNull($codeItem['center_y'] ?? null) ?? 0.0;
        $descriptionY = $this->floatOrNull($descriptionItem['center_y'] ?? null) ?? $codeY;

        return ($codeY + $descriptionY) / 2;
    }

    private function centerX(?array $item): ?float
    {
        if (! $item) {
            return null;
        }

        return $this->floatOrNull($item['center_x'] ?? null);
    }

    private function maxX(array $items): ?float
    {
        $values = [];

        foreach ($items as $item) {
            $x2 = $this->floatOrNull($item['x2'] ?? null);

            if ($x2 !== null) {
                $values[] = $x2;
            }
        }

        return empty($values) ? null : max($values);
    }

    private function parseMoney(string $amount): ?float
    {
        $amount = trim($amount);

        if ($amount === '') {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | Formati europei e OCR
        |--------------------------------------------------------------------------
        |
        | Supporta:
        | - 1.289,00
        | - 289,00
        | - 2.99
        | - -10,00
        |
        */
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

    private function parseQuantity(string $quantity): ?float
    {
        $normalized = str_replace(',', '.', trim($quantity));

        if (! is_numeric($normalized)) {
            return null;
        }

        return round((float) $normalized, 3);
    }

    private function extractEanFromSupportingLines(array $supportingLines): ?string
    {
        foreach ($supportingLines as $line) {
            $normalizedLine = mb_strtolower($line);

            /*
            |--------------------------------------------------------------------------
            | IMEI / seriali non sono EAN
            |--------------------------------------------------------------------------
            |
            | Se la riga parla esplicitamente di IMEI o seriale, non dobbiamo usarla
            | come codice EAN prodotto.
            |
            */
            if (
                str_contains($normalizedLine, 'imei')
                || str_contains($normalizedLine, 'seriale')
                || str_contains($normalizedLine, 'serial')
                || str_contains($normalizedLine, 'sn-')
            ) {
                continue;
            }

            if (preg_match('/\b(?<ean>\d{8}|\d{12}|\d{13}|\d{14})\b/u', $line, $matches)) {
                return $matches['ean'];
            }
        }

        return null;
    }

    private function extractSerialNumberFromSupportingLines(array $supportingLines): ?string
    {
        foreach ($supportingLines as $line) {
            if (preg_match('/\bIMEI\s*(?:TEST[-\s]*)?(?<serial>[A-Z0-9\-]{8,})\b/iu', $line, $matches)) {
                return trim($matches['serial']);
            }

            if (preg_match('/\bseriale\s+(?<serial>[A-Z0-9\-]{6,})\b/iu', $line, $matches)) {
                return trim($matches['serial']);
            }

            if (preg_match('/\bSN[-\s]?(?<serial>[A-Z0-9\-]{6,})\b/iu', $line, $matches)) {
                return trim($matches['serial']);
            }
        }

        return null;
    }

    private function estimateConfidenceScore(
        string $description,
        string $invoiceCode,
        ?float $quantity,
        ?float $unitPrice,
        ?float $totalPrice,
        ?string $vatRate
    ): int {
        $score = 60;

        if ($description !== '') {
            $score += 10;
        }

        if ($invoiceCode !== '') {
            $score += 10;
        }

        if ($quantity !== null) {
            $score += 5;
        }

        if ($unitPrice !== null) {
            $score += 5;
        }

        if ($totalPrice !== null) {
            $score += 5;
        }

        if ($vatRate !== null) {
            $score += 5;
        }

        return min(100, $score);
    }

    private function detectAmountYOffset(array $items, array $codeItems, array $columns, array $bounds): float
    {
        $totalColumnX = $columns['total']['x'] ?? null;

        if (! is_numeric($totalColumnX)) {
            return 0.0;
        }

        $totalItems = [];

        foreach ($items as $item) {
            if (! $this->itemInsideTableBounds($item, $bounds)) {
                continue;
            }

            $text = trim((string) ($item['text'] ?? ''));

            if (! $this->looksLikeMoney($text)) {
                continue;
            }

            $centerX = $this->floatOrNull($item['center_x'] ?? null);
            $centerY = $this->floatOrNull($item['center_y'] ?? null);

            if ($centerX === null || $centerY === null) {
                continue;
            }

            if (abs($centerX - (float) $totalColumnX) > 120) {
                continue;
            }

            $totalItems[] = $item;
        }

        usort(
            $totalItems,
            fn (array $a, array $b): int => ($a['center_y'] ?? 0) <=> ($b['center_y'] ?? 0)
        );

        usort(
            $codeItems,
            fn (array $a, array $b): int => ($a['center_y'] ?? 0) <=> ($b['center_y'] ?? 0)
        );

        $limit = min(count($codeItems), count($totalItems), 8);
        $offsets = [];

        for ($index = 0; $index < $limit; $index++) {
            $codeY = $this->floatOrNull($codeItems[$index]['center_y'] ?? null);
            $totalY = $this->floatOrNull($totalItems[$index]['center_y'] ?? null);

            if ($codeY === null || $totalY === null) {
                continue;
            }

            $offset = $codeY - $totalY;

            /*
            |--------------------------------------------------------------------------
            | Offset plausibile
            |--------------------------------------------------------------------------
            |
            | Nelle foto inclinate gli importi possono stare sopra il codice.
            | Se invece sono allineati, l'offset sarà vicino a zero.
            |
            */
            if ($offset < -20 || $offset > 90) {
                continue;
            }

            $offsets[] = $offset;
        }

        if (empty($offsets)) {
            return 0.0;
        }

        sort($offsets);

        $middle = intdiv(count($offsets), 2);

        if (count($offsets) % 2 === 1) {
            return (float) $offsets[$middle];
        }

        return ((float) $offsets[$middle - 1] + (float) $offsets[$middle]) / 2;
    }

    private function amountTargetY(array $codeItem, array $descriptionItem, float $amountYOffset): float
    {
        $codeY = $this->floatOrNull($codeItem['center_y'] ?? null);
        $descriptionY = $this->floatOrNull($descriptionItem['center_y'] ?? null);

        if ($codeY === null && $descriptionY === null) {
            return 0.0;
        }

        /*
        |--------------------------------------------------------------------------
        | Se l'offset è significativo, usiamo il codice come ancora.
        |--------------------------------------------------------------------------
        |
        | Nel documento foto corrente gli importi sono circa 30-35px sopra il codice.
        | Questo evita di prendere gli importi della riga successiva.
        |
        */
        if (abs($amountYOffset) >= 8.0 && $codeY !== null) {
            return $codeY - $amountYOffset;
        }

        return (($codeY ?? $descriptionY) + ($descriptionY ?? $codeY)) / 2;
    }

    private function rowBoundsForCodeItem(array $codeItems, int $index, array $tableBounds): array
    {
        $currentY = $this->floatOrNull($codeItems[$index]['center_y'] ?? null);

        if ($currentY === null) {
            return [
                'start_y' => $tableBounds['start_y'] ?? 0,
                'end_y' => $tableBounds['end_y'] ?? null,
            ];
        }

        $previousY = $this->floatOrNull($codeItems[$index - 1]['center_y'] ?? null);
        $nextY = $this->floatOrNull($codeItems[$index + 1]['center_y'] ?? null);

        /*
        |--------------------------------------------------------------------------
        | Fascia verticale della riga prodotto
        |--------------------------------------------------------------------------
        |
        | Gli importi OCR possono stare più in alto della descrizione, soprattutto
        | nelle foto inclinate. Per questo usiamo i midpoint tra codici prodotto
        | e aggiungiamo un margine.
        |
        */
        $margin = 24.0;

        $startY = $previousY !== null
            ? (($previousY + $currentY) / 2) - $margin
            : (($tableBounds['start_y'] ?? 0) - $margin);

        $endY = $nextY !== null
            ? (($currentY + $nextY) / 2) + $margin
            : ($tableBounds['end_y'] ?? null);

        return [
            'start_y' => max(0.0, $startY),
            'end_y' => $endY,
        ];
    }

    private function itemInsideRowBounds(array $item, array $rowBounds): bool
    {
        $centerY = $this->floatOrNull($item['center_y'] ?? null);

        if ($centerY === null) {
            return false;
        }

        if ($centerY < ($rowBounds['start_y'] ?? 0)) {
            return false;
        }

        if (($rowBounds['end_y'] ?? null) !== null && $centerY > $rowBounds['end_y']) {
            return false;
        }

        return true;
    }

    private function formatMoneyForRawText(float $amount): string
    {
        return number_format($amount, 2, ',', '');
    }

    private function formatNumberForRawText(float $number): string
    {
        return rtrim(rtrim(number_format($number, 3, ',', ''), '0'), ',');
    }

    private function floatOrNull(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}