<?php

namespace App\Services\Documents\InvoiceTableExtraction;

use App\Models\Document;

/**
 * Estrae righe tabellari da fatture con testo digitale già disponibile.
 *
 * Questa classe non crea DocumentLine.
 * Produce solo InvoiceRowCandidate, così il risultato può essere confrontato
 * con altre strategie prima di modificare il database.
 */
class TextInvoiceTableExtractor implements InvoiceTableExtractor
{   
    /**
     * Costruttore della classe.
     *
     * @param InvoiceTableExtractionScorer $scorer Oggetto responsabile della valutazione delle righe estratte.
     */
    public function __construct(
        private readonly InvoiceTableExtractionScorer $scorer,
    ) {
    }

    /**
     * Esegue l'estrazione della tabella fattura da un documento.
     *
     * @param Document $document Documento da cui estrarre la tabella fattura.
     * @return InvoiceTableExtractionResult Risultato dell'estrazione della tabella fattura.
     */
    public function extract(Document $document): InvoiceTableExtractionResult
    {
        $lines = $this->documentLines($document);

        if ($lines === []) {
            return InvoiceTableExtractionResult::empty('text_invoice_table', ['empty_raw_text']);
        }

        $rows = [];
        $pendingRow = null;
        $warnings = [];

        /*
        |--------------------------------------------------------------------------
        | Estrazione header/role-driven
        |--------------------------------------------------------------------------
        |
        | Prima di usare le euristiche legacy, proviamo a ricostruire la tabella
        | leggendo l'header e mappando le colonne a ruoli amministrativi noti:
        | codice, descrizione, quantità, prezzo unitario, totale riga.
        |
        | Questo non è product matching e non usa parole prodotto.
        | Usa solo green flag strutturali della tabella fattura.
        */
        $expectedCodeRows = $this->countExpectedCodeRows($lines);
        $headerDrivenRows = $this->extractHeaderDrivenRows($lines);

        if (
            $headerDrivenRows !== []
            && ($expectedCodeRows === 0 || count($headerDrivenRows) >= $expectedCodeRows)
        ) {
            $coverageRatio = $expectedCodeRows > 0
                ? round(count($headerDrivenRows) / $expectedCodeRows, 2)
                : null;

            $result = new InvoiceTableExtractionResult(
                strategy: 'text_invoice_table_header_roles',
                rows: $headerDrivenRows,
                warnings: [],
                metadata: [
                    'source' => 'document.raw_text',
                    'mode' => 'header_role_mapping',
                    'lines_count' => count($lines),
                    'expected_code_rows' => $expectedCodeRows,
                    'extracted_rows' => count($headerDrivenRows),
                    'coverage_ratio' => $coverageRatio,
                ],
            );

            return $this->scorer->score($result);
        }

        foreach ($lines as $index => $line) {
            if ($this->lineShouldBeIgnored($line)) {
                $pendingRow = null;

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Riga completa inline
            |--------------------------------------------------------------------------
            |
            | Esempi:
            | DK-USB Docking Station USB-C Dual HDMI 4K 1 22% 119,00 119,00
            | EL-1001 SMARTPHONE NOVA X2 1 399,90 22% 399,90
            |
            */
            $inlineRow = $this->extractInlineRow($line);

            if ($inlineRow) {
                $supportingLines = $this->findFollowingSupportingLines($lines, $index);

                $rows[] = $this->buildRowCandidate(
                    item: array_merge($inlineRow, [
                        'supporting_lines' => $supportingLines,
                    ]),
                    sourceLineNumber: $index + 1,
                );

                $pendingRow = null;

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Completamento riga multi-riga
            |--------------------------------------------------------------------------
            |
            | Esempio:
            | NB-X1 Notebook Lenovo ThinkPad X1 Carbon Gen 11 1 22%
            | Intel Core i7, 16GB RAM...
            | S/N PF4TEST0091 - EAN 0196388123456 1.499,00 1.499,00
            |
            */
            if ($pendingRow) {
                $completedRow = $this->extractContinuationAmountRow($line, $pendingRow);

                if ($completedRow) {
                    $rows[] = $this->buildRowCandidate(
                        item: $completedRow,
                        sourceLineNumber: $pendingRow['source_line_number'] ?? ($index + 1),
                    );

                    $pendingRow = null;

                    continue;
                }

                if ($this->lineLooksLikeDescriptionContinuation($line)) {
                    $pendingRow['description_parts'][] = $line;
                    $pendingRow['supporting_lines'][] = $line;

                    continue;
                }

                if ($this->lineLooksLikeTechnicalSupportingLine($line)) {
                    $pendingRow['supporting_lines'][] = $line;

                    continue;
                }

                $warnings[] = 'pending_row_discarded';
                $pendingRow = null;
            }

            /*
            |--------------------------------------------------------------------------
            | Inizio riga multi-riga con quantità/IVA ma senza importi
            |--------------------------------------------------------------------------
            */
            $startRow = $this->extractProductStartWithQuantityVat($line);

            if ($startRow) {
                $pendingRow = array_merge($startRow, [
                    'source_line_number' => $index + 1,
                    'description_parts' => [],
                    'supporting_lines' => [],
                ]);

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Inizio riga multi-riga senza importi
            |--------------------------------------------------------------------------
            |
            | Manteniamo anche questo caso per fatture dove codice e descrizione
            | sono su una riga, mentre quantità/prezzo arrivano dopo.
            */
            $genericStartRow = $this->extractProductStart($line);

            if ($genericStartRow) {
                $pendingRow = array_merge($genericStartRow, [
                    'source_line_number' => $index + 1,
                    'description_parts' => [],
                    'supporting_lines' => [],
                ]);

                continue;
            }
        }

        $expectedCodeRows = $this->countExpectedCodeRows($lines);

        $coverageRatio = $expectedCodeRows > 0
            ? round(count($rows) / $expectedCodeRows, 2)
            : null;

        $result = new InvoiceTableExtractionResult(
            strategy: 'text_invoice_table',
            rows: $rows,
            warnings: $warnings,
            metadata: [
                'source' => 'document.raw_text',
                'lines_count' => count($lines),
                'expected_code_rows' => $expectedCodeRows,
                'extracted_rows' => count($rows),
                'coverage_ratio' => $coverageRatio,
            ],
        );

        return $this->scorer->score($result);
    }

    /**
     * Conta le righe che sembrano iniziare con un codice articolo fattura.
     *
     * Serve allo scorer per capire se l'estrattore ha perso righe.
     */
    private function countExpectedCodeRows(array $lines): int
    {
        $count = 0;

        foreach ($lines as $line) {
            if ($this->lineShouldBeIgnored($line)) {
                continue;
            }

            if ($this->lineLooksLikeTechnicalSupportingLine($line)) {
                continue;
            }

            if (! preg_match('/^(?<code>' . $this->invoiceCodePattern() . ')(?:\s+|$)/u', $line, $matches)) {
                continue;
            }

            $code = trim((string) ($matches['code'] ?? ''));

            if ($this->invoiceCodeShouldBeSkipped($code)) {
                continue;
            }

            $count++;
        }

        return $count;
    }

    /**
     * Normalizza il testo del documento in righe pulite.
     */
    private function documentLines(Document $document): array
    {
        return collect(preg_split('/\R/u', (string) $document->raw_text) ?: [])
            ->map(fn ($line): string => $this->normalizeLine((string) $line))
            ->filter(fn (string $line): bool => $line !== '')
            ->values()
            ->all();
    }

    /**
     * Estrae una riga fattura completa.
     */
    private function extractInlineRow(string $line): ?array
    {
        return $this->extractInlineRowWithDiscount($line)
            ?? $this->extractInlineRowWithoutDiscount($line)
            ?? $this->extractInlineRowVatBeforeAmounts($line);
    }

    /**
     * Layout:
     * CODICE DESCRIZIONE QTA UNITARIO SCONTO IVA TOTALE
     */
    private function extractInlineRowWithDiscount(string $line): ?array
    {
        $unitPricePattern = $this->namedAmountPattern('unit_price');
        $discountPattern = $this->namedAmountPattern('discount');
        $totalPricePattern = $this->namedAmountPattern('total_price');

        $pattern = '/^(?<code>' . $this->invoiceCodePattern() . ')\s+' .
            '(?<description>.+?)\s+' .
            '(?<quantity>\d+(?:[,.]\d+)?)\s+' .
            $unitPricePattern . '\s+' .
            $discountPattern . '\s+' .
            '(?<vat>\d{1,2}(?:[,.]\d{2})?%)\s+' .
            $totalPricePattern . '\s*$/u';

        if (! preg_match($pattern, $line, $matches)) {
            return null;
        }

        return $this->buildItemFromMatches($matches, hasDiscount: true);
    }

    /**
     * Layout:
     * CODICE DESCRIZIONE QTA UNITARIO IVA TOTALE
     */
    private function extractInlineRowWithoutDiscount(string $line): ?array
    {
        $unitPricePattern = $this->namedAmountPattern('unit_price');
        $totalPricePattern = $this->namedAmountPattern('total_price');

        $pattern = '/^(?<code>' . $this->invoiceCodePattern() . ')\s+' .
            '(?<description>.+?)\s+' .
            '(?<quantity>\d+(?:[,.]\d+)?)\s+' .
            $unitPricePattern . '\s+' .
            '(?<vat>\d{1,2}(?:[,.]\d{2})?%)\s+' .
            $totalPricePattern . '\s*$/u';

        if (! preg_match($pattern, $line, $matches)) {
            return null;
        }

        return $this->buildItemFromMatches($matches, hasDiscount: false);
    }

    /**
     * Layout:
     * CODICE DESCRIZIONE QTA IVA UNITARIO TOTALE
     */
    private function extractInlineRowVatBeforeAmounts(string $line): ?array
    {
        $unitPricePattern = $this->namedAmountPattern('unit_price');
        $totalPricePattern = $this->namedAmountPattern('total_price');

        $pattern = '/^(?<code>' . $this->invoiceCodePattern() . ')\s+' .
            '(?<description>.+?)\s+' .
            '(?<quantity>\d+(?:[,.]\d+)?)\s+' .
            '(?<vat>\d{1,2}(?:[,.]\d{2})?%)\s+' .
            $unitPricePattern . '\s+' .
            $totalPricePattern . '\s*$/u';

        if (! preg_match($pattern, $line, $matches)) {
            return null;
        }

        return $this->buildItemFromMatches($matches, hasDiscount: false);
    }

    /**
     * Inizio prodotto:
     * CODICE DESCRIZIONE QTA IVA
     */
    private function extractProductStartWithQuantityVat(string $line): ?array
    {
        $pattern = '/^(?<code>' . $this->invoiceCodePattern() . ')\s+' .
            '(?<description>.+?)\s+' .
            '(?<quantity>\d+(?:[,.]\d+)?)\s+' .
            '(?<vat>\d{1,2}(?:[,.]\d{2})?%)\s*$/u';

        if (! preg_match($pattern, $line, $matches)) {
            return null;
        }

        $code = trim((string) $matches['code']);
        $description = trim((string) $matches['description']);

        if ($this->invoiceCodeShouldBeSkipped($code) || $description === '') {
            return null;
        }

        return [
            'code' => $code,
            'description' => $description,
            'quantity' => $this->parseQuantity((string) $matches['quantity']),
            'vat_rate' => trim((string) $matches['vat']),
            'unit_price' => null,
            'total_price' => null,
            'discount_amount' => null,
        ];
    }

    /**
     * Inizio prodotto:
     * CODICE DESCRIZIONE
     */
    private function extractProductStart(string $line): ?array
    {
        $pattern = '/^(?<code>' . $this->invoiceCodePattern() . ')\s+(?<description>.+)$/u';

        if (! preg_match($pattern, $line, $matches)) {
            return null;
        }

        $code = trim((string) $matches['code']);
        $description = trim((string) $matches['description']);

        if ($this->invoiceCodeShouldBeSkipped($code) || $description === '') {
            return null;
        }

        if (! empty($this->extractAmountsFromText($description))) {
            return null;
        }

        if ($this->lineLooksLikeTechnicalSupportingLine($description)) {
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
        ];
    }

    /**
     * Riga finale che completa un prodotto multi-riga.
     *
     * Supporta righe prodotto distribuite così:
     *
     * CODICE DESCRIZIONE
     * dettaglio tecnico
     * EAN/SN/- QTA EUR UNITARIO EUR TOTALE
     *
     * È una regola strutturale: non usa nomi prodotto, ma posizione di quantità,
     * importi e righe tecniche di supporto.
     */
    private function extractContinuationAmountRow(string $line, array $pendingRow): ?array
    {
        $unitPricePattern = $this->namedAmountPattern('unit_price');
        $totalPricePattern = $this->namedAmountPattern('total_price');

        $patterns = [
            /*
            * Quantità + unitario + totale senza prefisso tecnico.
            *
            * Caso comune quando il PDF separa:
            * - codice e descrizione;
            * - dettagli descrittivi;
            * - quantità e importi su una riga numerica dedicata.
            *
            * Esempio:
            * 1 899.00 899.00
            */
            '/^(?<quantity>\d+(?:[,.]\d+)?)\s+' .
                $unitPricePattern . '\s+' .
                $totalPricePattern . '\s*$/u',

            /*
            * Supporto + quantità + IVA + unitario + totale
            */
            '/^(?<support>.+?)\s+(?<quantity>\d+(?:[,.]\d+)?)\s+(?<vat>\d{1,2}(?:[,.]\d{2})?%)\s+' .
                $unitPricePattern . '\s+' .
                $totalPricePattern . '\s*$/u',

            /*
            * Supporto + IVA + unitario + totale
            */
            '/^(?<support>.+?)\s+(?<vat>\d{1,2}(?:[,.]\d{2})?%)\s+' .
                $unitPricePattern . '\s+' .
                $totalPricePattern . '\s*$/u',

            /*
            * Supporto + quantità + unitario + totale
            */
            '/^(?<support>.+?)\s+(?<quantity>\d+(?:[,.]\d+)?)\s+' .
                $unitPricePattern . '\s+' .
                $totalPricePattern . '\s*$/u',

            /*
            * Supporto + unitario + totale
            */
            '/^(?<support>.+?)\s+' .
                $unitPricePattern . '\s+' .
                $totalPricePattern . '\s*$/u',
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match($pattern, $line, $matches)) {
                continue;
            }

            $support = trim((string) ($matches['support'] ?? ''));

            $supportingLines = $pendingRow['supporting_lines'] ?? [];

            /*
            * Il supporto tecnico è facoltativo: alcune fatture riportano soltanto
            * quantità, prezzo unitario e totale sulla riga numerica conclusiva.
            */
            if ($support !== '') {
                $supportingLines[] = $support;
            }

            return array_merge($pendingRow, [
                'supporting_lines' => $supportingLines,
                'quantity' => isset($matches['quantity'])
                    ? $this->parseQuantity((string) $matches['quantity'])
                    : ($pendingRow['quantity'] ?? null),
                'vat_rate' => isset($matches['vat'])
                    ? trim((string) $matches['vat'])
                    : ($pendingRow['vat_rate'] ?? null),
                'unit_price' => $this->parseMoney((string) $matches['unit_price']),
                'total_price' => $this->parseMoney((string) $matches['total_price']),
                'discount_amount' => null,
            ]);
        }

        return null;
    }

    /**
     * Costruisce il formato interno item.
     */
    private function buildItemFromMatches(array $matches, bool $hasDiscount): ?array
    {
        $code = trim((string) $matches['code']);
        $description = trim((string) $matches['description']);

        if ($this->invoiceCodeShouldBeSkipped($code) || $description === '') {
            return null;
        }

        return [
            'code' => $code,
            'description' => $description,
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
     * Converte un item interno in InvoiceRowCandidate.
     */
    private function buildRowCandidate(array $item, int $sourceLineNumber): InvoiceRowCandidate
    {
        $supportingLines = $item['supporting_lines'] ?? [];
        $descriptionParts = $item['description_parts'] ?? [];

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
            ean: $this->extractEanFromSupportingLines($supportingLines),
            serialNumber: $this->extractSerialNumberFromSupportingLines($supportingLines),
            sourceItemIds: [],
            sourceVisualLineIds: [],
            warnings: [],
            metadata: [
                'source_line_number' => $sourceLineNumber,
            ],
        );
    }

    /**
     * Cerca righe tecniche subito dopo una riga inline.
     */
    private function findFollowingSupportingLines(array $lines, int $currentIndex): array
    {
        $supportingLines = [];

        for ($offset = 1; $offset <= 2; $offset++) {
            $index = $currentIndex + $offset;

            if (! isset($lines[$index])) {
                break;
            }

            $line = $lines[$index];

            if ($this->lineShouldBeIgnored($line)) {
                break;
            }

            if ($this->extractInlineRow($line) || $this->extractProductStart($line)) {
                break;
            }

            if (! $this->lineLooksLikeTechnicalSupportingLine($line)) {
                break;
            }

            $supportingLines[] = $line;
        }

        return $supportingLines;
    }

    /**
     * Determina se una riga sembra essere una continuazione della descrizione del prodotto.
     * 
     * @param string $line La riga da analizzare.
     * @return bool True se la riga sembra essere una continuazione della descrizione, false altrimenti.
     */
    private function lineLooksLikeDescriptionContinuation(string $line): bool
    {
        if ($this->lineShouldBeIgnored($line)) {
            return false;
        }

        if (! empty($this->extractAmountsFromText($line))) {
            return false;
        }

        if ($this->extractInlineRow($line) || $this->extractProductStart($line)) {
            return false;
        }

        return mb_strlen($line) >= 6;
    }

    /**
     * Estrae righe tabellari usando l'header come mappa dei ruoli colonna.
     *
     * Questa strategia è pensata per fatture digitali dove il testo contiene:
     * Codice / Descrizione / Quantità / Prezzo unitario / Totale riga
     *
     * Non usa parole prodotto.
     * Usa solo ruoli amministrativi della tabella.
     *
     * @param array<int, string> $lines
     * @return array<int, InvoiceRowCandidate>
     */
    private function extractHeaderDrivenRows(array $lines): array
    {
        $headerIndex = null;
        $headerRoles = null;

        foreach ($lines as $index => $line) {
            $roles = $this->detectHeaderRoles($line);

            if ($roles === null) {
                continue;
            }

            $headerIndex = $index;
            $headerRoles = $roles;

            break;
        }

        if ($headerIndex === null || $headerRoles === null) {
            return [];
        }

        $rows = [];

        for ($index = $headerIndex + 1; $index < count($lines); $index++) {
            $line = $lines[$index] ?? '';
            $line = trim((string) $line);

            if ($line === '') {
                continue;
            }

            if ($this->headerDrivenTableShouldEnd($line)) {
                break;
            }

            if ($this->lineShouldBeIgnored($line)) {
                continue;
            }

            $row = $this->extractHeaderDrivenRow(
                line: $line,
                headerRoles: $headerRoles,
                sourceLineNumber: $index + 1,
            );

            if ($row === null) {
                continue;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Riconosce un header tabellare e restituisce i ruoli colonna trovati.
     *
     * Non pretende che tutte le colonne esistano: molte fatture non hanno IVA
     * o sconto per riga. Però richiede almeno descrizione e un prezzo.
     *
     * @return array<string, string>|null
     */
    private function detectHeaderRoles(string $line): ?array
    {
        $normalized = $this->normalizeHeaderText($line);

        if ($normalized === '') {
            return null;
        }

        $roles = [];

        $roleSynonyms = [
            'code' => [
                'codice',
                'cod',
                'sku',
                'articolo',
                'item',
                'ref',
            ],
            'description' => [
                'descrizione',
                'descr',
                'prodotto',
                'servizio',
                'nome',
            ],
            'quantity' => [
                'quantita',
                'quantità',
                'qta',
                'qty',
                'q ty',
            ],
            'unit_price' => [
                'prezzo unitario',
                'unitario',
                'prezzo',
                'p unit',
                'unit price',
            ],
            'total_price' => [
                'totale riga',
                'totale',
                'importo',
                'importo riga',
                'line total',
            ],
            'vat' => [
                'iva',
                'aliquota',
                'vat',
            ],
            'discount' => [
                'sconto',
                'discount',
            ],
            'ean' => [
                'ean',
                'barcode',
                'cod barre',
                'codice barre',
            ],
            'serial' => [
                'seriale',
                'serial',
                'imei',
                's n',
            ],
        ];

        foreach ($roleSynonyms as $role => $synonyms) {
            foreach ($synonyms as $synonym) {
                if (str_contains($normalized, $synonym)) {
                    $roles[$role] = $synonym;

                    break;
                }
            }
        }

        if (! isset($roles['description'])) {
            return null;
        }

        if (! isset($roles['unit_price']) && ! isset($roles['total_price'])) {
            return null;
        }

        if (! isset($roles['code']) && ! isset($roles['quantity'])) {
            return null;
        }

        return $roles;
    }

    /**
     * Estrae una riga dati usando i ruoli dedotti dall'header.
     *
     * MVP:
     * - codice a inizio riga;
     * - descrizione nel mezzo;
     * - quantità + importi in coda.
     *
     * Questo copre tabelle con IVA solo nel riepilogo finale, senza IVA per riga.
     */
    private function extractHeaderDrivenRow(
        string $line,
        array $headerRoles,
        int $sourceLineNumber,
    ): ?InvoiceRowCandidate {
        if (! preg_match('/^(?<code>' . $this->invoiceCodePattern() . ')\s+(?<body>.+)$/u', $line, $matches)) {
            return null;
        }

        $code = trim((string) ($matches['code'] ?? ''));
        $body = trim((string) ($matches['body'] ?? ''));

        $inlineEan = $this->extractInlineEan($body);
        $body = $this->removeInlineEan($body);

        if ($code === '' || $body === '') {
            return null;
        }

        if ($this->invoiceCodeShouldBeSkipped($code)) {
            return null;
        }

        $unitPricePattern = $this->namedAmountPattern('unit_price', headerDriven: true);
        $totalPricePattern = $this->namedAmountPattern('total_price', headerDriven: true);
        $eanPattern = '\d{8}|\d{12}|\d{13}|\d{14}';
        $serialPattern = '[A-Z0-9][A-Z0-9\-\/]{5,}';

        $patterns = [];

        if (
            isset($headerRoles['ean'])
            && isset($headerRoles['quantity'])
            && isset($headerRoles['unit_price'])
            && isset($headerRoles['total_price'])
        ) {
            $patterns[] = '/^(?<description>.+?)\s+' .
                '(?<ean>' . $eanPattern . ')\s+' .
                '(?<quantity>\d+(?:[,.]\d+)?)\s+' .
                $unitPricePattern . '\s+' .
                $totalPricePattern . '\s*$/u';
        }

        if (
            isset($headerRoles['serial'])
            && isset($headerRoles['quantity'])
            && isset($headerRoles['unit_price'])
            && isset($headerRoles['total_price'])
        ) {
            $patterns[] = '/^(?<description>.+?)\s+' .
                '(?<serial>' . $serialPattern . ')\s+' .
                '(?<quantity>\d+(?:[,.]\d+)?)\s+' .
                $unitPricePattern . '\s+' .
                $totalPricePattern . '\s*$/u';
        }

        if (
            isset($headerRoles['quantity'])
            && isset($headerRoles['unit_price'])
            && isset($headerRoles['total_price'])
        ) {
            $patterns[] = '/^(?<description>.+?)\s+' .
                '(?<quantity>\d+(?:[,.]\d+)?)\s+' .
                $unitPricePattern . '\s+' .
                $totalPricePattern . '\s*$/u';
        }

        if (
            isset($headerRoles['quantity'])
            && ! isset($headerRoles['unit_price'])
            && isset($headerRoles['total_price'])
        ) {
            $patterns[] = '/^(?<description>.+?)\s+' .
                '(?<quantity>\d+(?:[,.]\d+)?)\s+' .
                $totalPricePattern . '\s*$/u';
        }

        foreach ($patterns as $pattern) {
            if (! preg_match($pattern, $body, $rowMatches)) {
                continue;
            }

            $description = trim((string) ($rowMatches['description'] ?? ''));

            if ($description === '' || $this->lineShouldBeIgnored($description)) {
                return null;
            }

            return new InvoiceRowCandidate(
                code: $code,
                description: $description,
                descriptionParts: [],
                quantity: isset($rowMatches['quantity'])
                    ? $this->parseQuantity((string) $rowMatches['quantity'])
                    : null,
                vatRate: null,
                unitPrice: isset($rowMatches['unit_price'])
                    ? $this->parseMoney((string) $rowMatches['unit_price'])
                    : null,
                totalPrice: isset($rowMatches['total_price'])
                    ? $this->parseMoney((string) $rowMatches['total_price'])
                    : null,
                discountAmount: null,
                supportingLines: [],
                ean: $inlineEan ?? ($rowMatches['ean'] ?? null),
                serialNumber: $rowMatches['serial'] ?? null,
                sourceItemIds: [],
                sourceVisualLineIds: [],
                warnings: [],
                metadata: [
                    'source_line_number' => $sourceLineNumber,
                    'mode' => 'header_role_mapping',
                    'header_roles' => $headerRoles,
                ],
            );
        }

        return null;
    }

    /**
     * Decide quando la tabella estratta da header è terminata.
     */
    private function headerDrivenTableShouldEnd(string $line): bool
    {
        $normalized = $this->normalizeHeaderText($line);

        if ($normalized === '') {
            return false;
        }

        foreach ([
            'imponibile',
            'iva ',
            'iva 22',
            'totale documento',
            'totale fattura',
            'totale iva',
            'netto a pagare',
            'netto da pagare',
            'pagamento',
            'note',
            'garanzia',
        ] as $signal) {
            if (str_starts_with($normalized, $signal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalizza testo header per matching ruoli colonna.
     */
    private function normalizeHeaderText(string $text): string
    {
        $normalized = mb_strtolower(trim($text));

        $normalized = str_replace(
            ['à', 'è', 'é', 'ì', 'ò', 'ù'],
            ['a', 'e', 'e', 'i', 'o', 'u'],
            $normalized
        );

        $normalized = preg_replace('/[^a-z0-9%]+/u', ' ', $normalized) ?: $normalized;

        return trim(preg_replace('/\s+/', ' ', $normalized) ?: $normalized);
    }

    /**
     * Determina se una riga sembra essere una riga tecnica di supporto (es. contenente EAN, IMEI, seriale).
     * 
     * @param string $line La riga da analizzare.
     * @return bool True se la riga sembra essere una riga tecnica di supporto, false altrimenti.
     */
    private function lineLooksLikeTechnicalSupportingLine(string $line): bool
    {
        $normalized = mb_strtolower($line);

        foreach (['ean', 'imei', 'seriale', 'serial', 's/n', 'sn-', 'barcode', 'cod. bar'] as $signal) {
            if (str_contains($normalized, $signal)) {
                return true;
            }
        }

        return $this->extractEanFromSupportingLines([$line]) !== null;
    }

    /**
     * Determina se una riga dovrebbe essere ignorata perché contiene segnali di non rilevanza per l'estrazione della tabella fattura.
     * 
     * @param string $line La riga da analizzare.
     * @return bool True se la riga dovrebbe essere ignorata, false altrimenti.
     */
    private function lineShouldBeIgnored(string $line): bool
    {
        $normalized = mb_strtolower($line);

        foreach ([
            'cod. descrizione',
            'codice descrizione',
            'descrizione qta',
            'riepilogo iva',
            'totale imponibile',
            'imponibile ',
            'totale iva',
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
     * Determina se un codice prodotto dovrebbe essere ignorato perché sembra essere un codice generico o una parola chiave non rilevante.
     * 
     * @param string|null $code Il codice da analizzare.
     * @return bool True se il codice dovrebbe essere ignorato, false altrimenti.
     */
    private function invoiceCodeShouldBeSkipped(?string $code): bool
    {
        $code = mb_strtoupper(trim((string) $code));

        if ($code === '') {
            return false;
        }

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
     * Estrae un possibile codice EAN da righe di supporto, escludendo quelle che contengono segnali di seriale/IMEI.
     */
    private function extractEanFromSupportingLines(array $supportingLines): ?string
    {
        foreach ($supportingLines as $line) {
            $normalized = mb_strtolower($line);

            if (
                str_contains($normalized, 'imei')
                || str_contains($normalized, 'seriale')
                || str_contains($normalized, 'serial')
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
     * Estrae un EAN/GTIN scritto direttamente nella descrizione della riga.
     *
     * Esempi:
     * - EAN 0196388123456
     * - EAN: 8055555012222
     * - Barcode 8055555012222
     */
    private function extractInlineEan(string $text): ?string
    {
        if (preg_match('/\b(?:EAN|GTIN|Barcode|Cod(?:ice)?\.?\s*barre)\s*[:\-]?\s*(?<ean>\d{8}|\d{12}|\d{13}|\d{14})\b/iu', $text, $matches)) {
            return $matches['ean'];
        }

        return null;
    }

    /**
     * Rimuove dal body della riga un EAN/GTIN inline già estratto.
     *
     * Serve per evitare che il numero EAN venga interpretato come quantità
     * o come parte di un importo.
     */
    private function removeInlineEan(string $text): string
    {
        $cleaned = preg_replace(
            '/\b(?:EAN|GTIN|Barcode|Cod(?:ice)?\.?\s*barre)\s*[:\-]?\s*(?:\d{8}|\d{12}|\d{13}|\d{14})\b/iu',
            '',
            $text
        ) ?: $text;

        return trim(preg_replace('/\s+/', ' ', $cleaned) ?: $cleaned);
    }

    /**
     * Estrae un possibile numero di serie da righe di supporto, cercando segnali di seriale/IMEI e formati tipici di numeri di serie.
     * 
     * @param array $supportingLines Le righe di supporto da analizzare.
     * @return string|null Il numero di serie estratto, o null se non trovato.
     */
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

            if (preg_match('/\bS\/N\s*(?<serial>[A-Z0-9\-]{6,})\b/iu', $line, $matches)) {
                return trim($matches['serial']);
            }
        }

        return null;
    }

    /**
     * Estrae gli importi da un testo, utilizzando il pattern definito per gli importi.
     * 
     * @param string $text Il testo da analizzare.
     * @return array Gli importi estratti.
     */
    private function extractAmountsFromText(string $text): array
    {
        if (! preg_match_all(
            '/(?<![A-Z0-9])' . $this->embeddedAmountPattern() . '(?![A-Z0-9])/iu',
            $text,
            $matches
        )) {
            return [];
        }

        return $matches[0] ?? [];
    }

    /**
     * Normalizza un importo europeo o internazionale.
     *
     * Formati supportati:
     * - 899,00
     * - 1.899,00
     * - 1 899,00
     * - 899.00
     * - 1,899.00
     */
    private function parseMoney(string $amount): ?float
    {
        $amount = preg_replace(
            '/\b(?:EURO|[A-Z]{3})\b|[€$£]/iu',
            '',
            $amount
        ) ?? $amount;

        $normalized = preg_replace(
            '/\s+/u',
            '',
            trim($amount)
        );

        if ($normalized === null || $normalized === '') {
            return null;
        }

        $lastCommaPosition = strrpos($normalized, ',');
        $lastDotPosition = strrpos($normalized, '.');

        /*
        * Quando sono presenti entrambi i separatori, quello più a destra è
        * considerato il separatore decimale; l'altro separa le migliaia.
        */
        if (
            $lastCommaPosition !== false
            && $lastDotPosition !== false
        ) {
            $decimalSeparator = $lastCommaPosition > $lastDotPosition
                ? ','
                : '.';

            $thousandsSeparator = $decimalSeparator === ','
                ? '.'
                : ',';

            $normalized = str_replace(
                $thousandsSeparator,
                '',
                $normalized
            );

            if ($decimalSeparator === ',') {
                $normalized = str_replace(',', '.', $normalized);
            }
        } elseif ($lastCommaPosition !== false) {
            /*
            * Solo virgola: formato europeo con virgola decimale.
            */
            $normalized = str_replace(',', '.', $normalized);
        } elseif ($lastDotPosition !== false) {
            /*
            * Solo punto: formato internazionale con punto decimale.
            * Il punto deve essere mantenuto.
            */
            $normalized = str_replace(',', '', $normalized);
        }

        if (! is_numeric($normalized)) {
            return null;
        }

        return round((float) $normalized, 2);
    }

    /**
     * Normalizza e converte una stringa di quantità in un float.
     * 
     * @param string $quantity La stringa di quantità da convertire.
     * @return float|null Il valore numerico della quantità, o null se la conversione fallisce.
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
     * Normalizza una linea di testo, rimuovendo spazi multipli e caratteri di controllo.
     * 
     * @param string $line La linea di testo da normalizzare.
     * @return string La linea normalizzata.
     */
    private function normalizeLine(string $line): string
    {
        return trim(preg_replace('/\s+/', ' ', $line) ?: $line);
    }

    /**
     * Restituisce il pattern regex per riconoscere codici fattura, che possono essere alfanumerici e contenere trattini.
     * 
     * @return string Il pattern regex per i codici fattura.
     */
    private function invoiceCodePattern(): string
    {
        return '[A-Z]{2,}(?:-[A-Z0-9]+)+|[A-Z]{2,}\d[A-Z0-9\-\/\.]*';
    }

    /**
     * Restituisce il pattern per importi con formato europeo o internazionale.
     *
     * Formati supportati:
     * - 899,00
     * - 1.899,00
     * - 1 899,00
     * - 899.00
     * - 1,899.00
     */
    private function amountPattern(): string
    {
        return '\-?(?:(?:\d{1,3}(?:[.\s]\d{3})+|\d+),\d{2}'
            . '|(?:\d{1,3}(?:,\d{3})+|\d+)\.\d{2})';
    }

    /**
     * Pattern prudente per cercare importi dentro testo descrittivo libero.
     *
     * Mantiene il comportamento precedente con virgola decimale, evitando che
     * versioni tecniche come "USB 3.20" vengano trattate come prezzi.
     */
    private function embeddedAmountPattern(): string
    {
        return '\-?(?:\d{1,3}(?:[.\s]\d{3})+|\d+),\d{2}';
    }

    /**
     * Pattern importi per tabelle header-driven.
     *
     * Accetta sia il formato europeo sia quello internazionale, ma non accetta
     * lo spazio come separatore delle migliaia perché nelle righe normalizzate
     * lo spazio separa anche le colonne.
     */
    private function headerDrivenAmountPattern(): string
    {
        return '\-?(?:(?:\d{1,3}(?:\.\d{3})+|\d+),\d{2}'
            . '|(?:\d{1,3}(?:,\d{3})+|\d+)\.\d{2})';
    }

    /**
     * Restituisce un gruppo regex nominato per importi con valuta opzionale.
     *
     * Esempi accettati:
     * - 129,90
     * - 1.899,00
     * - EUR 1.899,00
     * - € 1.899,00
     *
     * La valuta resta fuori dal gruppo catturato, così parseMoney riceve solo
     * la parte numerica.
     */
    private function namedAmountPattern(string $name, bool $headerDriven = false): string
    {
        $amountPattern = $headerDriven
            ? $this->headerDrivenAmountPattern()
            : $this->amountPattern();

        return '(?:(?:EURO|[A-Z]{3}|€|\$|£)\s*)?(?<' . $name . '>' . $amountPattern . ')';
    }
}