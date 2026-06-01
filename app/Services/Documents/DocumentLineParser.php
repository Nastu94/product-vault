<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\DocumentLineType;
use App\Services\Documents\InvoiceTableExtraction\InvoiceTableExtractionDocumentLineWriter;
use App\Services\Documents\InvoiceTableExtraction\InvoiceTableExtractionManager;
use App\Services\Documents\InvoiceTableExtraction\InvoiceTableExtractionQualityGate;

class DocumentLineParser
{
    /**
     * Parser principale per estrazione righe prodotto/servizio da testo documento.
     * Usa diverse strategie euristiche per identificare righe candidate, con o senza importi.
     * Il parser è progettato per funzionare su testo raw estratto da OCR o PDF, 
     * e non richiede necessariamente righe già separate.
     * Le righe estratte vengono salvate in tabella document_lines, 
     * con metadata che indicano la strategia di parsing usata e i dati grezzi trovati.
     * Il parser è iterativo e mantiene uno stato di "candidato in sospeso" per gestire righe prodotto 
     * distribuite su più linee o con quantità su riga separata.
     * Per le fatture digitali tabellari viene usato un parser dedicato che riconosce righe 
     * con codice, descrizione, quantità, prezzo, sconto e IVA.
     */
    public function __construct(
        private readonly LayoutAwareInvoiceLineParser $layoutAwareInvoiceLineParser,
        private readonly LayoutAwareReceiptLineParser $layoutAwareReceiptLineParser,
        private readonly InvoiceTableExtractionManager $invoiceTableExtractionManager,
        private readonly InvoiceTableExtractionQualityGate $invoiceTableExtractionQualityGate,
        private readonly InvoiceTableExtractionDocumentLineWriter $invoiceTableExtractionDocumentLineWriter,
    ) {
    }

    /**
     * Estrae righe candidate dal testo del documento.
     *
     * Strategie supportate:
     * - righe prodotto con importi;
     * - righe prodotto senza importi ma con codice + descrizione + quantità;
     * - prodotti distribuiti su più righe dal parser PDF.
     */
    public function parse(Document $document): int
    {
        $text = trim((string) $document->raw_text);

        if ($text === '') {
            return 0;
        }

        $lineTypeId = DocumentLineType::query()
            ->where('code', 'product')
            ->value('id');

        /*
        |--------------------------------------------------------------------------
        | Pulizia righe precedenti
        |--------------------------------------------------------------------------
        |
        | In questa fase rigeneriamo le righe ogni volta che rilanciamo il parser.
        | Quando introdurremo la revisione manuale, eviteremo di cancellare righe
        | già confermate dall'utente.
        |
        */
        $document->lines()->delete();

        $lines = preg_split('/\R/u', $text) ?: [];
    
        /*
        |--------------------------------------------------------------------------
        | Parser dedicato per fatture tabellari
        |--------------------------------------------------------------------------
        |
        | Le fatture digitali hanno spesso righe strutturate con codice, descrizione,
        | quantità, prezzo, sconto, IVA e imponibile. Non devono passare dalle
        | euristiche OCR pensate per scontrini, altrimenti rischiamo associazioni
        | errate tra prezzo e descrizione.
        |
        */
        if ($document->documentType?->code === 'invoice') {
            $layoutAwareCreated = $this->layoutAwareInvoiceLineParser->parse($document, $lineTypeId);

            if ($layoutAwareCreated > 0) {
                return $layoutAwareCreated;
            }

            $invoiceTextCreated = $this->parseInvoiceLines($document, $lineTypeId, $lines);

            if ($invoiceTextCreated > 0) {
                return $invoiceTextCreated;
            }

            return $this->parseInvoiceTableExtractionFallback($document, $lineTypeId);
        }

        /*
        |--------------------------------------------------------------------------
        | Parser layout-aware per scontrini OCR
        |--------------------------------------------------------------------------
        |
        | Se abbiamo visual lines OCR affidabili, usiamole prima del parser testuale.
        | Questo evita associazioni errate tra descrizioni e importi negli scontrini
        | lunghi o multi-colonna.
        |
        */
        if ($document->documentType?->code === 'receipt') {
            $layoutAwareReceiptCreated = $this->layoutAwareReceiptLineParser->parse($document, $lineTypeId);

            if ($layoutAwareReceiptCreated > 0) {
                return $layoutAwareReceiptCreated;
            }
        }

        $created = 0;
        $pendingCodeParts = [];
        $pendingCandidate = null;

        foreach ($lines as $index => $line) {
            $rawLine = $this->normalizeLine($line);

            if ($rawLine === '') {
                continue;
            }

            if ($this->lineBreaksProductContext($rawLine)) {
                if ($pendingCandidate && $this->pendingCandidateIsUsable($pendingCandidate)) {
                    $this->createLineFromPendingCandidate($document, $lineTypeId, $pendingCandidate);
                    $created++;
                }

                $pendingCandidate = null;
                $pendingCodeParts = [];

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Quantità standalone
            |--------------------------------------------------------------------------
            |
            | Esempio:
            | PRD-IMMK3163 Divano Fabiola Lounge
            | Canapa Verde
            | 1
            |
            | La riga "1" completa il candidato prodotto precedente.
            |
            */
            if ($pendingCandidate && $this->lineIsStandaloneQuantity($rawLine)) {
                $pendingCandidate['quantity'] = $this->parseQuantity($rawLine);

                $this->createLineFromPendingCandidate($document, $lineTypeId, $pendingCandidate);

                $created++;
                $pendingCandidate = null;
                $pendingCodeParts = [];

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Righe con importi
            |--------------------------------------------------------------------------
            |
            | Esempio:
            | DIVANO FABIOLA LOUNGE 2 € 1.040,00 € 2.080,0022
            |
            | Questa strategia funziona per fatture, DDT e scontrini con prezzi.
            |
            */
            $amounts = $this->extractAmountsFromText($rawLine);

            if (! empty($amounts)) {
                if ($this->lineShouldBeIgnored($rawLine)) {
                    $pendingCandidate = null;
                    $pendingCodeParts = [];

                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | Scontrini: righe a importo zero o negativo
                |--------------------------------------------------------------------------
                |
                | Su uno scontrino, una riga con importo finale zero o negativo non è una
                | riga prodotto acquistato. È normalmente uno sconto, coupon, storno,
                | omaggio, acconto, rettifica o riga informativa.
                |
                | È una regola strutturale, non keyword-based.
                */
                if (
                    $document->documentType?->code === 'receipt'
                    && $this->receiptAmountLineIsZeroOrNegative($rawLine)
                ) {
                    $pendingCandidate = null;
                    $pendingCodeParts = [];

                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | Scontrini testuali con colonne DESCRIZIONE / IVA / IMPORTO
                |--------------------------------------------------------------------------
                |
                | Prima delle euristiche generiche, proviamo a interpretare la riga come
                | riga tabellare scontrino. Questo evita che l'IVA venga salvata come
                | quantity e che il simbolo % finisca nella descrizione.
                |
                */
                $receiptTextTableLineCreated = $this->tryCreateReceiptTextTableLine(
                    document: $document,
                    lineTypeId: $lineTypeId,
                    rawLine: $rawLine,
                    currentIndex: $index
                );

                if ($receiptTextTableLineCreated) {
                    $created++;
                    $pendingCandidate = null;
                    $pendingCodeParts = [];

                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | Scontrini OCR con righe spezzate
                |--------------------------------------------------------------------------
                |
                | Esempio:
                | 22,00%1.289,00
                | iPhone13PraMax128
                | 0194252697894
                |
                | La riga con importo non contiene descrizione, ma la descrizione è vicina.
                | Questa strategia viene usata solo quando la riga importo contiene una
                | percentuale IVA, così evitiamo di prendere subtotali, IVA o pagamenti.
                |
                */
                $receiptLineCreated = $this->tryCreateReceiptOcrLineFromAmountContext(
                    document: $document,
                    lineTypeId: $lineTypeId,
                    lines: $lines,
                    currentIndex: $index,
                    rawLine: $rawLine,
                    amounts: $amounts,
                );

                if ($receiptLineCreated) {
                    $created++;
                    $pendingCandidate = null;
                    $pendingCodeParts = [];

                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | Scontrini OCR con prezzo e descrizione su righe separate
                |--------------------------------------------------------------------------
                |
                | Esempio:
                | 749,90
                | SMARTPHONE ALPHA X1 256GB BLK
                |
                | Questa strategia è più prudente della ricerca "nearby" generica:
                | considera solo importi standalone positivi e la prima riga utile successiva.
                | Così evitiamo di trasformare subtotali, IVA o pagamenti in prodotti.
                |
                */
                $standaloneAmountLineCreated = $this->tryCreateReceiptOcrLineFromStandaloneAmountContext(
                    document: $document,
                    lineTypeId: $lineTypeId,
                    lines: $lines,
                    currentIndex: $index,
                    rawLine: $rawLine,
                    amounts: $amounts,
                );

                if ($standaloneAmountLineCreated) {
                    $created++;
                    $pendingCandidate = null;
                    $pendingCodeParts = [];

                    continue;
                }

                $description = $this->extractDescription($rawLine);

                if (! $description) {
                    $pendingCandidate = null;
                    $pendingCodeParts = [];

                    continue;
                }

                $productCode = $this->extractProductCodeFromLine($rawLine)
                    ?: $this->buildProductCode($pendingCodeParts);

                $quantity = $this->extractQuantityBeforeFirstAmount($rawLine);
                $unitPrice = count($amounts) >= 2 ? $amounts[0] : null;
                $totalPrice = end($amounts);

                if ($document->documentType?->code === 'order_confirmation') {
                    $quantity = $this->inferOrderConfirmationQuantity(
                        extractedQuantity: $quantity,
                        unitPrice: $unitPrice,
                        totalPrice: $totalPrice
                    );
                }

                $nearbyOrderProductContext = $this->findNearbyOrderProductContext(
                    document: $document,
                    lines: $lines,
                    currentIndex: $index,
                    rawLine: $rawLine,
                    fallbackDescription: $description,
                    fallbackProductCode: $productCode
                );

                if ($nearbyOrderProductContext !== null) {
                    $description = $nearbyOrderProductContext['description'];
                    $productCode = $nearbyOrderProductContext['product_code'] ?? $productCode;
                }

                DocumentLine::query()->create([
                    'document_id' => $document->id,
                    'document_line_type_id' => $lineTypeId,
                    'line_number' => $index + 1,
                    'raw_text' => $nearbyOrderProductContext['raw_text'] ?? $rawLine,
                    'description' => $description,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $totalPrice,
                    'confidence_score' => $this->estimateConfidenceScore(
                        description: $description,
                        amounts: $amounts,
                        quantity: $quantity,
                        productCode: $productCode,
                    ),
                    'metadata' => [
                        'parser' => 'document_line_parser_v3',
                        'mode' => 'amount_based',
                        'amounts_found' => $amounts,
                        'pending_code_parts' => $pendingCodeParts,
                        'product_code_candidate' => $productCode,
                        'ean_code_candidate' => $nearbyOrderProductContext['ean_code'] ?? null,
                        'supporting_lines' => $nearbyOrderProductContext['supporting_lines'] ?? [],
                    ],
                ]);

                $created++;
                $pendingCandidate = null;
                $pendingCodeParts = [];

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Riga con codice prodotto + descrizione
            |--------------------------------------------------------------------------
            |
            | Esempio:
            | PRD-IMMK3163 Divano Fabiola Lounge
            |
            | In questo caso non abbiamo prezzo, ma possiamo comunque generare
            | una riga candidata.
            |
            */
            $productStart = $this->extractProductStartFromLine($rawLine);

            if ($productStart) {
                if ($pendingCandidate && $this->pendingCandidateIsUsable($pendingCandidate)) {
                    $this->createLineFromPendingCandidate($document, $lineTypeId, $pendingCandidate);
                    $created++;
                }

                $pendingCandidate = [
                    'line_number' => $index + 1,
                    'raw_text_parts' => [$rawLine],
                    'description_parts' => [$productStart['description']],
                    'quantity' => null,
                    'unit_price' => null,
                    'total_price' => null,
                    'amounts' => [],
                    'product_code' => $productStart['code'],
                    'mode' => 'code_description_quantity',
                ];

                $pendingCodeParts = [];

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Continuazione descrizione prodotto
            |--------------------------------------------------------------------------
            |
            | Esempio:
            | PRD-IMMK3163 Divano Fabiola Lounge
            | Canapa Verde
            |
            | "Canapa Verde" completa la descrizione del candidato precedente.
            |
            */
            if ($pendingCandidate && $this->lineLooksLikeDescriptionContinuation($rawLine)) {
                $pendingCandidate['raw_text_parts'][] = $rawLine;
                $pendingCandidate['description_parts'][] = $rawLine;

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Pezzi di codice prodotto su righe separate
            |--------------------------------------------------------------------------
            |
            | Esempio:
            | PRD-
            | IMMK3163
            |
            | Verranno usati sulla prossima riga prodotto con importi.
            |
            */
            if ($this->lineLooksLikeProductCodePart($rawLine)) {
                $pendingCodeParts[] = $rawLine;

                continue;
            }
        }

        if ($pendingCandidate && $this->pendingCandidateIsUsable($pendingCandidate)) {
            $this->createLineFromPendingCandidate($document, $lineTypeId, $pendingCandidate);
            $created++;
        }

        return $created;
    }

    /**
     * Cerca contesto prodotto vicino a una riga ordine con importi.
     *
     * Serve per documenti e-commerce / conferme ordine dove il nome prodotto,
     * le caratteristiche, l'EAN e gli importi possono stare su righe separate.
     */
    private function findNearbyOrderProductContext(
        Document $document,
        array $lines,
        int $currentIndex,
        string $rawLine,
        string $fallbackDescription,
        ?string $fallbackProductCode
    ): ?array {
        if ($document->documentType?->code !== 'order_confirmation') {
            return null;
        }

        $productCode = $this->extractProductCodeFromLine($rawLine)
            ?: $fallbackProductCode;

        /*
        |--------------------------------------------------------------------------
        | Titolo prodotto
        |--------------------------------------------------------------------------
        |
        | Sostituiamo la descrizione estratta dalla riga importo solo se sembra
        | una descrizione tecnica/accessoria, per esempio:
        | "colore bianco - Wi-Fi - funzione lavapavimenti".
        |
        | Se invece la descrizione è già un prodotto leggibile, come:
        | "Kit 6 sacchetti ricambio SmartClean X200",
        | non dobbiamo prendere il titolo della riga precedente.
        |
        */
        $title = $this->orderLineLooksLikeTechnicalDetail($fallbackDescription)
            ? $this->findPreviousOrderProductTitle($lines, $currentIndex, $fallbackDescription)
            : null;

        $ean = $title !== null
            ? $this->findNearbyOrderProductEan($lines, $currentIndex)
            : null;

        if (! $title && ! $ean) {
            return null;
        }

        $supportingLines = $this->collectNearbyOrderSupportingLines(
            lines: $lines,
            currentIndex: $currentIndex,
            includeTechnicalDetails: $title !== null
        );

        $rawTextParts = array_filter([
            $title,
            $rawLine,
            ...$supportingLines,
        ], fn ($part): bool => trim((string) $part) !== '');

        return [
            'description' => $title ?: $fallbackDescription,
            'product_code' => $productCode,
            'ean_code' => $ean,
            'supporting_lines' => $supportingLines,
            'raw_text' => trim(preg_replace('/\s+/', ' ', implode(' ', $rawTextParts)) ?: implode(' ', $rawTextParts)),
        ];
    }

    /**
     * Capisce se la descrizione estratta sembra un dettaglio tecnico e non
     * il vero nome prodotto.
     */
    private function orderLineLooksLikeTechnicalDetail(string $line): bool
    {
        $normalized = mb_strtolower(trim($line));

        if ($normalized === '') {
            return false;
        }

        $technicalPrefixes = [
            'colore',
            'funzione',
            'versione',
            'compatibile',
            'materiale',
            'dimensione',
            'misura',
        ];

        foreach ($technicalPrefixes as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cerca il titolo prodotto nelle righe precedenti alla riga importo.
     */
    private function findPreviousOrderProductTitle(array $lines, int $currentIndex, string $fallbackDescription): ?string
    {
        for ($offset = -1; $offset >= -4; $offset--) {
            $index = $currentIndex + $offset;

            if (! isset($lines[$index])) {
                continue;
            }

            $candidate = $this->normalizeLine($lines[$index]);

            if ($candidate === '') {
                continue;
            }

            if ($this->lineShouldBeIgnored($candidate)) {
                return null;
            }

            if ($this->lineIsStandaloneQuantity($candidate)) {
                continue;
            }

            if ($this->lineLooksLikeBarcode($candidate)) {
                continue;
            }

            if (! empty($this->extractAmountsFromText($candidate))) {
                continue;
            }

            if ($this->lineLooksLikeProductCodePart($candidate)) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Preferiamo una riga più "nome prodotto" rispetto alla riga descrittiva
            | tecnica già estratta dall'importo.
            |--------------------------------------------------------------------------
            */
            if ($this->orderLineLooksLikeProductTitle($candidate, $fallbackDescription)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Valuta se una riga vicina sembra un titolo prodotto e non una nota tecnica.
     */
    private function orderLineLooksLikeProductTitle(string $line, string $fallbackDescription): bool
    {
        if (mb_strlen($line) < 6 || mb_strlen($line) > 120) {
            return false;
        }

        if (! preg_match('/[a-zA-ZÀ-ÿ]/u', $line)) {
            return false;
        }

        $normalizedLine = mb_strtolower($line);
        $normalizedFallback = mb_strtolower($fallbackDescription);

        $technicalSignals = [
            'colore',
            'funzione',
            'ean',
            'seriale',
            's/n',
            'wifi',
            'wi-fi',
        ];

        foreach ($technicalSignals as $signal) {
            if (
                str_starts_with($normalizedLine, $signal)
                && ! str_starts_with($normalizedFallback, $signal)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Cerca EAN vicino alla riga importo di una conferma ordine.
     */
    private function findNearbyOrderProductEan(array $lines, int $currentIndex): ?string
    {
        for ($offset = -3; $offset <= 3; $offset++) {
            if ($offset === 0) {
                continue;
            }

            $index = $currentIndex + $offset;

            if (! isset($lines[$index])) {
                continue;
            }

            $candidate = $this->normalizeLine($lines[$index]);

            if ($candidate === '') {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | EAN esplicito
            |--------------------------------------------------------------------------
            |
            | Questo è il caso più affidabile:
            | EAN: 8057777001234
            |
            */
            if (preg_match('/\bEAN\s*[:\-]?\s*(?<ean>\d{8}|\d{12}|\d{13}|\d{14})\b/iu', $candidate, $matches)) {
                return $matches['ean'];
            }

            /*
            |--------------------------------------------------------------------------
            | Barcode isolato
            |--------------------------------------------------------------------------
            |
            | Accettiamo una riga numerica pura, ma NON una riga descrittiva con
            | prezzi dentro. Altrimenti una stringa tipo:
            | "RVA-X200 ... 249,90 249,90"
            | può diventare falsamente "2002499024990".
            |
            */
            if ($this->lineLooksLikeStandaloneBarcode($candidate)) {
                return preg_replace('/\D+/', '', $candidate) ?: null;
            }
        }

        return null;
    }

    /**
     * Riconosce barcode/EAN solo quando la riga è composta dal codice,
     * eventualmente con spazi o separatori, ma senza descrizioni e prezzi.
     */
    private function lineLooksLikeStandaloneBarcode(string $line): bool
    {
        $normalized = trim($line);

        if ($normalized === '') {
            return false;
        }

        if (preg_match('/[a-zA-ZÀ-ÿ]/u', $normalized)) {
            return false;
        }

        if (! empty($this->extractAmountsFromText($normalized))) {
            return false;
        }

        $digits = preg_replace('/\D+/', '', $normalized) ?: '';

        return (bool) preg_match('/^\d{8}$|^\d{12}$|^\d{13}$|^\d{14}$/', $digits);
    }

    /**
     * Raccoglie righe tecniche vicine utili per revisione/debug.
     */
    private function collectNearbyOrderSupportingLines(
        array $lines,
        int $currentIndex,
        bool $includeTechnicalDetails = false
    ): array {
        $supportingLines = [];

        for ($offset = -2; $offset <= 2; $offset++) {
            if ($offset === 0) {
                continue;
            }

            $index = $currentIndex + $offset;

            if (! isset($lines[$index])) {
                continue;
            }

            $candidate = $this->normalizeLine($lines[$index]);

            if ($candidate === '') {
                continue;
            }

            if ($this->lineShouldBeIgnored($candidate)) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Non agganciare altre righe articolo.
            |--------------------------------------------------------------------------
            |
            | Se una riga contiene importi, è quasi certamente un'altra riga tabella
            | e-commerce. Non deve diventare supporting line del prodotto corrente.
            |
            */
            if (! empty($this->extractAmountsFromText($candidate))) {
                continue;
            }

            if ($this->lineIsStandaloneQuantity($candidate)) {
                continue;
            }

            $normalized = mb_strtolower($candidate);

            if (
                str_contains($normalized, 'ean')
                || str_contains($normalized, 'serial')
                || str_contains($normalized, 's/n')
                || $this->lineLooksLikeStandaloneBarcode($candidate)
            ) {
                $supportingLines[] = $candidate;

                continue;
            }

            if (
                $includeTechnicalDetails
                && $this->orderLineLooksLikeTechnicalDetail($candidate)
            ) {
                $supportingLines[] = $candidate;
            }
        }

        return array_values(array_unique($supportingLines));
    }

    /**
     * Verifica se una riga importo di scontrino termina con un importo zero o negativo.
     */
    private function receiptAmountLineIsZeroOrNegative(string $line): bool
    {
        $amount = $this->extractLastSignedMoneyFromText($line);

        if ($amount === null) {
            return false;
        }

        return $amount <= 0;
    }

    /**
     * Estrae l'ultimo importo firmato presente in una riga.
     *
     * A differenza di extractAmountsFromText(), mantiene il segno meno.
     */
    private function extractLastSignedMoneyFromText(string $text): ?float
    {
        if (! preg_match_all(
            '/(?<amount>-?\d{1,3}(?:[.\s]\d{3})*[,.]\d{2}|-?\d+[,.]\d{2})/u',
            $text,
            $matches
        )) {
            return null;
        }

        $amounts = $matches['amount'] ?? [];

        if (empty($amounts)) {
            return null;
        }

        $lastAmount = end($amounts);

        return $this->parseMoney((string) $lastAmount);
    }

    /**
     * Crea una DocumentLine da una riga scontrino testuale:
     * DESCRIZIONE IVA IMPORTO
     *
     * Esempio:
     * CAVO USB-C 1M NYLON NERO 22% 8,90
     */
    private function tryCreateReceiptTextTableLine(
        Document $document,
        ?int $lineTypeId,
        string $rawLine,
        int $currentIndex
    ): bool {
        if ($document->documentType?->code !== 'receipt') {
            return false;
        }

        $item = $this->extractReceiptTextTableItem($rawLine);

        if (! $item) {
            return false;
        }

        DocumentLine::query()->create([
            'document_id' => $document->id,
            'document_line_type_id' => $lineTypeId,
            'line_number' => $currentIndex + 1,
            'raw_text' => $rawLine,
            'description' => $item['description'],
            'quantity' => 1,
            'unit_price' => $item['amount'],
            'total_price' => $item['amount'],
            'confidence_score' => $this->estimateConfidenceScore(
                description: $item['description'],
                amounts: [$item['amount']],
                quantity: 1,
                productCode: null,
            ),
            'metadata' => [
                'parser' => 'document_line_parser_v7',
                'mode' => 'receipt_text_table',
                'vat_rate' => $item['vat_rate'],
                'amounts_found' => [$item['amount']],
                'product_code_candidate' => null,
            ],
        ]);

        return true;
    }

    /**
     * Estrae una riga scontrino testuale strutturata.
     */
    private function extractReceiptTextTableItem(string $line): ?array
    {
        $line = $this->normalizeLine($line);

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
        | Regola strutturale, non keyword-based:
        | se l'importo è zero o negativo non è una riga prodotto acquistato.
        |
        */
        if ($amount <= 0) {
            return null;
        }

        return [
            'description' => trim(preg_replace('/\s+/', ' ', $description) ?: $description),
            'vat_rate' => trim((string) ($matches['vat'] ?? '')),
            'amount' => $amount,
        ];
    }

    /**
     * Estrae righe prodotto/servizio da una fattura tabellare digitale.
     */
    private function parseInvoiceLines(Document $document, ?int $lineTypeId, array $lines): int
    {
        $created = 0;
        $pendingInvoiceItem = null;

        foreach ($lines as $index => $line) {
            $rawLine = $this->normalizeLine($line);

            if ($rawLine === '') {
                continue;
            }

            if ($this->invoiceLineShouldBeIgnored($rawLine)) {
                $pendingInvoiceItem = null;

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Riga fattura completa su una sola riga
            |--------------------------------------------------------------------------
            |
            | Esempio:
            | EL-ACC CAVO USB-C 1M BIANCO 2 7,50 0,00 22% 15,00
            |
            */
            $inlineItem = $this->extractInvoiceInlineItem($rawLine);

            if ($inlineItem) {
                $supportingLines = $this->findFollowingInvoiceSupportingLines($lines, $index);

                if (! empty($supportingLines)) {
                    $inlineItem['supporting_lines'] = $supportingLines;

                    $ean = $this->extractEanFromInvoiceSupportingLines($supportingLines);

                    if ($ean) {
                        $inlineItem['product_code'] = $ean;
                    }
                }

                $this->createInvoiceLine(
                    document: $document,
                    lineTypeId: $lineTypeId,
                    lineNumber: $index + 1,
                    rawTextParts: array_merge([$rawLine], $supportingLines),
                    item: $inlineItem,
                    mode: 'invoice_tabular_inline'
                );

                $created++;
                $pendingInvoiceItem = null;

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Riga importi che completa un prodotto iniziato su righe precedenti
            |--------------------------------------------------------------------------
            |
            | Esempio:
            | EL-1001 SMARTPHONE NOVA X2 128GB BLACK
            | IMEI TEST-356000000000001 - seriale SN-NOVAX2-TEST
            | 1 399,90 0,00 22% 399,90
            |
            */
            if ($pendingInvoiceItem) {
                $continuationAmountItem = $this->extractInvoiceContinuationAmountItem(
                    line: $rawLine,
                    pendingInvoiceItem: $pendingInvoiceItem
                );

                if ($continuationAmountItem) {
                    $this->createInvoiceLine(
                        document: $document,
                        lineTypeId: $lineTypeId,
                        lineNumber: $pendingInvoiceItem['line_number'],
                        rawTextParts: array_merge($pendingInvoiceItem['raw_text_parts'], [$rawLine]),
                        item: $continuationAmountItem,
                        mode: 'invoice_tabular_multiline_split_amounts'
                    );

                    $created++;
                    $pendingInvoiceItem = null;

                    continue;
                }
                $amountColumns = $this->extractInvoiceAmountColumns($rawLine);

                if ($amountColumns) {
                    $item = array_merge($pendingInvoiceItem, $amountColumns);

                    $this->createInvoiceLine(
                        document: $document,
                        lineTypeId: $lineTypeId,
                        lineNumber: $pendingInvoiceItem['line_number'],
                        rawTextParts: $pendingInvoiceItem['raw_text_parts'],
                        item: $item,
                        mode: 'invoice_tabular_multiline'
                    );

                    $created++;
                    $pendingInvoiceItem = null;

                    continue;
                }

                if ($this->lineLooksLikeInvoiceDescriptionContinuation($rawLine)) {
                    $pendingInvoiceItem['raw_text_parts'][] = $rawLine;
                    $pendingInvoiceItem['supporting_lines'][] = $rawLine;

                    $ean = $this->extractEanFromInvoiceSupportingLines($pendingInvoiceItem['supporting_lines']);

                    if ($ean) {
                        $pendingInvoiceItem['product_code'] = $ean;
                    }

                    continue;
                }

                if ($this->lineLooksLikeInvoiceSupportingMetadata($rawLine)) {
                    $pendingInvoiceItem['raw_text_parts'][] = $rawLine;
                    $pendingInvoiceItem['supporting_lines'][] = $rawLine;

                    $barcode = $this->extractBarcodeFromText($rawLine);

                    if ($barcode) {
                        $pendingInvoiceItem['product_code'] = $barcode;
                    }

                    continue;
                }
            }

            $productStartWithQuantityVat = $this->extractInvoiceProductStartWithQuantityVat($rawLine);

            if ($productStartWithQuantityVat) {
                $pendingInvoiceItem = [
                    'line_number' => $index + 1,
                    'raw_text_parts' => [$rawLine],
                    'supporting_lines' => [],
                    'invoice_code' => $productStartWithQuantityVat['code'],
                    'description' => $productStartWithQuantityVat['description'],
                    'quantity' => $productStartWithQuantityVat['quantity'],
                    'vat_rate' => $productStartWithQuantityVat['vat_rate'],
                    'product_code' => $productStartWithQuantityVat['code'],
                ];

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Inizio prodotto fattura multi-riga
            |--------------------------------------------------------------------------
            |
            | Esempio:
            | EL-1001 SMARTPHONE NOVA X2 128GB BLACK
            |
            */
            $productStart = $this->extractInvoiceProductStart($rawLine);

            if ($productStart) {
                $pendingInvoiceItem = [
                    'line_number' => $index + 1,
                    'raw_text_parts' => [$rawLine],
                    'supporting_lines' => [],
                    'invoice_code' => $productStart['code'],
                    'description' => $productStart['description'],
                    'product_code' => $productStart['code'],
                ];

                continue;
            }
        }

        return $created;
    }

    /**
     * Cerca righe tecniche subito dopo una riga fattura inline.
     */
    private function findFollowingInvoiceSupportingLines(array $lines, int $currentIndex): array
    {
        $supportingLines = [];

        for ($offset = 1; $offset <= 2; $offset++) {
            $index = $currentIndex + $offset;

            if (! isset($lines[$index])) {
                break;
            }

            $line = $this->normalizeLine($lines[$index]);

            if ($line === '') {
                continue;
            }

            if ($this->invoiceLineShouldBeIgnored($line)) {
                break;
            }

            if ($this->extractInvoiceInlineItem($line)) {
                break;
            }

            if ($this->extractInvoiceProductStart($line)) {
                break;
            }

            if (! $this->lineLooksLikeInvoiceSupportingMetadata($line)) {
                break;
            }

            $supportingLines[] = $line;
        }

        return $supportingLines;
    }

    /**
     * Estrae EAN da righe supporto, evitando IMEI/seriali.
     */
    private function extractEanFromInvoiceSupportingLines(array $supportingLines): ?string
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
     * Esclude intestazioni, riepiloghi, totali, pagamenti e note.
     */
    private function invoiceLineShouldBeIgnored(string $line): bool
    {
        $normalized = mb_strtolower($line);

        if ($this->lineShouldBeIgnored($line)) {
            return true;
        }

        $signals = [
            'codice descrizione',
            'cliente intestatario',
            'data documento',
            'scadenza',
            'valuta',
            'riepilogo iva',
            'totale imponibile',
            'totale iva',
            'totale fattura',
            'netto a pagare',
            'acconto',
            'nota',
            'caso limite',
            'documento di test',
            'non utilizzabile',
        ];

        foreach ($signals as $signal) {
            if (str_contains($normalized, $signal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Estrae una riga fattura completa.
     *
     * Supporta sia layout con sconto:
     * CODICE DESCRIZIONE QTA PREZZO SCONTO IVA TOTALE
     *
     * sia layout compatto senza sconto:
     * CODICE DESCRIZIONE QTA PREZZO IVA TOTALE
     */
    private function extractInvoiceInlineItem(string $line): ?array
    {
        return $this->extractInvoiceInlineItemWithDiscount($line)
            ?? $this->extractInvoiceInlineItemWithoutDiscount($line)
            ?? $this->extractInvoiceInlineItemVatBeforeAmounts($line);
    }

    /**
     * Fallback strutturato per fatture che i parser legacy non riescono a leggere.
     *
     * Non sostituisce il parser attuale:
     * viene usato solo quando layout-aware + parser testuale legacy producono 0 righe.
     *
     * Questo evita regressioni su documenti già stabilizzati e permette di recuperare
     * casi nuovi come fatture OCR con prodotto spezzato su più righe.
     */
    private function parseInvoiceTableExtractionFallback(Document $document, ?int $lineTypeId): int
    {
        $result = $this->invoiceTableExtractionManager->extractBest($document);

        if (! $this->invoiceTableExtractionQualityGate->passes($result)) {
            return 0;
        }

        return $this->invoiceTableExtractionDocumentLineWriter->write(
            document: $document,
            lineTypeId: $lineTypeId,
            result: $result
        );
    }

    /**
     * Estrae righe fattura con colonna sconto.
     */
    private function extractInvoiceInlineItemWithDiscount(string $line): ?array
    {
        $amountPattern = '\-?\d{1,3}(?:[.\s]\d{3})*,\d{2}|\-?\d+,\d{2}';

        $pattern = '/^(?<code>' . $this->invoiceCodePattern() . ')\s+' .
            '(?<description>.+?)\s+' .
            '(?<quantity>\d+(?:[,.]\d+)?)\s+' .
            '(?<unit_price>' . $amountPattern . ')\s+' .
            '(?<discount>' . $amountPattern . ')\s+' .
            '(?<vat>\d{1,2}(?:,\d{2})?%)\s+' .
            '(?<total_price>' . $amountPattern . ')\s*$/u';

        if (! preg_match($pattern, $line, $matches)) {
            return null;
        }

        return $this->buildInvoiceItemFromMatches($matches, true);
    }

    /**
     * Estrae righe fattura senza colonna sconto.
     */
    private function extractInvoiceInlineItemWithoutDiscount(string $line): ?array
    {
        $amountPattern = '\-?\d{1,3}(?:[.\s]\d{3})*,\d{2}|\-?\d+,\d{2}';

        $pattern = '/^(?<code>' . $this->invoiceCodePattern() . ')\s+' .
            '(?<description>.+?)\s+' .
            '(?<quantity>\d+(?:[,.]\d+)?)\s+' .
            '(?<unit_price>' . $amountPattern . ')\s+' .
            '(?<vat>\d{1,2}(?:,\d{2})?%)\s+' .
            '(?<total_price>' . $amountPattern . ')\s*$/u';

        if (! preg_match($pattern, $line, $matches)) {
            return null;
        }

        return $this->buildInvoiceItemFromMatches($matches, false);
    }

    /**
     * Estrae righe fattura con layout:
     * CODICE DESCRIZIONE QTA IVA UNITARIO TOTALE
     *
     * Esempio:
     * DK-USB Docking Station USB-C Dual HDMI 4K 1 22% 119,00 119,00
     */
    private function extractInvoiceInlineItemVatBeforeAmounts(string $line): ?array
    {
        $amountPattern = '\-?\d{1,3}(?:[.\s]\d{3})*,\d{2}|\-?\d+,\d{2}';

        $pattern = '/^(?<code>' . $this->invoiceCodePattern() . ')\s+' .
            '(?<description>.+?)\s+' .
            '(?<quantity>\d+(?:[,.]\d+)?)\s+' .
            '(?<vat>\d{1,2}(?:,\d{2})?%)\s+' .
            '(?<unit_price>' . $amountPattern . ')\s+' .
            '(?<total_price>' . $amountPattern . ')\s*$/u';

        if (! preg_match($pattern, $line, $matches)) {
            return null;
        }

        return $this->buildInvoiceItemFromMatches($matches, false);
    }

    /**
     * Normalizza i match regex in un item fattura.
     */
    private function buildInvoiceItemFromMatches(array $matches, bool $hasDiscount): ?array
    {
        $description = trim($matches['description']);

        if ($description === '' || $this->lineShouldBeIgnored($description)) {
            return null;
        }

        $invoiceCode = trim($matches['code']);

        if ($this->invoiceCodeShouldBeSkippedAsDocumentLine($invoiceCode)) {
            return null;
        }

        return [
            'invoice_code' => $invoiceCode,
            'description' => $description,
            'quantity' => $this->parseQuantity($matches['quantity']),
            'unit_price' => $this->parseMoney($matches['unit_price']),
            'discount_amount' => $hasDiscount
                ? $this->parseMoney($matches['discount'])
                : null,
            'vat_rate' => trim($matches['vat']),
            'total_price' => $this->parseMoney($matches['total_price']),
            'product_code' => $invoiceCode,
        ];
    }

    /**
     * Estrae colonne importo che completano una riga fattura multi-riga.
     *
     * Supporta sia:
     * QTA PREZZO SCONTO IVA TOTALE
     *
     * sia:
     * QTA PREZZO IVA TOTALE
     */
    private function extractInvoiceAmountColumns(string $line): ?array
    {
        return $this->extractInvoiceAmountColumnsWithDiscount($line)
            ?? $this->extractInvoiceAmountColumnsWithoutDiscount($line);
    }

    /**
     * Estrae colonne importo con sconto.
     */
    private function extractInvoiceAmountColumnsWithDiscount(string $line): ?array
    {
        $amountPattern = '\-?\d{1,3}(?:[.\s]\d{3})*,\d{2}|\-?\d+,\d{2}';

        $pattern = '/^(?<quantity>\d+(?:[,.]\d+)?)\s+' .
            '(?<unit_price>' . $amountPattern . ')\s+' .
            '(?<discount>' . $amountPattern . ')\s+' .
            '(?<vat>\d{1,2}(?:,\d{2})?%)\s+' .
            '(?<total_price>' . $amountPattern . ')\s*$/u';

        if (! preg_match($pattern, $line, $matches)) {
            return null;
        }

        return [
            'quantity' => $this->parseQuantity($matches['quantity']),
            'unit_price' => $this->parseMoney($matches['unit_price']),
            'discount_amount' => $this->parseMoney($matches['discount']),
            'vat_rate' => trim($matches['vat']),
            'total_price' => $this->parseMoney($matches['total_price']),
        ];
    }

    /**
     * Estrae colonne importo senza sconto.
     */
    private function extractInvoiceAmountColumnsWithoutDiscount(string $line): ?array
    {
        $amountPattern = '\-?\d{1,3}(?:[.\s]\d{3})*,\d{2}|\-?\d+,\d{2}';

        $pattern = '/^(?<quantity>\d+(?:[,.]\d+)?)\s+' .
            '(?<unit_price>' . $amountPattern . ')\s+' .
            '(?<vat>\d{1,2}(?:,\d{2})?%)\s+' .
            '(?<total_price>' . $amountPattern . ')\s*$/u';

        if (! preg_match($pattern, $line, $matches)) {
            return null;
        }

        return [
            'quantity' => $this->parseQuantity($matches['quantity']),
            'unit_price' => $this->parseMoney($matches['unit_price']),
            'discount_amount' => null,
            'vat_rate' => trim($matches['vat']),
            'total_price' => $this->parseMoney($matches['total_price']),
        ];
    }

    /**
     * Estrae codice + descrizione da una riga iniziale di prodotto fattura.
     */
    private function extractInvoiceProductStart(string $line): ?array
    {
        $pattern = '/^(?<code>[A-Z]{2,}(?:-[A-Z0-9]+)+|[A-Z]{2,}\d[A-Z0-9\-\/\.]*)\s+(?<description>.+)$/u';

        if (! preg_match($pattern, $line, $matches)) {
            return null;
        }

        $description = trim($matches['description']);

        if ($description === '' || $this->lineShouldBeIgnored($description)) {
            return null;
        }

        if (! empty($this->extractAmountsFromText($description))) {
            return null;
        }

        return [
            'code' => trim($matches['code']),
            'description' => $description,
        ];
    }

    /**
     * Riconosce righe di supporto: seriali, IMEI, barcode, note tecniche prodotto.
     */
    private function lineLooksLikeInvoiceSupportingMetadata(string $line): bool
    {
        $normalized = mb_strtolower($line);

        $signals = [
            'imei',
            'seriale',
            'serial',
            'sn-',
            'cod. bar',
            'barcode',
            'ean',
        ];

        foreach ($signals as $signal) {
            if (str_contains($normalized, $signal)) {
                return true;
            }
        }

        return $this->extractBarcodeFromText($line) !== null;
    }

    /**
     * Estrae un barcode/EAN numerico da una riga.
     */
    private function extractBarcodeFromText(string $text): ?string
    {
        if (! preg_match('/\b(?<barcode>\d{8}|\d{12}|\d{13}|\d{14})\b/u', $text, $matches)) {
            return null;
        }

        return $matches['barcode'];
    }

    /**
     * Crea una DocumentLine da una riga fattura.
     */
    private function createInvoiceLine(
        Document $document,
        ?int $lineTypeId,
        int $lineNumber,
        array $rawTextParts,
        array $item,
        string $mode
    ): void {
        $description = trim((string) ($item['description'] ?? ''));

        if ($description === '') {
            return;
        }

        $amounts = array_values(array_filter([
            $item['unit_price'] ?? null,
            $item['discount_amount'] ?? null,
            $item['total_price'] ?? null,
        ], fn ($amount) => $amount !== null));

        DocumentLine::query()->create([
            'document_id' => $document->id,
            'document_line_type_id' => $lineTypeId,
            'line_number' => $lineNumber,
            'raw_text' => trim(implode(' ', $rawTextParts)),
            'description' => $description,
            'quantity' => $item['quantity'] ?? null,
            'unit_price' => $item['unit_price'] ?? null,
            'total_price' => $item['total_price'] ?? null,
            'confidence_score' => $this->estimateConfidenceScore(
                description: $description,
                amounts: $amounts,
                quantity: $item['quantity'] ?? null,
                productCode: $item['product_code'] ?? null,
            ),
            'metadata' => [
                'parser' => 'document_line_parser_v6',
                'mode' => $mode,
                'invoice_code' => $item['invoice_code'] ?? null,
                'product_code_candidate' => $item['product_code'] ?? null,
                'serial_number_candidate' => $this->extractSerialNumberFromInvoiceSupportingLines($item['supporting_lines'] ?? []),
                'discount_amount' => $item['discount_amount'] ?? null,
                'vat_rate' => $item['vat_rate'] ?? null,
                'supporting_lines' => $item['supporting_lines'] ?? [],
            ],
        ]);
    }

    /**
     * Estrae serial number da righe di supporto fattura.
     */
    private function extractSerialNumberFromInvoiceSupportingLines(array $supportingLines): ?string
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
     * Esclude codici contabili o note che non rappresentano righe prodotto/servizio.
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
     * Estrae l'inizio di un prodotto fattura quando quantità e IVA sono sulla
     * prima riga, ma unitario/totale arrivano su una riga successiva.
     *
     * Esempio:
     * NB-X1 Notebook Lenovo ThinkPad X1 Carbon Gen 11 1 22%
     */
    private function extractInvoiceProductStartWithQuantityVat(string $line): ?array
    {
        $pattern = '/^(?<code>' . $this->invoiceCodePattern() . ')\s+' .
            '(?<description>.+?)\s+' .
            '(?<quantity>\d+(?:[,.]\d+)?)\s+' .
            '(?<vat>\d{1,2}(?:[,.]\d{2})?%)\s*$/u';

        if (! preg_match($pattern, $line, $matches)) {
            return null;
        }

        $invoiceCode = trim($matches['code']);

        if ($this->invoiceCodeShouldBeSkippedAsDocumentLine($invoiceCode)) {
            return null;
        }

        $description = trim($matches['description']);

        if ($description === '' || $this->lineShouldBeIgnored($description)) {
            return null;
        }

        return [
            'code' => $invoiceCode,
            'description' => $description,
            'quantity' => $this->parseQuantity($matches['quantity']),
            'vat_rate' => trim($matches['vat']),
        ];
    }

    /**
     * Estrae una riga finale che completa un prodotto multi-riga.
     *
     * Supporta casi tipo:
     * S/N PF4TEST0091 - EAN 0196388123456 1.499,00 1.499,00
     * EAN 8055555012222 22% 119,00 119,00
     */
    private function extractInvoiceContinuationAmountItem(string $line, array $pendingInvoiceItem): ?array
    {
        $amountPattern = '\-?\d{1,3}(?:[.\s]\d{3})*,\d{2}|\-?\d+,\d{2}';

        $patterns = [
            '/^(?<support>.+?)\s+(?<quantity>\d+(?:[,.]\d+)?)\s+(?<vat>\d{1,2}(?:[,.]\d{2})?%)\s+(?<unit_price>' . $amountPattern . ')\s+(?<total_price>' . $amountPattern . ')\s*$/u',
            '/^(?<support>.+?)\s+(?<vat>\d{1,2}(?:[,.]\d{2})?%)\s+(?<unit_price>' . $amountPattern . ')\s+(?<total_price>' . $amountPattern . ')\s*$/u',
            '/^(?<support>.+?)\s+(?<unit_price>' . $amountPattern . ')\s+(?<total_price>' . $amountPattern . ')\s*$/u',
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match($pattern, $line, $matches)) {
                continue;
            }

            $support = trim((string) ($matches['support'] ?? ''));

            if ($support === '') {
                return null;
            }

            $supportingLines = $pendingInvoiceItem['supporting_lines'] ?? [];
            $supportingLines[] = $support;

            $ean = $this->extractEanFromInvoiceSupportingLines($supportingLines);

            return array_merge($pendingInvoiceItem, [
                'supporting_lines' => $supportingLines,
                'quantity' => isset($matches['quantity'])
                    ? $this->parseQuantity($matches['quantity'])
                    : ($pendingInvoiceItem['quantity'] ?? null),
                'unit_price' => $this->parseMoney($matches['unit_price']),
                'discount_amount' => null,
                'vat_rate' => isset($matches['vat'])
                    ? trim($matches['vat'])
                    : ($pendingInvoiceItem['vat_rate'] ?? null),
                'total_price' => $this->parseMoney($matches['total_price']),
                'product_code' => $ean ?: ($pendingInvoiceItem['product_code'] ?? null),
            ]);
        }

        return null;
    }

    /**
     * Righe descrittive intermedie di un prodotto fattura multi-riga.
     *
     * Non sono nuove righe prodotto, ma contesto da conservare in supporting_lines.
     */
    private function lineLooksLikeInvoiceDescriptionContinuation(string $line): bool
    {
        $line = $this->normalizeLine($line);

        if ($line === '') {
            return false;
        }

        if ($this->invoiceLineShouldBeIgnored($line)) {
            return false;
        }

        if (! empty($this->extractAmountsFromText($line))) {
            return false;
        }

        if ($this->extractInvoiceInlineItem($line)) {
            return false;
        }

        if ($this->extractInvoiceProductStartWithQuantityVat($line)) {
            return false;
        }

        if ($this->extractInvoiceProductStart($line)) {
            return false;
        }

        return mb_strlen($line) >= 6;
    }

    /**
     * Converte un importo testuale europeo in float.
     */
    private function parseMoney(string $amount): ?float
    {
        $normalized = str_replace(['.', ' '], '', trim($amount));
        $normalized = str_replace(',', '.', $normalized);

        if (! is_numeric($normalized)) {
            return null;
        }

        return round((float) $normalized, 2);
    }

    /**
     * Prova a creare una riga prodotto da uno scontrino OCR in cui prezzo
     * e descrizione sono stati estratti su righe diverse.
     */
    private function tryCreateReceiptOcrLineFromAmountContext(
        Document $document,
        ?int $lineTypeId,
        array $lines,
        int $currentIndex,
        string $rawLine,
        array $amounts
    ): bool {
        /*
        |--------------------------------------------------------------------------
        | Guard clause
        |--------------------------------------------------------------------------
        |
        | Usiamo questa strategia solo per righe che sembrano una riga articolo
        | da scontrino: IVA percentuale + importo.
        |
        */
        if (! $this->lineLooksLikeReceiptItemAmountLine($rawLine)) {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Se la riga contiene già una descrizione utile, lasciamo lavorare
        | il parser amount_based standard.
        |--------------------------------------------------------------------------
        */
        if ($this->extractDescription($rawLine)) {
            return false;
        }

        $description = $this->findNearbyProductDescription($lines, $currentIndex);

        if (! $description) {
            return false;
        }

        $productCode = $this->findNearbyProductCode($lines, $currentIndex);

        $price = end($amounts);

        DocumentLine::query()->create([
            'document_id' => $document->id,
            'document_line_type_id' => $lineTypeId,
            'line_number' => $currentIndex + 1,
            'raw_text' => trim($rawLine . ' ' . $description),
            'description' => $description,
            'quantity' => 1,
            'unit_price' => $price,
            'total_price' => $price,
            'confidence_score' => $this->estimateConfidenceScore(
                description: $description,
                amounts: $amounts,
                quantity: 1,
                productCode: $productCode,
            ),
            'metadata' => [
                'parser' => 'document_line_parser_v4',
                'mode' => 'receipt_ocr_split_amount_description',
                'amounts_found' => $amounts,
                'product_code_candidate' => $productCode,
                'amount_line' => $rawLine,
            ],
        ]);

        return true;
    }

    /**
     * Prova a creare una riga prodotto da uno scontrino OCR in cui
     * l'importo è su una riga e la descrizione prodotto sulla riga successiva.
     *
     * Esempio:
     * 749,90
     * SMARTPHONE ALPHA X1 256GB BLK
     */
    private function tryCreateReceiptOcrLineFromStandaloneAmountContext(
        Document $document,
        ?int $lineTypeId,
        array $lines,
        int $currentIndex,
        string $rawLine,
        array $amounts
    ): bool {
        /*
        |--------------------------------------------------------------------------
        | Guard clause sul tipo documento
        |--------------------------------------------------------------------------
        |
        | Questa euristica è pensata per scontrini OCR a colonne.
        | Su fatture o documenti diversi potrebbe creare falsi positivi.
        |
        */
        if ($document->documentType?->code !== 'receipt') {
            return false;
        }

        if (! $this->lineLooksLikeStandalonePositiveAmountLine($rawLine, $amounts)) {
            return false;
        }

        $description = $this->findFollowingProductDescriptionForStandaloneAmount($lines, $currentIndex)
            ?: $this->findPreviousProductDescriptionForStandaloneAmount($lines, $currentIndex);

        if (! $description) {
            return false;
        }

        $productCode = $this->findNearbyProductCode($lines, $currentIndex);

        $price = end($amounts);

        DocumentLine::query()->create([
            'document_id' => $document->id,
            'document_line_type_id' => $lineTypeId,
            'line_number' => $currentIndex + 1,
            'raw_text' => trim($rawLine . ' ' . $description),
            'description' => $description,
            'quantity' => 1,
            'unit_price' => $price,
            'total_price' => $price,
            'confidence_score' => $this->estimateConfidenceScore(
                description: $description,
                amounts: $amounts,
                quantity: 1,
                productCode: $productCode,
            ),
            'metadata' => [
                'parser' => 'document_line_parser_v5',
                'mode' => 'receipt_ocr_standalone_amount_description',
                'amounts_found' => $amounts,
                'product_code_candidate' => $productCode,
                'amount_line' => $rawLine,
                'description_line' => $description,
            ],
        ]);

        return true;
    }

    /**
     * Riconosce righe composte solo da un importo positivo.
     *
     * Esempi validi:
     * 749,90
     * 1.289,00
     * € 19,90
     *
     * Esempi non validi:
     * -30,00
     * 22,00%1.289,00
     * TOTALE 752,70
     */
    private function lineLooksLikeStandalonePositiveAmountLine(string $line, array $amounts): bool
    {
        $normalized = trim($line);

        if (count($amounts) !== 1) {
            return false;
        }

        if ((float) $amounts[0] <= 0) {
            return false;
        }

        if (str_starts_with($normalized, '-')) {
            return false;
        }

        return (bool) preg_match('/^(?:€\s*)?(?:\d{1,3}(?:[.\s]\d{3})+|\d+),\d{2}$/u', $normalized);
    }

    /**
     * Cerca una descrizione prodotto subito dopo una riga importo standalone.
     *
     * È volutamente prudente:
     * - salta eventuali righe vuote;
     * - si ferma se incontra subtotale, totale, IVA, pagamento, sconto;
     * - non cerca all'indietro, per evitare di associare SUBTOTALE all'ultimo prodotto.
     */
    private function findFollowingProductDescriptionForStandaloneAmount(array $lines, int $currentIndex): ?string
    {
        for ($offset = 1; $offset <= 3; $offset++) {
            $index = $currentIndex + $offset;

            if (! isset($lines[$index])) {
                return null;
            }

            $candidate = $this->normalizeLine($lines[$index]);

            if ($candidate === '') {
                continue;
            }

            if ($this->lineShouldBeIgnored($candidate)) {
                return null;
            }

            if ($this->lineIsStandaloneQuantity($candidate)) {
                return null;
            }

            if (! empty($this->extractAmountsFromText($candidate))) {
                return null;
            }

            if ($this->lineLooksLikeBarcode($candidate)) {
                return null;
            }

            if ($this->lineLooksLikeReceiptProductDescription($candidate)) {
                return $candidate;
            }

            return null;
        }

        return null;
    }

    /**
     * Cerca una descrizione prodotto subito prima di una riga importo standalone.
     *
     * Serve per OCR a colonne dove PaddleOCR può leggere:
     * CAVO USB-C 1M
     * 12,90
     *
     * È volutamente prudente: se incontra subtotali, totali, IVA,
     * pagamenti, sconti, barcode o importi, non crea nulla.
     */
    private function findPreviousProductDescriptionForStandaloneAmount(array $lines, int $currentIndex): ?string
    {
        for ($offset = -1; $offset >= -3; $offset--) {
            $index = $currentIndex + $offset;

            if (! isset($lines[$index])) {
                return null;
            }

            $candidate = $this->normalizeLine($lines[$index]);

            if ($candidate === '') {
                continue;
            }

            if ($this->lineShouldBeIgnored($candidate)) {
                return null;
            }

            if ($this->lineIsStandaloneQuantity($candidate)) {
                return null;
            }

            if (! empty($this->extractAmountsFromText($candidate))) {
                return null;
            }

            if ($this->lineLooksLikeBarcode($candidate)) {
                return null;
            }

            if ($this->lineLooksLikeReceiptProductDescription($candidate)) {
                return $candidate;
            }

            return null;
        }

        return null;
    }

    /**
     * Riconosce righe scontrino tipo:
     * 22,00%1.289,00
     * 10% 49,99
     */
    private function lineLooksLikeReceiptItemAmountLine(string $line): bool
    {
        return (bool) preg_match('/\b\d{1,2}(?:,\d{2})?\s*%\s*\d{1,3}(?:[.\s]\d{3})*,\d{2}|\b\d{1,2}(?:,\d{2})?\s*%\s*\d+,\d{2}/u', $line);
    }

    /**
     * Cerca una descrizione prodotto vicino alla riga importo OCR.
     */
    private function findNearbyProductDescription(array $lines, int $currentIndex): ?string
    {
        /*
        |--------------------------------------------------------------------------
        | Preferiamo le righe successive.
        |--------------------------------------------------------------------------
        |
        | In molti scontrini OCR Paddle legge prima la colonna IVA/prezzo e poi
        | la descrizione articolo. Se non troviamo nulla dopo, guardiamo prima.
        |
        */
        $offsetGroups = [
            [1, 2, 3, 4],
            [-1, -2, -3, -4],
        ];

        foreach ($offsetGroups as $offsets) {
            foreach ($offsets as $offset) {
                $index = $currentIndex + $offset;

                if (! isset($lines[$index])) {
                    continue;
                }

                $candidate = $this->normalizeLine($lines[$index]);

                if ($this->lineLooksLikeReceiptProductDescription($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Capisce se una riga OCR sembra una descrizione prodotto.
     */
    private function lineLooksLikeReceiptProductDescription(string $line): bool
    {
        if ($line === '') {
            return false;
        }

        if ($this->lineShouldBeIgnored($line)) {
            return false;
        }

        if ($this->lineIsStandaloneQuantity($line)) {
            return false;
        }

        if (! empty($this->extractAmountsFromText($line))) {
            return false;
        }

        if ($this->lineLooksLikeBarcode($line)) {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Deve contenere almeno lettere.
        |--------------------------------------------------------------------------
        */
        if (! preg_match('/[a-zA-ZÀ-ÿ]/u', $line)) {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Scarta rumore molto corto o troppo lungo.
        |--------------------------------------------------------------------------
        */
        if (mb_strlen($line) < 4 || mb_strlen($line) > 80) {
            return false;
        }

        $normalized = mb_strtolower($line);

        $badSignals = [
            'descrizione',
            'subtotale',
            'totale',
            'pagamento',
            'sconto',
            'iva',
            'punti',
            'cashback',
        ];

        foreach ($badSignals as $signal) {
            if (str_contains($normalized, $signal)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Cerca un codice prodotto/EAN vicino alla riga importo OCR.
     */
    private function findNearbyProductCode(array $lines, int $currentIndex): ?string
    {
        for ($offset = 1; $offset <= 6; $offset++) {
            $index = $currentIndex + $offset;

            if (! isset($lines[$index])) {
                continue;
            }

            $candidate = $this->normalizeLine($lines[$index]);

            if ($this->lineLooksLikeBarcode($candidate)) {
                return preg_replace('/\D+/', '', $candidate) ?: $candidate;
            }
        }

        for ($offset = 1; $offset <= 4; $offset++) {
            $index = $currentIndex + $offset;

            if (! isset($lines[$index])) {
                continue;
            }

            $candidate = $this->normalizeLine($lines[$index]);

            if ($this->lineLooksLikeProductCodePart($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Riconosce codici EAN/GTIN o barcode numerici comuni.
     */
    private function lineLooksLikeBarcode(string $line): bool
    {
        $digits = preg_replace('/\D+/', '', $line) ?: '';

        return (bool) preg_match('/^\d{8}$|^\d{12}$|^\d{13}$|^\d{14}$/', $digits);
    }

    /**
     * Crea una riga documento partendo da un candidato multi-riga.
     */
    private function createLineFromPendingCandidate(Document $document, ?int $lineTypeId, array $candidate): void
    {
        $description = trim(implode(' ', $candidate['description_parts']));
        $rawText = trim(implode(' ', $candidate['raw_text_parts']));
        $amounts = $candidate['amounts'] ?? [];
        $quantity = $candidate['quantity'] ?? null;
        $productCode = $candidate['product_code'] ?? null;

        DocumentLine::query()->create([
            'document_id' => $document->id,
            'document_line_type_id' => $lineTypeId,
            'line_number' => $candidate['line_number'],
            'raw_text' => $rawText,
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => $candidate['unit_price'] ?? null,
            'total_price' => $candidate['total_price'] ?? null,
            'confidence_score' => $this->estimateConfidenceScore(
                description: $description,
                amounts: $amounts,
                quantity: $quantity,
                productCode: $productCode,
            ),
            'metadata' => [
                'parser' => 'document_line_parser_v3',
                'mode' => $candidate['mode'] ?? 'pending_candidate',
                'amounts_found' => $amounts,
                'product_code_candidate' => $productCode,
            ],
        ]);
    }

    /**
     * Verifica se un candidato multi-riga è abbastanza utile da essere salvato.
     */
    private function pendingCandidateIsUsable(array $candidate): bool
    {
        $description = trim(implode(' ', $candidate['description_parts'] ?? []));

        if ($description === '') {
            return false;
        }

        if (mb_strlen($description) < 3) {
            return false;
        }

        return true;
    }

    /**
     * Normalizza una riga del testo estratto.
     */
    private function normalizeLine(string $line): string
    {
        return trim(preg_replace('/\s+/', ' ', $line) ?: '');
    }

    /**
     * Capisce se una riga interrompe il contesto prodotto.
     */
    private function lineBreaksProductContext(string $line): bool
    {
        $normalized = mb_strtolower($line);

        $breakSignals = [
            'destinatario',
            'destinazione',
            'codice descrizione',
            'quantità',
            'quantita',
            'prezzo',
            'sconto',
            'importo iva',
            'rif. conferma',
            'rif. ordine',
            'ordine:',
            'fase:',
            'tot. documento',
            'totale',
            'incaricato del trasporto',
            'causale del trasporto',
            'firma',
            'operatore',
            'note',
            'nr. colli',
            'privacy',
            'contributo conai',
        ];

        foreach ($breakSignals as $signal) {
            if (str_contains($normalized, $signal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Esclude righe che non rappresentano prodotti.
     */
    private function lineShouldBeIgnored(string $line): bool
    {
        $normalized = mb_strtolower($line);

        $ignoredSignals = [
            'totale',
            'tot. documento',
            'tot documento',
            'totale documento',
            'totale complessivo',
            'importo totale',
            'pagamento',
            'bancomat',
            'contanti',
            'resto',
            'destinatario',
            'destinazione',
            'firma',
            'porto',
            'privacy',
            'normativa',
            'reg. ue',
            'contributo conai',
            'rif. conferma',
            'conferma d\'ordine',
            'rif. ordine',
            'causale del trasporto',
            'incaricato del trasporto',
            'nr. colli',
            'peso',
            'aspetto esteriore',
            'p.iva',
            'partita iva',
            'c.f.',
            'iban',
            'banca',
            'pec',
            'e-mail',
            'email',
            'pag.',
        ];

        foreach ($ignoredSignals as $signal) {
            if (str_contains($normalized, $signal)) {
                return true;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | IVA come parola autonoma
        |--------------------------------------------------------------------------
        |
        | Non usiamo str_contains($line, 'iva'), perché parole come "DIVANO"
        | contengono la sequenza "iva" ma non sono righe IVA.
        |
        */
        if (preg_match('/\biva\b/u', $normalized)) {
            return true;
        }

        return false;
    }

    /**
     * Estrae un codice prodotto da una riga che inizia con codice + descrizione.
     */
    private function extractProductCodeFromLine(string $line): ?string
    {
        $productStart = $this->extractProductStartFromLine($line);

        return $productStart['code'] ?? null;
    }

    /**
     * Estrae coppia codice prodotto + descrizione.
     */
    private function extractProductStartFromLine(string $line): ?array
    {
        /*
        |--------------------------------------------------------------------------
        | Esempi supportati:
        | PRD-IMMK3163 Divano Fabiola Lounge
        | ABC123 Lavatrice Modello X
        |--------------------------------------------------------------------------
        */
        if (! preg_match('/^(?<code>[A-Z]{2,}[A-Z0-9\-\/\.]*\d[A-Z0-9\-\/\.]*)\s+(?<description>.+)$/iu', $line, $matches)) {
            return null;
        }

        $code = trim($matches['code']);
        $description = trim($matches['description']);

        if ($description === '' || mb_strlen($description) < 3) {
            return null;
        }

        if ($this->lineShouldBeIgnored($description)) {
            return null;
        }

        return [
            'code' => $code,
            'description' => $description,
        ];
    }

    /**
     * Capisce se una riga sembra un pezzo di codice prodotto.
     */
    private function lineLooksLikeProductCodePart(string $line): bool
    {
        $normalized = mb_strtoupper(trim($line));

        if (mb_strlen($normalized) < 2 || mb_strlen($normalized) > 30) {
            return false;
        }

        $blockedWords = [
            'LOGO',
            'DEBUG',
            'DESTINATARIO',
            'DESTINAZIONE',
            'PREZZO',
            'IVATO',
            'SCONTO',
            'IMPORTO',
            'IVA',
            'CODICE',
            'DESCRIZIONE',
            'QUANTITA',
            'QUANTITÀ',
            'FIRMA',
            'PORTO',
            'PESO',
            'OPERATORE',
            'NOTE',
            'PAG',
            'PAG.',
        ];

        if (in_array($normalized, $blockedWords, true)) {
            return false;
        }

        if (! preg_match('/^[A-Z0-9][A-Z0-9\-\/\.]*$/u', $normalized)) {
            return false;
        }

        return str_contains($normalized, '-')
            || preg_match('/\d/u', $normalized);
    }

    /**
     * Ricompone eventuali pezzi di codice prodotto.
     */
    private function buildProductCode(array $pendingCodeParts): ?string
    {
        if (empty($pendingCodeParts)) {
            return null;
        }

        $code = implode('', $pendingCodeParts);
        $code = preg_replace('/\s+/', '', $code) ?: $code;

        return $code !== '' ? $code : null;
    }

    /**
     * Capisce se una riga può completare una descrizione prodotto.
     */
    private function lineLooksLikeDescriptionContinuation(string $line): bool
    {
        if ($this->lineShouldBeIgnored($line)) {
            return false;
        }

        if ($this->lineLooksLikeProductCodePart($line)) {
            return false;
        }

        if ($this->lineIsStandaloneQuantity($line)) {
            return false;
        }

        if (! empty($this->extractAmountsFromText($line))) {
            return false;
        }

        if (str_contains($line, '@')) {
            return false;
        }

        if (mb_strlen($line) < 2 || mb_strlen($line) > 120) {
            return false;
        }

        return true;
    }

    /**
     * Estrae una descrizione candidata rimuovendo importi, simboli, quantità e codice prodotto iniziale.
     */
    private function extractDescription(string $line): ?string
    {
        $description = $line;

        $productCode = $this->extractProductCodeFromLine($line);

        if ($productCode) {
            $description = preg_replace('/^' . preg_quote($productCode, '/') . '\s+/u', '', $description) ?: $description;
        }

        $description = preg_replace('/€\s*/u', '', $description) ?: $description;
        $description = preg_replace('/\d{1,3}(?:[.\s]\d{3})*,\d{2}|\d+,\d{2}/u', '', $description) ?: $description;
        $description = preg_replace('/\b\d{1,3}\b/u', '', $description) ?: $description;

        $description = trim(preg_replace('/\s+/', ' ', $description) ?: '');

        if ($description === '') {
            return null;
        }

        if (mb_strlen($description) < 3) {
            return null;
        }

        $lowerDescription = mb_strtolower($description);

        $badDescriptions = [
            'codice descrizione',
            'prezzo',
            'sconto',
            'importo iva',
        ];

        foreach ($badDescriptions as $badDescription) {
            if ($lowerDescription === $badDescription || str_contains($lowerDescription, $badDescription)) {
                return null;
            }
        }

        if (preg_match('/\biva\b/u', $lowerDescription)) {
            return null;
        }

        return $description;
    }

    /**
     * Estrae una quantità candidata dal testo prima del primo importo.
     */
    private function extractQuantityBeforeFirstAmount(string $line): ?float
    {
        $parts = preg_split('/(?:€\s*)?\d{1,3}(?:[.\s]\d{3})*,\d{2}|(?:€\s*)?\d+,\d{2}/u', $line);

        $beforeFirstAmount = trim($parts[0] ?? '');

        if ($beforeFirstAmount === '') {
            return null;
        }

        if (preg_match_all('/\b\d{1,3}(?:[,.]\d{1,3})?\b/u', $beforeFirstAmount, $matches)) {
            $rawQuantity = end($matches[0]);

            return $this->parseQuantity($rawQuantity);
        }

        return null;
    }

    /**
     * Inferisce la quantità per conferme ordine / documenti e-commerce.
     *
     * Nei documenti e-commerce la quantità è spesso una colonna separata, ma OCR/PDF
     * possono perderla. In questi casi è più affidabile usare il rapporto tra
     * totale riga e prezzo unitario.
     *
     * Esempi:
     * - unitario 249,90 e totale 249,90 => quantità 1
     * - unitario 6,50 e totale 13,00 => quantità 2
     *
     * Questo evita anche falsi positivi come:
     * "Kit 6 sacchetti ..." interpretato come quantità 6.
     */
    private function inferOrderConfirmationQuantity(
        ?float $extractedQuantity,
        ?float $unitPrice,
        ?float $totalPrice
    ): ?float {
        if ($unitPrice !== null && $totalPrice !== null && $unitPrice > 0 && $totalPrice > 0) {
            $ratio = $totalPrice / $unitPrice;
            $roundedRatio = round($ratio);

            if ($roundedRatio >= 1 && abs($ratio - $roundedRatio) < 0.01) {
                return (float) $roundedRatio;
            }
        }

        return $extractedQuantity;
    }

    /**
     * Capisce se una riga è solo una quantità.
     */
    private function lineIsStandaloneQuantity(string $line): bool
    {
        return (bool) preg_match('/^\d{1,3}(?:[,.]\d{1,3})?$/u', trim($line));
    }

    /**
     * Converte una quantità testuale in numero.
     */
    private function parseQuantity(string $quantity): ?float
    {
        $normalized = str_replace(',', '.', trim($quantity));

        if (! is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    }

    /**
     * Estrae importi in formato italiano/europeo.
     */
    private function extractAmountsFromText(string $text): array
    {
        preg_match_all('/(?:€\s*)?(?<amount>\d{1,3}(?:[.\s]\d{3})*,\d{2}|\d+,\d{2})/u', $text, $matches);

        $amounts = [];

        foreach ($matches['amount'] ?? [] as $rawAmount) {
            $normalized = str_replace(['.', ' '], '', $rawAmount);
            $normalized = str_replace(',', '.', $normalized);

            if (! is_numeric($normalized)) {
                continue;
            }

            $amounts[] = (float) $normalized;
        }

        return $amounts;
    }

    /**
     * Pattern codice fattura.
     *
     * Supporta:
     * - codici con trattino: USED-R14, FOOD-PASTA, CLEAN-SAN
     * - codici alfanumerici: EL1001
     * - codici testuali brevi usati come riga contabile: SCONTO
     */
    private function invoiceCodePattern(): string
    {
        return '(?:[A-Z]{2,}(?:-[A-Z0-9]+)+|[A-Z]{2,}\d[A-Z0-9\-\/\.]*|[A-Z]{3,})';
    }

    /**
     * Stima semplice della qualità della riga candidata.
     */
    private function estimateConfidenceScore(
        string $description,
        array $amounts,
        ?float $quantity,
        ?string $productCode
    ): int {
        $score = 35;

        if (mb_strlen($description) >= 8) {
            $score += 20;
        }

        if ($productCode) {
            $score += 20;
        }

        if ($quantity !== null) {
            $score += 15;
        }

        if (! empty($amounts)) {
            $score += 15;
        }

        if (count($amounts) >= 2) {
            $score += 10;
        }

        return min($score, 100);
    }
}