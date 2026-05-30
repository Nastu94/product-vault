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
        $normalized = str_replace(['.', ' '], '', trim($amount));
        $normalized = str_replace(',', '.', $normalized);

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