<?php

namespace App\Services\Documents\InvoiceTableExtraction;

use App\Models\Document;
use App\Services\Documents\DocumentOcrLayoutResolver;

/**
 * Estrae righe fattura da visual_lines OCR.
 *
 * Non modifica il database.
 *
 * Obiettivo:
 * - ricostruire righe tabellari OCR come candidati;
 * - lasciare allo scorer la scelta tra strategie;
 * - evitare che il primo parser parzialmente riuscito blocchi gli altri.
 */
class OcrVisualLineInvoiceTableExtractor implements InvoiceTableExtractor
{   
    /**
     * Il layout resolver è responsabile di restituire un layout coerente con visual_lines
     * e di buona qualità, eventualmente applicando filtri, normalizzazioni e correzioni
     * sulle linee OCR raw. L'extractor si aspetta che il layout restituito abbia una chiave
     * 'visual_lines' che contenga un array di linee ordinate come appaiono visivamente nel documento.
     */
    public function __construct(
        private readonly DocumentOcrLayoutResolver $layoutResolver,
        private readonly InvoiceTableExtractionScorer $scorer,
    ) {
    }

    /**
     * Il metodo principale che esegue l'estrazione delle righe fattura da visual_lines.
     * Restituisce un InvoiceTableExtractionResult che contiene i candidati trovati, eventuali warning e metadata sul processo di estrazione.
     * Il processo di estrazione segue questi passi principali:
     * 1. Risolve il layout del documento per ottenere le visual_lines ordinate.
     * 2. Trova l'indice di inizio della tabella fattura cercando un header con parole chiave come "codice" e "descrizione".
     * 3. Conta quante righe visive sembrano righe articolo fattura per avere un'idea della copertura dell'estrazione.
     * 4. Itera sulle visual_lines a partire dall'indice di inizio tabella, cercando righe che iniziano con un codice fattura.
     * 5. Per ogni riga che sembra una riga articolo, prova a estrarre i dati tabellari (codice, descrizione, quantità, prezzi, ecc.) 
     *  e a completare la riga con eventuali linee di supporto o con la riga successiva se contiene importi.
     * 6. Raccoglie i candidati estratti, eventuali warning e metadata come numero di righe visuali, indice di inizio tabella, 
     *  numero di righe articolo attese, numero di righe estratte e ratio di copertura.
     * 7. Passa il risultato allo scorer per assegnare un punteggio alla strategia di estrazione.
     */
    public function extract(Document $document): InvoiceTableExtractionResult
    {
        $layout = $this->layoutResolver->resolve($document);

        $visualLines = $layout['layout']['visual_lines'] ?? [];

        if (! is_array($visualLines) || empty($visualLines)) {
            return InvoiceTableExtractionResult::empty('ocr_visual_lines', ['no_visual_lines']);
        }

        $visualLines = $this->normalizeVisualLines($visualLines);

        $tableStartIndex = $this->findTableStartIndex($visualLines);

        if ($tableStartIndex === null) {
            return InvoiceTableExtractionResult::empty('ocr_visual_lines', ['invoice_table_header_not_found']);
        }

        $expectedCodeRows = $this->countExpectedCodeRows(
            visualLines: $visualLines,
            tableStartIndex: $tableStartIndex
        );

        $rows = [];
        $warnings = [];
        $index = $tableStartIndex + 1;

        while ($index < count($visualLines)) {
            $line = $visualLines[$index];
            $text = $line['text'];

            if ($this->lineEndsTable($text)) {
                break;
            }

            if (! $this->lineStartsWithInvoiceCode($text)) {
                $index++;

                continue;
            }

            $code = $this->extractLeadingInvoiceCode($text);

            if ($code === null || $this->invoiceCodeShouldBeSkipped($code)) {
                $index++;

                continue;
            }

            $nextCodeIndex = $this->findNextCodeLineIndex($visualLines, $index + 1);
            $segmentEnd = $nextCodeIndex ?? count($visualLines);

            $row = $this->extractRowFromSegment(
                visualLines: $visualLines,
                startIndex: $index,
                endIndex: $segmentEnd
            );

            if (
                $row !== null
                && ! $row->hasPrice()
                && $nextCodeIndex !== null
                && isset($visualLines[$nextCodeIndex])
            ) {
                $shifted = $this->tryCompleteRowFromNextCodeLine(
                    row: $row,
                    nextLine: $visualLines[$nextCodeIndex]
                );

                if ($shifted !== null) {
                    $row = $shifted['row'];
                    $visualLines[$nextCodeIndex]['text'] = $shifted['next_line_without_amounts'];
                    $warnings[] = 'row_completed_from_shifted_amounts';
                }
            }

            if ($row !== null) {
                $rows[] = $row;
            }

            $index = $nextCodeIndex ?? $segmentEnd;
        }

        $coverageRatio = $expectedCodeRows > 0
            ? round(count($rows) / $expectedCodeRows, 2)
            : null;

        $result = new InvoiceTableExtractionResult(
            strategy: 'ocr_visual_lines',
            rows: $rows,
            warnings: $warnings,
            metadata: [
                'visual_lines_count' => count($visualLines),
                'table_start_index' => $tableStartIndex,
                'expected_code_rows' => $expectedCodeRows,
                'extracted_rows' => count($rows),
                'coverage_ratio' => $coverageRatio,
            ],
        );

        return $this->scorer->score($result);
    }

    /**
     * Conta quante righe visive sembrano righe articolo fattura.
     *
     * Serve allo scorer per capire se l'extractor ha ricostruito una percentuale
     * sufficiente della tabella o se ha prodotto un risultato parziale.
     */
    private function countExpectedCodeRows(array $visualLines, int $tableStartIndex): int
    {
        $count = 0;

        for ($index = $tableStartIndex + 1; $index < count($visualLines); $index++) {
            $text = $visualLines[$index]['text'] ?? '';

            if ($this->lineEndsTable($text)) {
                break;
            }

            $code = $this->extractLeadingInvoiceCode($text);

            if ($code === null) {
                continue;
            }

            if ($this->invoiceCodeShouldBeSkipped($code)) {
                continue;
            }

            if ($this->lineLooksLikeTechnicalSupportingInfo($text)) {
                continue;
            }

            $count++;
        }

        return $count;
    }

    /**
     * Applica normalizzazioni e filtri alle visual_lines raw restituite dal layout resolver, 
     *  restituendo un array di linee ordinate come appaiono visivamente nel documento.
     * Le normalizzazioni includono:
     * - rimozione di linee senza testo o con solo spazi;
     * - normalizzazione degli spazi interni ed esterni del testo;
     * - ordinamento delle linee in base alla loro posizione verticale (y) per garantire che siano processate 
     *  nell'ordine visivo corretto.
     * Il layout resolver dovrebbe già restituire le linee ordinate, ma questa funzione garantisce 
     *  che eventuali anomalie vengano corrette prima dell'estrazione.
     * L'extractor si aspetta che ogni linea abbia almeno una chiave 'text' con il testo OCR della linea, 
     *  e opzionalmente 'id', 'item_ids', 'x1', 'y1', 'x2', 'y2' e 'center_y' per metadata di posizione e associazione a item.
     */
    private function normalizeVisualLines(array $visualLines): array
    {
        return collect($visualLines)
            ->filter(fn (array $line): bool => isset($line['text']) && trim((string) $line['text']) !== '')
            ->map(fn (array $line): array => [
                'id' => $line['id'] ?? null,
                'text' => $this->normalizeLine((string) ($line['text'] ?? '')),
                'item_ids' => $line['item_ids'] ?? [],
                'x1' => $line['x1'] ?? null,
                'y1' => $line['y1'] ?? null,
                'x2' => $line['x2'] ?? null,
                'y2' => $line['y2'] ?? null,
                'center_y' => $line['center_y'] ?? $line['y1'] ?? 0,
            ])
            ->sortBy(fn (array $line): float => (float) ($line['center_y'] ?? 0))
            ->values()
            ->all();
    }

    /**
     * Trova l'indice di inizio della tabella fattura cercando un header con parole chiave come "codice" e "descrizione".
     * Restituisce l'indice della prima linea che sembra essere l'header della tabella, o null se non viene trovato.
     * La ricerca è case-insensitive e cerca parole chiave comuni negli header di tabelle fattura. Se non viene trovato un header,
     * l'extractor potrebbe non riuscire a identificare correttamente le righe articolo, quindi è importante che 
     *  il layout resolver restituisca un layout di buona qualità con le visual_lines ben normalizzate. 
     */
    private function findTableStartIndex(array $visualLines): ?int
    {
        foreach ($visualLines as $index => $line) {
            $text = mb_strtolower($line['text']);

            $hasCodeHeader = str_contains($text, 'codice')
                || str_contains($text, 'cod.');

            if (! $hasCodeHeader || ! str_contains($text, 'descrizione')) {
                continue;
            }

            return $index;
        }

        return null;
    }

    /**
     * Trova l'indice della prossima linea che inizia con un codice fattura a partire da un indice dato.
     * Restituisce l'indice della linea trovata, o null se non viene trovata nessuna linea con un codice fattura.
     * La ricerca si ferma se viene incontrata una linea che sembra indicare la fine della tabella 
     *  (es. contiene parole come "totale", "riepilogo", ecc.) per evitare di includere righe non pertinenti.
     */
    private function findNextCodeLineIndex(array $visualLines, int $fromIndex): ?int
    {
        for ($index = $fromIndex; $index < count($visualLines); $index++) {
            $text = $visualLines[$index]['text'] ?? '';

            if ($this->lineEndsTable($text)) {
                return null;
            }

            if ($this->lineStartsWithInvoiceCode($text)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Estrae una riga della tabella fattura da un segmento di linee visive.
     * Restituisce un oggetto InvoiceRowCandidate o null se non è possibile estrarre una riga valida.
     */
    private function extractRowFromSegment(array $visualLines, int $startIndex, int $endIndex): ?InvoiceRowCandidate
    {
        $startLine = $visualLines[$startIndex];
        $startText = $startLine['text'];

        $item = $this->extractInlineItem($startText)
            ?? $this->extractStartWithQuantityVat($startText)
            ?? $this->extractStartWithoutAmounts($startText);

        if ($item === null) {
            return null;
        }

        $descriptionParts = [];
        $supportingLines = [];
        $sourceVisualLineIds = array_filter([$startLine['id'] ?? null]);
        $sourceItemIds = $startLine['item_ids'] ?? [];

        for ($index = $startIndex + 1; $index < $endIndex; $index++) {
            $line = $visualLines[$index];
            $text = $line['text'];

            $sourceVisualLineIds[] = $line['id'] ?? null;
            $sourceItemIds = array_merge($sourceItemIds, $line['item_ids'] ?? []);

            $completion = $this->extractContinuationAmounts($text, $item);

            if ($completion !== null) {
                $item = $completion;
                continue;
            }

            if ($this->lineLooksLikeTechnicalSupportingInfo($text)) {
                $supportingLines[] = $text;
                continue;
            }

            if ($this->lineLooksLikeDescriptionContinuation($text)) {
                if (($item['description'] ?? '') === '') {
                    $item['description'] = $text;
                } else {
                    $descriptionParts[] = $text;
                }

                $supportingLines[] = $text;
            }
        }

        $supportingLines = array_values(array_unique(array_merge(
            $supportingLines,
            $item['supporting_lines'] ?? []
        )));

        $ean = $this->extractEanFromLines($supportingLines);
        $serialNumber = $this->extractSerialNumberFromLines($supportingLines);

        return new InvoiceRowCandidate(
            code: $item['code'] ?? null,
            description: trim((string) ($item['description'] ?? '')),
            descriptionParts: $descriptionParts,
            quantity: $item['quantity'] ?? null,
            vatRate: $item['vat_rate'] ?? null,
            unitPrice: $item['unit_price'] ?? null,
            totalPrice: $item['total_price'] ?? null,
            discountAmount: $item['discount_amount'] ?? null,
            supportingLines: $supportingLines,
            ean: $ean,
            serialNumber: $serialNumber,
            sourceItemIds: array_values(array_unique(array_filter($sourceItemIds))),
            sourceVisualLineIds: array_values(array_unique(array_filter($sourceVisualLineIds))),
            warnings: [],
            metadata: [
                'start_visual_line_index' => $startIndex,
                'end_visual_line_index' => $endIndex,
            ],
        );
    }

    /**
     * Prova a completare una riga articolo con importi estratti dalla riga successiva se questa contiene 
     *  un codice fattura e importi.
     * Restituisce un array con la riga completata e il testo della riga successiva senza gli importi, 
     *  o null se non è possibile completare la riga.
     * La logica di completamento include diversi controlli per evitare di completare con righe non pertinenti,
     *  come righe contabili (es. sconti, acconti, storni) o righe tecniche di supporto (es. informazioni seriale o ean),
     * e per evitare di completare con importi negativi o zero che non hanno senso come prezzo di un prodotto acquistato.
     * Se la riga successiva sembra valida per completare la riga articolo, gli importi vengono estratti e assegnati alla riga,
     * e il testo della riga successiva viene restituito senza gli importi per evitare di creare un candidato duplicato 
     *  con quegli importi.
     */
    private function tryCompleteRowFromNextCodeLine(InvoiceRowCandidate $row, array $nextLine): ?array
    {
        $text = $nextLine['text'] ?? '';

        $amounts = $this->extractTrailingAmountColumns($text);

        if ($amounts === null) {
            return null;
        }

        $nextLineWithoutAmounts = $this->stripTrailingAmountColumns($text);

        if ($nextLineWithoutAmounts === $text || ! $this->lineStartsWithInvoiceCode($nextLineWithoutAmounts)) {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | Non usare righe contabili come completamento importi
        |--------------------------------------------------------------------------
        |
        | Caso tipico:
        | KIT-CLEAN ...
        | SCONTO Sconto promo notebook ricondizionato -50,00 -50,00
        |
        | La riga SCONTO non deve completare il prodotto precedente.
        */
        $nextCode = $this->extractLeadingInvoiceCode($nextLineWithoutAmounts);

        if ($this->invoiceCodeShouldBeSkipped($nextCode)) {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | Non completare con importi negativi o zero
        |--------------------------------------------------------------------------
        |
        | Una riga prodotto acquistato non deve essere completata con totale <= 0.
        | Sconti, acconti e rettifiche vanno gestiti come righe contabili,
        | non come prezzo del prodotto precedente.
        */
        if (($amounts['total_price'] ?? null) !== null && $amounts['total_price'] <= 0) {
            return null;
        }

        $quantity = $row->quantity ?? $amounts['quantity'] ?? null;

        if (
            $quantity === null
            && $amounts['unit_price'] !== null
            && $amounts['total_price'] !== null
            && abs($amounts['unit_price'] - $amounts['total_price']) < 0.01
        ) {
            $quantity = 1.0;
        }

        $completed = new InvoiceRowCandidate(
            code: $row->code,
            description: $row->description,
            descriptionParts: $row->descriptionParts,
            quantity: $quantity,
            vatRate: $row->vatRate ?? $amounts['vat_rate'] ?? null,
            unitPrice: $amounts['unit_price'],
            totalPrice: $amounts['total_price'],
            discountAmount: $row->discountAmount,
            supportingLines: $row->supportingLines,
            ean: $row->ean,
            serialNumber: $row->serialNumber,
            sourceItemIds: array_values(array_unique(array_merge(
                $row->sourceItemIds,
                $nextLine['item_ids'] ?? []
            ))),
            sourceVisualLineIds: array_values(array_unique(array_filter(array_merge(
                $row->sourceVisualLineIds,
                [$nextLine['id'] ?? null]
            )))),
            warnings: array_values(array_unique(array_merge(
                $row->warnings,
                ['completed_from_shifted_amounts']
            ))),
            metadata: array_merge($row->metadata, [
                'shifted_amount_source_visual_line_id' => $nextLine['id'] ?? null,
            ]),
        );

        return [
            'row' => $completed,
            'next_line_without_amounts' => $nextLineWithoutAmounts,
        ];
    }

    /**
     * Prova a estrarre i dati di una riga articolo da una singola linea di testo che inizia con un codice fattura.
     */
    private function extractInlineItem(string $line): ?array
    {
        return $this->extractInlineItemWithDiscount($line)
            ?? $this->extractInlineItemWithoutDiscount($line)
            ?? $this->extractInlineItemVatBeforeAmounts($line);
    }

    /**
     * Prova a estrarre i dati di una riga articolo da una singola linea di testo che include uno sconto.
     */
    private function extractInlineItemWithDiscount(string $line): ?array
    {
        $amount = $this->amountPattern();

        $pattern = '/^(?<code>' . $this->invoiceCodePattern() . ')\s+' .
            '(?<description>.+?)\s+' .
            '(?<quantity>\d+(?:[,.]\d+)?)\s+' .
            '(?<unit_price>' . $amount . ')\s+' .
            '(?<discount>' . $amount . ')\s+' .
            '(?<vat>\d{1,2}(?:[,.]\d{2})?%)\s+' .
            '(?<total_price>' . $amount . ')\s*$/u';

        if (! preg_match($pattern, $line, $matches)) {
            return null;
        }

        return $this->buildItemFromMatches($matches, hasDiscount: true);
    }

    /**
     * Prova a estrarre i dati di una riga articolo da una singola linea di testo che non include uno sconto.
     */
    private function extractInlineItemWithoutDiscount(string $line): ?array
    {
        $amount = $this->amountPattern();

        $pattern = '/^(?<code>' . $this->invoiceCodePattern() . ')\s+' .
            '(?<description>.+?)\s+' .
            '(?<quantity>\d+(?:[,.]\d+)?)\s+' .
            '(?<unit_price>' . $amount . ')\s+' .
            '(?<vat>\d{1,2}(?:[,.]\d{2})?%)\s+' .
            '(?<total_price>' . $amount . ')\s*$/u';

        if (! preg_match($pattern, $line, $matches)) {
            return null;
        }

        return $this->buildItemFromMatches($matches, hasDiscount: false);
    }

    /**
     * Prova a estrarre i dati di una riga articolo da una singola linea di testo in cui l'IVA precede gli importi.
     */
    private function extractInlineItemVatBeforeAmounts(string $line): ?array
    {
        $amount = $this->amountPattern();

        $pattern = '/^(?<code>' . $this->invoiceCodePattern() . ')\s+' .
            '(?<description>.+?)\s+' .
            '(?<quantity>\d+(?:[,.]\d+)?)\s+' .
            '(?<vat>\d{1,2}(?:[,.]\d{2})?%)\s+' .
            '(?<unit_price>' . $amount . ')\s+' .
            '(?<total_price>' . $amount . ')\s*$/u';

        if (! preg_match($pattern, $line, $matches)) {
            return null;
        }

        return $this->buildItemFromMatches($matches, hasDiscount: false);
    }

    /**
     * Prova a estrarre i dati di una riga articolo da una singola linea di testo che inizia con una quantità e un'IVA.
     */
    private function extractStartWithQuantityVat(string $line): ?array
    {
        $pattern = '/^(?<code>' . $this->invoiceCodePattern() . ')\s+' .
            '(?<description>.+?)\s+' .
            '(?<quantity>\d+(?:[,.]\d+)?)\s+' .
            '(?<vat>\d{1,2}(?:[,.]\d{2})?%)\s*$/u';

        if (! preg_match($pattern, $line, $matches)) {
            return null;
        }

        return [
            'code' => trim((string) $matches['code']),
            'description' => trim((string) $matches['description']),
            'quantity' => $this->parseQuantity((string) $matches['quantity']),
            'vat_rate' => trim((string) $matches['vat']),
            'unit_price' => null,
            'total_price' => null,
            'discount_amount' => null,
            'supporting_lines' => [],
        ];
    }

    /**
     * Prova a estrarre i dati di una riga articolo da una singola linea di testo che non include importi.
     */
    private function extractStartWithoutAmounts(string $line): ?array
    {
        $pattern = '/^(?<code>' . $this->invoiceCodePattern() . ')\s+(?<description>.+)$/u';

        if (! preg_match($pattern, $line, $matches)) {
            return null;
        }

        $code = trim((string) $matches['code']);
        $description = trim((string) $matches['description']);

        if ($this->invoiceCodeShouldBeSkipped($code)) {
            return null;
        }

        if ($this->textLooksLikeColumnHeaderRemainder($description)) {
            $description = '';
        }

        if ($this->lineLooksLikeTechnicalSupportingInfo($description)) {
            return null;
        }

        if (! empty($this->extractAmountsFromText($description))) {
            return null;
        }

        return [
            'code' => $code,
            'description' => $description,
            'quantity' => null,
            'vat_rate' => null,
            'unit_price' => null,
            'total_price' => null,
            'discount_amount' => null,
            'supporting_lines' => [],
        ];
    }

    /**
     * Prova a estrarre i dati di una riga articolo da una singola linea di testo che continua una riga precedente con importi.
     */
    private function extractContinuationAmounts(string $line, array $item): ?array
    {
        $amount = $this->amountPattern();

        $patterns = [
            '/^(?<support>.+?)\s+(?<quantity>\d+(?:[,.]\d+)?)\s+(?<vat>\d{1,2}(?:[,.]\d{2})?%)\s+(?<unit_price>' . $amount . ')\s+(?<total_price>' . $amount . ')\s*$/u',
            '/^(?<support>.+?)\s+(?<vat>\d{1,2}(?:[,.]\d{2})?%)\s+(?<unit_price>' . $amount . ')\s+(?<total_price>' . $amount . ')\s*$/u',
            '/^(?<support>.+?)\s+(?<unit_price>' . $amount . ')\s+(?<total_price>' . $amount . ')\s*$/u',
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match($pattern, $line, $matches)) {
                continue;
            }

            $support = trim((string) ($matches['support'] ?? ''));

            if ($support === '') {
                return null;
            }

            /*
            |--------------------------------------------------------------------------
            | Righe contabili / rettifiche
            |--------------------------------------------------------------------------
            |
            | Una riga SCONTO, ACCONTO, STORNO, COUPON, RIMBORSO ecc. non deve
            | completare il prodotto precedente. Sono righe contabili, non importi
            | del prodotto.
            */
            if ($this->textLooksLikeAccountingAdjustment($support)) {
                return null;
            }

            $unitPrice = $this->parseMoney((string) $matches['unit_price']);
            $totalPrice = $this->parseMoney((string) $matches['total_price']);

            /*
            |--------------------------------------------------------------------------
            | Importi non positivi
            |--------------------------------------------------------------------------
            |
            | Una riga prodotto acquistata non viene completata con totale <= 0.
            | Importi negativi o zero sono sconti, coupon, storni o rettifiche.
            */
            if ($unitPrice === null || $totalPrice === null || $unitPrice <= 0 || $totalPrice <= 0) {
                return null;
            }

            $supportingLines = $item['supporting_lines'] ?? [];
            $supportingLines[] = $support;

            return array_merge($item, [
                'supporting_lines' => $supportingLines,
                'quantity' => isset($matches['quantity'])
                    ? $this->parseQuantity((string) $matches['quantity'])
                    : ($item['quantity'] ?? null),
                'vat_rate' => isset($matches['vat'])
                    ? trim((string) $matches['vat'])
                    : ($item['vat_rate'] ?? null),
                'unit_price' => $unitPrice,
                'total_price' => $totalPrice,
            ]);
        }

        return null;
    }

    /**
     * Prova a estrarre i dati di una riga articolo da una singola linea di testo che termina con importi.
     */
    private function extractTrailingAmountColumns(string $line): ?array
    {
        $amount = $this->amountPattern();

        $patterns = [
            '/\s+(?<quantity>\d+(?:[,.]\d+)?)\s+(?<vat>\d{1,2}(?:[,.]\d{2})?%)\s+(?<unit_price>' . $amount . ')\s+(?<total_price>' . $amount . ')\s*$/u',
            '/\s+(?<vat>\d{1,2}(?:[,.]\d{2})?%)\s+(?<unit_price>' . $amount . ')\s+(?<total_price>' . $amount . ')\s*$/u',
            '/\s+(?<unit_price>' . $amount . ')\s+(?<total_price>' . $amount . ')\s*$/u',
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match($pattern, $line, $matches)) {
                continue;
            }

            return [
                'quantity' => isset($matches['quantity'])
                    ? $this->parseQuantity((string) $matches['quantity'])
                    : null,
                'vat_rate' => isset($matches['vat'])
                    ? trim((string) $matches['vat'])
                    : null,
                'unit_price' => $this->parseMoney((string) $matches['unit_price']),
                'total_price' => $this->parseMoney((string) $matches['total_price']),
            ];
        }

        return null;
    }

    /**
     * Riconosce righe contabili o rettifiche che non devono completare
     * una riga prodotto.
     *
     * Esempi:
     * SCONTO Sconto promo notebook ricondizionato
     * COUPON fedeltà
     * ACCONTO già versato
     * STORNO riga precedente
     */
    private function textLooksLikeAccountingAdjustment(string $text): bool
    {
        $normalized = mb_strtolower(trim($text));

        if ($normalized === '') {
            return false;
        }

        foreach ([
            'sconto',
            'sconti',
            'promo',
            'coupon',
            'buono',
            'voucher',
            'acconto',
            'storno',
            'rettifica',
            'rimborso',
            'abbuono',
            'arrotondamento',
        ] as $signal) {
            if (str_starts_with($normalized, $signal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rimuove gli importi finali da una linea di testo.
     */
    private function stripTrailingAmountColumns(string $line): string
    {
        $amount = $this->amountPattern();

        $patterns = [
            '/\s+\d+(?:[,.]\d+)?\s+\d{1,2}(?:[,.]\d{2})?%\s+' . $amount . '\s+' . $amount . '\s*$/u',
            '/\s+\d{1,2}(?:[,.]\d{2})?%\s+' . $amount . '\s+' . $amount . '\s*$/u',
            '/\s+' . $amount . '\s+' . $amount . '\s*$/u',
        ];

        foreach ($patterns as $pattern) {
            $stripped = preg_replace($pattern, '', $line, 1);

            if (is_string($stripped) && $stripped !== $line) {
                return trim($stripped);
            }
        }

        return $line;
    }

    /**
     * Costruisce un array rappresentante un articolo a partire dai match trovati.
     */
    private function buildItemFromMatches(array $matches, bool $hasDiscount): ?array
    {
        $code = trim((string) $matches['code']);

        if ($this->invoiceCodeShouldBeSkipped($code)) {
            return null;
        }

        return [
            'code' => $code,
            'description' => trim((string) $matches['description']),
            'quantity' => $this->parseQuantity((string) $matches['quantity']),
            'vat_rate' => trim((string) $matches['vat']),
            'unit_price' => $this->parseMoney((string) $matches['unit_price']),
            'total_price' => $this->parseMoney((string) $matches['total_price']),
            'discount_amount' => $hasDiscount
                ? $this->parseMoney((string) $matches['discount'])
                : null,
            'supporting_lines' => [],
        ];
    }

    /**
     * Determina se una linea inizia con un codice fattura.
     */
    private function lineStartsWithInvoiceCode(string $line): bool
    {
        return $this->extractLeadingInvoiceCode($line) !== null;
    }

    /**
     * Estrae il codice fattura all'inizio di una linea, se presente.
     * Restituisce il codice estratto o null se la linea non inizia con un codice fattura valido.
     */
    private function extractLeadingInvoiceCode(string $line): ?string
    {
        if (! preg_match('/^(?<code>' . $this->invoiceCodePattern() . ')(?:\s+|$)/u', $line, $matches)) {
            return null;
        }

        return trim((string) $matches['code']);
    }

    /**
     * Determina se un codice fattura è da considerare non rilevante per l'estrazione delle righe articolo.
     * Alcuni codici come "SCONTO", "NOTA", "ACCONTO", "TOTALE" ecc. indicano righe contabili, note o totali
     * che non devono essere trattati come righe prodotto.
     */
    private function invoiceCodeShouldBeSkipped(?string $code): bool
    {
        $code = mb_strtoupper(trim((string) $code));

        return in_array($code, [
            'SCONTO',
            'NOTA',
            'NOTE',
            'ACCONTO',
            'TOTALE',
            'SUBTOTALE',
            'RIEPILOGO',
            'IMPORTO',
        ], true);
    }

    /**
     * Determina se una linea sembra indicare la fine della tabella fattura.
     * Cerca parole chiave comuni che appaiono nelle righe di totale, subtotale, imponibile, riepilogo, ecc.
     * Se una linea contiene questi segnali, l'extractor considera che la tabella fattura sia terminata e non cerca più righe articolo dopo di essa.
     */
    private function lineEndsTable(string $line): bool
    {
        $normalized = mb_strtolower($line);

        foreach ([
            'riepilogo',
            'subtotale',
            'imponibile',
            'totale iva',
            'totale documento',
            'totale fattura',
            'netto da pagare',
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
     * Determina se una linea sembra essere una continuazione della descrizione di un articolo.
     * La logica include diversi controlli per evitare di considerare come continuazione righe che contengono importi,
     *  righe che sembrano tecniche di supporto (es. informazioni su seriale o ean) o righe che sembrano contabili (es. sconti, acconti, storni).
     * Se la linea supera questi controlli e ha una lunghezza sufficiente, viene considerata una possibile continuazione della descrizione.
     */
    private function lineLooksLikeDescriptionContinuation(string $line): bool
    {
        if ($this->lineEndsTable($line)) {
            return false;
        }

        if ($this->textLooksLikeAccountingAdjustment($line)) {
            return false;
        }

        if ($this->lineStartsWithInvoiceCode($line)) {
            return false;
        }

        if (! empty($this->extractAmountsFromText($line))) {
            return false;
        }

        return mb_strlen($line) >= 6;
    }

    /**
     * Determina se una linea sembra essere un'informazione tecnica di supporto (es. informazioni su seriale, ean, barcode, ecc.)
     * che non deve essere considerata come descrizione o parte della descrizione di un articolo, ma che può essere utile per arricchire il candidato riga con dati come ean o seriale.
     * La logica include controlli per cercare parole chiave indicative di informazioni tecniche e per cercare pattern di ean o seriali anche in assenza di label esplicite.
     */
    private function lineLooksLikeTechnicalSupportingInfo(string $line): bool
    {
        $normalized = mb_strtolower($line);

        foreach (['ean ', 's/n ', 'sn ', 'serial ', 'seriale ', 'imei ', 'barcode ', 'cod. bar'] as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return true;
            }
        }

        return $this->extractEanFromLines([$line]) !== null
            || $this->extractSerialNumberFromLines([$line]) !== null;
    }

    /**
     * Determina se un testo sembra contenere segnali di essere un resto di header di tabella (es. "quantità", "iva", "prezzo", "totale")
     * che non è stato completamente catturato nell'header vero e proprio, ma che potrebbe essere rimasto attaccato alla descrizione del primo articolo.
     * Se il testo contiene più di uno di questi segnali, è probabile che sia un resto di header e non una vera descrizione, quindi può essere ignorato o rimosso dalla descrizione del primo articolo.
     */
    private function textLooksLikeColumnHeaderRemainder(string $text): bool
    {
        $normalized = mb_strtolower($text);
        $signals = 0;

        foreach (['qta', 'qtà', 'quantita', 'quantità', 'iva', 'unitario', 'prezzo', 'totale'] as $signal) {
            if (str_contains($normalized, $signal)) {
                $signals++;
            }
        }

        return $signals >= 2;
    }

    /**
     * Estrae un codice EAN da un insieme di linee di testo, dando priorità a linee che contengono la label "EAN" anche se contengono anche parole come "S/N", "seriale" o "IMEI".
     * Se una linea contiene la label "EAN" seguita da un numero di 8, 12, 13 o 14 cifre, quel numero viene considerato un EAN valido e restituito immediatamente.
     * Se una linea non contiene la label "EAN" ma contiene un numero di 8, 12, 13 o 14 cifre e non contiene parole come "S/N", "seriale" o "IMEI", quel numero viene considerato un EAN implicito e restituito.
     * Se nessuna linea contiene un EAN valido, viene restituito null.
     */
    private function extractEanFromLines(array $lines): ?string
    {
        foreach ($lines as $line) {
            /*
            |--------------------------------------------------------------------------
            | EAN esplicito
            |--------------------------------------------------------------------------
            |
            | Deve avere priorità anche se la stessa riga contiene S/N, seriale o IMEI.
            |
            | Esempio:
            | S/N PF4TEST0091 - EAN 0196388123456
            */
            if (preg_match('/\bEAN\s*(?<ean>\d{8}|\d{12}|\d{13}|\d{14})\b/iu', $line, $matches)) {
                return $matches['ean'];
            }

            $normalized = mb_strtolower($line);

            /*
            |--------------------------------------------------------------------------
            | Evita di scambiare IMEI/seriali numerici per EAN impliciti
            |--------------------------------------------------------------------------
            |
            | Se non c'è la label EAN e la riga parla solo di seriali/IMEI,
            | non prendiamo numeri a caso.
            */
            if (
                str_contains($normalized, 'imei')
                || str_contains($normalized, 'seriale')
                || str_contains($normalized, 'serial')
                || str_contains($normalized, 's/n')
                || str_contains($normalized, 'sn-')
            ) {
                continue;
            }

            if (preg_match('/\b(?<ean>\d{8}|\d{12}|\d{13}|\d{14})\b/u', $line, $matches)) {
                return $matches['ean'];
            }
        }

        return null;
    }

    /**
     * Estrae un numero di serie da un insieme di linee di testo, dando priorità a linee che contengono label come "S/N", "seriale" o "IMEI".
     * Se una linea contiene una di queste label seguita da un numero alfanumerico di almeno 6 caratteri, quel numero viene considerato un seriale valido e restituito immediatamente.
     * Se nessuna linea contiene un seriale valido, viene restituito null.
     */
    private function extractSerialNumberFromLines(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (preg_match('/\bIMEI\s*(?:TEST[-\s]*)?(?<serial>[A-Z0-9\-]{8,})\b/iu', $line, $matches)) {
                return trim($matches['serial']);
            }

            if (preg_match('/\bseriale\s+(?<serial>[A-Z0-9\-]{6,})\b/iu', $line, $matches)) {
                return trim($matches['serial']);
            }

            if (preg_match('/\bSN[-\s]?(?<serial>[A-Z0-9\-]{6,})\b/iu', $line, $matches)) {
                return trim($matches['serial']);
            }

            if (preg_match('/\bS\/N\s*(?<serial>[A-Z0-9\-]{6,})\b/iu', $line, $matches)) {
                return trim($matches['serial']);
            }
        }

        return null;
    }

    /** 
     * Estrae tutti gli importi presenti in un testo, restituendoli come array di stringhe.
     * La regex cerca pattern che corrispondono a numeri con due decimali, con separatore decimale "," e separatore delle migliaia "." o spazio, o con separatore decimale "." senza separatore delle migliaia.
     * Vengono considerati anche importi negativi che iniziano con "-", ma non importi che fanno parte di codici alfanumerici (es. "PROD-123,45" non viene considerato un importo).
     */
    private function extractAmountsFromText(string $text): array
    {
        if (! preg_match_all('/(?<![A-Z0-9])' . $this->amountPattern() . '(?![A-Z0-9])/iu', $text, $matches)) {
            return [];
        }

        return $matches[0] ?? [];
    }

    /**
     * Converte una stringa che rappresenta un importo in un float.
     * La stringa può contenere separatori delle migliaia "." o spazio e separatore decimale "," o ".".
     * Vengono considerati anche importi negativi che iniziano con "-".
     * Se la stringa non rappresenta un importo valido, viene restituito null.
     */
    private function parseMoney(string $amount): ?float
    {
        $amount = trim($amount);

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
     * Converte una stringa che rappresenta una quantità in un float.
     * La stringa può contenere separatore decimale "," o "." e opzionalmente separatori delle migliaia "." o spazio.
     * Se la stringa non rappresenta una quantità valida, viene restituito null.
     */
    private function parseQuantity(string $quantity): ?float
    {
        $normalized = str_replace(',', '.', trim($quantity));

        if (! is_numeric($normalized)) {
            return null;
        }

        return round((float) $normalized, 3);
    }

    /**
     * Normalizza una linea di testo rimuovendo spazi extra e unificando gli spazi in uno singolo.
     */
    private function normalizeLine(string $line): string
    {
        return trim(preg_replace('/\s+/', ' ', $line) ?: $line);
    }

    /**
     * Restituisce una regex per riconoscere codici fattura all'inizio di una linea.
     * La regex cerca stringhe che iniziano con almeno due lettere seguite da trattini e alfanumerici, o da almeno due lettere seguite da un numero e poi da alfanumerici o trattini.
     * Esempi di codici riconosciuti: "PROD-123", "AB-456-CD", "XYZ789", "ITEM-001-A".
     */
    private function invoiceCodePattern(): string
    {
        return '[A-Z]{2,}(?:-[A-Z0-9]+)+|[A-Z]{2,}\d[A-Z0-9\-\/\.]*';
    }

    /**
     * Restituisce una regex per riconoscere importi monetari, con o senza segno negativo, con separatore decimale "," e separatore delle migliaia "." o spazio, o con separatore decimale "." senza separatore delle migliaia.
     * Esempi di importi riconosciuti: "1.234,56", "1234,56", "1 234,56", "-1234,56", "1234.56", "-1234.56".
     */
    private function amountPattern(): string
    {
        return '\-?\d{1,3}(?:[.\s]\d{3})*,\d{2}|\-?\d+,\d{2}|\-?\d+\.\d{2}';
    }
}