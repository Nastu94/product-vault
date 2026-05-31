<?php

namespace App\Services\Documents\InvoiceTableExtraction;

use App\Models\Document;
use App\Services\Documents\DocumentOcrLayoutResolver;

/**
 * Estrae righe fattura usando gli OCR items e le coordinate geometriche.
 *
 * Questo extractor serve quando le visual_lines fondono righe diverse,
 * ma gli item OCR separati sono ancora posizionati correttamente nelle colonne.
 */
class OcrGeometryInvoiceTableExtractor implements InvoiceTableExtractor
{   
    /**
     * Identificatore della strategia di estrazione, usato per tracciare la provenienza dei dati e 
     * per eventuali logiche differenziate a valle.
     */
    private const STRATEGY = 'ocr_geometry_items';
    
    /**
     * Il layout resolver è responsabile di fornire gli item OCR normalizzati e filtrati per un documento.
     * L'inject del resolver permette di mantenere questa classe focalizzata sull'estrazione logica, 
     *  delegando la complessità della gestione degli item OCR a un componente dedicato.
     * Il scorer è usato per assegnare un punteggio di affidabilità al risultato dell'estrazione, 
     *  basato su metriche come il numero di righe estratte, la copertura dei codici, la presenza di warning, ecc.
     *  Questo permette alla quality gate di decidere se il risultato è accettabile per l'uso automatico.
     * L'inject del document line writer permette di scrivere le righe estratte nel database in modo coerente 
     *  con il resto della pipeline, e di centralizzare la logica di mapping tra i dati estratti e il modello DocumentLine.
     */
    public function __construct(
        private readonly DocumentOcrLayoutResolver $layoutResolver,
        private readonly InvoiceTableExtractionScorer $scorer,
    ) {
    }

    /**
     * Esegue l'estrazione delle righe fattura da un documento, restituendo un risultato strutturato che include le righe estratte,
     *  eventuali warning e metadata utili per valutare la qualità dell'estrazione.
     * Il processo di estrazione include:
     * 1. Risoluzione del layout OCR per ottenere gli item normalizzati.
     * 2. Rilevamento delle colonne della tabella fattura basato sulla posizione degli header.
     * 3. Rilevamento dei bounds verticali della tabella per filtrare gli item rilevanti.
     * 4. Identificazione degli item che sembrano codici prodotto e costruzione delle righe fattura candidate.
     * 5. Assegnazione di un punteggio di affidabilità al risultato e restituzione del risultato finale.
     *
     * Questa classe non modifica il database: si limita a restituire i dati estratti in una struttura 
     * che può essere poi valutata e scritta a valle.
     */
    public function extract(Document $document): InvoiceTableExtractionResult
    {
        $layout = $this->layoutResolver->resolve($document);

        $items = $this->normalizeItems($layout['items'] ?? []);

        if ($items === []) {
            return InvoiceTableExtractionResult::empty(self::STRATEGY, ['no_ocr_items']);
        }

        $columns = $this->detectColumns($items);

        if ($columns === null) {
            return InvoiceTableExtractionResult::empty(self::STRATEGY, ['invoice_columns_not_detected']);
        }

        $bounds = $this->detectTableBounds($items, $columns);
        $codeItems = $this->findCodeItems($items, $columns, $bounds);

        if ($codeItems === []) {
            return InvoiceTableExtractionResult::empty(self::STRATEGY, ['no_code_items_detected']);
        }

        $rows = [];
        $warnings = [];

        foreach ($codeItems as $index => $codeItem) {
            $code = $this->normalizeCode((string) ($codeItem['text'] ?? ''));

            if ($this->codeShouldBeSkipped($code)) {
                continue;
            }

            $rowBounds = $this->rowBoundsForCodeItem($codeItems, $index, $bounds);

            $row = $this->buildRowCandidate(
                items: $items,
                codeItem: $codeItem,
                columns: $columns,
                rowBounds: $rowBounds
            );

            if ($row === null) {
                $warnings[] = 'geometry_row_not_built';

                continue;
            }

            $rows[] = $row;
        }

        $expectedCodeRows = count(array_filter(
            $codeItems,
            fn (array $item): bool => ! $this->codeShouldBeSkipped(
                $this->normalizeCode((string) ($item['text'] ?? ''))
            )
        ));

        $coverageRatio = $expectedCodeRows > 0
            ? round(count($rows) / $expectedCodeRows, 2)
            : null;

        $result = new InvoiceTableExtractionResult(
            strategy: self::STRATEGY,
            rows: $rows,
            warnings: $warnings,
            metadata: [
                'items_count' => count($items),
                'expected_code_rows' => $expectedCodeRows,
                'extracted_rows' => count($rows),
                'coverage_ratio' => $coverageRatio,
                'columns' => [
                    'code_x' => $columns['code']['x'],
                    'description_x' => $columns['description']['x'],
                    'quantity_x' => $columns['quantity']['x'],
                    'vat_x' => $columns['vat']['x'],
                    'unit_price_x' => $columns['unit_price']['x'],
                    'total_x' => $columns['total']['x'],
                ],
                'bounds' => $bounds,
            ],
        );

        return $this->scorer->score($result);
    }

    /**
     * Normalizza gli item OCR filtrando quelli con testo vuoto, convertendo le coordinate in float e calcolando i centri.
     * Restituisce una lista di item ordinati top-left to bottom-right, pronti per essere processati nelle fasi successive.
     */
    private function normalizeItems(array $items): array
    {
        return collect($items)
            ->filter(fn (array $item): bool => trim((string) ($item['text'] ?? '')) !== '')
            ->map(fn (array $item): array => [
                'id' => $item['id'] ?? null,
                'text' => $this->normalizeLine((string) ($item['text'] ?? '')),
                'x1' => $this->floatOrNull($item['x1'] ?? null),
                'y1' => $this->floatOrNull($item['y1'] ?? null),
                'x2' => $this->floatOrNull($item['x2'] ?? null),
                'y2' => $this->floatOrNull($item['y2'] ?? null),
                'center_x' => $this->floatOrNull($item['center_x'] ?? null),
                'center_y' => $this->floatOrNull($item['center_y'] ?? null),
            ])
            ->filter(fn (array $item): bool => $item['center_x'] !== null && $item['center_y'] !== null)
            ->sortBy(fn (array $item): string => sprintf(
                '%010.3f-%010.3f',
                $item['center_y'],
                $item['center_x']
            ))
            ->values()
            ->all();
    }
    
    /**
     * Rileva le colonne della tabella fattura cercando un gruppo coerente di header.
     *
     * Non deve prendere testi generici tipo "Cod. Fisc." fuori tabella.
     * Per questo:
     * - parte da "Descrizione";
     * - cerca "Cod." a sinistra e vicino verticalmente;
     * - cerca Qta / IVA / Unitario / Totale a destra e vicino verticalmente;
     * - valida l'ordine orizzontale delle colonne.
     */
    private function detectColumns(array $items): ?array
    {
        $descriptionCandidates = $this->findHeaderCandidates(
            items: $items,
            aliases: ['descrizione'],
            allowContains: false
        );

        foreach ($descriptionCandidates as $description) {
            $descriptionX = (float) $description['center_x'];
            $descriptionY = (float) $description['center_y'];

            $code = $this->findHeaderCandidateNear(
                items: $items,
                aliases: ['cod.', 'codice', 'cod'],
                referenceY: $descriptionY,
                minX: null,
                maxX: $descriptionX - 20.0,
                maxYDistance: 90.0,
                allowContains: false
            );

            $quantity = $this->findHeaderCandidateNear(
                items: $items,
                aliases: ['qta', 'qtà', 'quantita', 'quantità'],
                referenceY: $descriptionY,
                minX: $descriptionX + 120.0,
                maxX: null,
                maxYDistance: 90.0,
                allowContains: false
            );

            $vat = $this->findHeaderCandidateNear(
                items: $items,
                aliases: ['iva'],
                referenceY: $descriptionY,
                minX: $descriptionX + 120.0,
                maxX: null,
                maxYDistance: 90.0,
                allowContains: false
            );

            $unitPrice = $this->findHeaderCandidateNear(
                items: $items,
                aliases: ['unitario', 'prezzo'],
                referenceY: $descriptionY,
                minX: $descriptionX + 120.0,
                maxX: null,
                maxYDistance: 90.0,
                allowContains: true
            );

            $total = $this->findHeaderCandidateNear(
                items: $items,
                aliases: ['totale'],
                referenceY: $descriptionY,
                minX: $descriptionX + 120.0,
                maxX: null,
                maxYDistance: 90.0,
                allowContains: false
            );

            if (! $code || ! $quantity || ! $vat || ! $unitPrice || ! $total) {
                continue;
            }

            $orderedColumns = [
                'code' => (float) $code['center_x'],
                'description' => (float) $description['center_x'],
                'quantity' => (float) $quantity['center_x'],
                'vat' => (float) $vat['center_x'],
                'unit_price' => (float) $unitPrice['center_x'],
                'total' => (float) $total['center_x'],
            ];

            if (! $this->invoiceHeaderColumnsAreHorizontallyCoherent($orderedColumns)) {
                continue;
            }

            return [
                'code' => [
                    'x' => $orderedColumns['code'],
                    'header' => $code,
                ],
                'description' => [
                    'x' => $orderedColumns['description'],
                    'header' => $description,
                ],
                'quantity' => [
                    'x' => $orderedColumns['quantity'],
                    'header' => $quantity,
                ],
                'vat' => [
                    'x' => $orderedColumns['vat'],
                    'header' => $vat,
                ],
                'unit_price' => [
                    'x' => $orderedColumns['unit_price'],
                    'header' => $unitPrice,
                ],
                'total' => [
                    'x' => $orderedColumns['total'],
                    'header' => $total,
                ],
            ];
        }

        return null;
    }

    /**
     * Trova un header tabellare.
     *
     * Per default usa match esatto per evitare falsi positivi tipo:
     * "P.IVA ... Cod. Fisc."
     */
    private function findHeaderItem(array $items, array $aliases): ?array
    {
        $candidates = $this->findHeaderCandidates(
            items: $items,
            aliases: $aliases,
            allowContains: false
        );

        return $candidates[0] ?? null;
    }

    /**
     * Trova tutti gli item che sembrano header di colonna.
     */
    private function findHeaderCandidates(array $items, array $aliases, bool $allowContains = false): array
    {
        $candidates = [];

        foreach ($items as $item) {
            $text = (string) ($item['text'] ?? '');

            if (! $this->headerTextMatches($text, $aliases, $allowContains)) {
                continue;
            }

            if ($this->floatOrNull($item['center_x'] ?? null) === null || $this->floatOrNull($item['center_y'] ?? null) === null) {
                continue;
            }

            $candidates[] = $item;
        }

        usort(
            $candidates,
            fn (array $a, array $b): int => ((float) $a['center_y']) <=> ((float) $b['center_y'])
                ?: ((float) $a['center_x']) <=> ((float) $b['center_x'])
        );

        return $candidates;
    }

    /**
     * Trova un header vicino verticalmente a un riferimento e, opzionalmente,
     * dentro un range orizzontale.
     */
    private function findHeaderCandidateNear(
        array $items,
        array $aliases,
        float $referenceY,
        ?float $minX = null,
        ?float $maxX = null,
        float $maxYDistance = 80.0,
        bool $allowContains = false
    ): ?array {
        $candidates = [];

        foreach ($items as $item) {
            $text = (string) ($item['text'] ?? '');

            if (! $this->headerTextMatches($text, $aliases, $allowContains)) {
                continue;
            }

            $centerX = $this->floatOrNull($item['center_x'] ?? null);
            $centerY = $this->floatOrNull($item['center_y'] ?? null);

            if ($centerX === null || $centerY === null) {
                continue;
            }

            if ($minX !== null && $centerX < $minX) {
                continue;
            }

            if ($maxX !== null && $centerX > $maxX) {
                continue;
            }

            $verticalDistance = abs($centerY - $referenceY);

            if ($verticalDistance > $maxYDistance) {
                continue;
            }

            $candidates[] = [
                'item' => $item,
                'vertical_distance' => $verticalDistance,
                'center_x' => $centerX,
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, function (array $a, array $b): int {
            $vertical = $a['vertical_distance'] <=> $b['vertical_distance'];

            if ($vertical !== 0) {
                return $vertical;
            }

            return $a['center_x'] <=> $b['center_x'];
        });

        return $candidates[0]['item'];
    }

    /**
     * Match prudente per testo header.
     */
    private function headerTextMatches(string $text, array $aliases, bool $allowContains = false): bool
    {
        $normalized = mb_strtolower(trim($text));
        $normalized = rtrim($normalized, " \t\n\r\0\x0B:");

        if ($normalized === '') {
            return false;
        }

        foreach ($aliases as $alias) {
            $alias = mb_strtolower(trim($alias));
            $alias = rtrim($alias, " \t\n\r\0\x0B:");

            if ($normalized === $alias) {
                return true;
            }

            /*
            |--------------------------------------------------------------------------
            | Contains controllato
            |--------------------------------------------------------------------------
            |
            | Utile per header tipo "Prezzo unitario".
            | Non va usato per "cod", perché prenderebbe "Cod. Fisc.".
            */
            if ($allowContains && mb_strlen($normalized) <= 30 && str_contains($normalized, $alias)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica che le colonne siano ordinate da sinistra verso destra.
     */
    private function invoiceHeaderColumnsAreHorizontallyCoherent(array $columns): bool
    {
        return $columns['code'] < $columns['description']
            && $columns['description'] < $columns['quantity']
            && $columns['quantity'] < $columns['vat']
            && $columns['vat'] < $columns['unit_price']
            && $columns['unit_price'] < $columns['total'];
    }

    /**
     * Rileva i bounds verticali della tabella fattura basandosi sulla posizione degli header e su eventuali linee che sembrano indicare la fine della tabella.
     * Il bound superiore è calcolato come il massimo y2 degli header meno una piccola tolleranza, per includere eventuali righe che toccano gli header.
     * Il bound inferiore è calcolato cercando linee che contengono parole come "totale" o "imponibile", che spesso indicano la fine della tabella, e prendendo il minimo y1 tra queste linee.
     * Se non vengono trovati header o linee di fine tabella, si usano dei valori di default per cercare di includere il maggior numero possibile di righe, anche a costo di includere rumore.
     */
    private function detectTableBounds(array $items, array $columns): array
    {
        $headerY2 = array_values(array_filter(array_map(
            fn (array $column): ?float => $this->floatOrNull($column['header']['y2'] ?? null),
            $columns
        )));

        $startY = $headerY2 !== []
            ? max($headerY2) - 10.0
            : 0.0;

        $endY = null;

        foreach ($items as $item) {
            $text = (string) ($item['text'] ?? '');
            $y1 = $this->floatOrNull($item['y1'] ?? null);

            if ($y1 === null || $y1 <= $startY) {
                continue;
            }

            if ($this->lineEndsTable($text)) {
                $endY = $endY === null ? $y1 : min($endY, $y1);
            }
        }

        return [
            'start_y' => $startY,
            'end_y' => $endY,
        ];
    }

    /**
     * Trova gli item che sembrano essere codici prodotto, basandosi sulla posizione rispetto agli header e su pattern comuni di codici.
     * Gli item devono essere all'interno dei bounds della tabella, avere un centro x vicino alla colonna del codice, e non devono essere troppo vicini alla colonna della descrizione per evitare di prendere descrizioni lunghe che si estendono sotto il codice.
     * Vengono ordinati top-left to bottom-right per mantenere l'ordine originale del documento, e per facilitare la costruzione delle righe fattura basate sulla vicinanza verticale tra i codici.
     * Vengono esclusi i codici che sembrano essere numeri di serie o codici a barre, che non rappresentano righe fattura reali.
     */
    private function findCodeItems(array $items, array $columns, array $bounds): array
    {
        $codeItems = [];
        $codeX = (float) $columns['code']['x'];
        $descriptionX = (float) $columns['description']['x'];

        foreach ($items as $item) {
            if (! $this->itemInsideTableBounds($item, $bounds)) {
                continue;
            }

            $text = $this->normalizeCode((string) ($item['text'] ?? ''));

            if (! $this->looksLikeCodeOrAccountingCode($text)) {
                continue;
            }

            $centerX = $this->floatOrNull($item['center_x'] ?? null);

            if ($centerX === null) {
                continue;
            }

            if ($centerX > ($descriptionX - 20)) {
                continue;
            }

            if (abs($centerX - $codeX) > 120) {
                continue;
            }

            $codeItems[] = $item;
        }

        usort(
            $codeItems,
            fn (array $a, array $b): int => ((float) $a['center_y']) <=> ((float) $b['center_y'])
        );

        return $codeItems;
    }
    
    /**
     * Calcola i bounds verticali specifici per una riga fattura basata sulla posizione di un item codice, considerando la posizione del codice stesso, del codice successivo e del bound inferiore della tabella.
     * Il bound superiore è calcolato come il centro y del codice meno una tolleranza, per includere eventuali righe che toccano il codice.
     * Il bound di fine contenuto è calcolato come il centro y del codice successivo meno una tolleranza, per cercare di includere solo le righe che appartengono alla stessa riga fattura, e non estendere troppo verso il basso in caso di righe molto lunghe o di righe multiple agganciate al codice.
     * Il bound di fine importi è calcolato come il centro y del codice successivo più una tolleranza, per cercare di includere eventuali importi che sono agganciati visivamente alla riga del codice, anche se si estendono un po' più in basso.
     * Il bound inferiore della tabella viene usato come fallback se non c'è un codice successivo, per cercare di includere tutte le righe fino alla fine della tabella, anche se questo può includere rumore in caso di righe molto lunghe o di righe multiple agganciate al codice.
     * Questi bounds vengono poi usati per filtrare gli item che appartengono alla stessa riga fattura, distinguendo tra quelli che fanno parte del contenuto principale (descrizione, codice) e quelli che fanno parte degli importi (quantità, prezzo), e permettendo di costruire righe fattura più accurate basate sulla vicinanza verticale degli item.
     * Il grace sulle colonne importo è particolarmente importante per gestire i casi in cui gli importi sono visivamente agganciati alla riga del codice, ma si estendono un po' più in basso, e per evitare di perdere questi dati importanti a causa di righe molto lunghe o di righe multiple agganciate al codice.
     * Questa logica di calcolo dei bounds è fondamentale per l'estrazione basata sulla geometria degli item OCR, e permette di gestire una varietà di layout e formati di fattura, cercando di massimizzare l'accuratezza dell'estrazione delle righe fattura.
     * Questi bounds non sono rigidi, ma servono come guida per filtrare gli item e costruire le righe fattura, e possono essere adattati o modificati in base alle specificità dei documenti e dei layout incontrati.
     * In particolare, il grace sulle colonne importo è una soluzione pragmatica per gestire la variabilità dei layout e dei formati di fattura, e per cercare di includere dati importanti che altrimenti potrebbero essere persi a causa di righe molto lunghe o di righe multiple agganciate al codice.
     * Questa funzione è un esempio di come la logica di estrazione basata sulla geometria degli item OCR può essere complessa e richiedere una serie di regole e tolleranze per cercare di gestire la variabilità dei documenti e dei layout, e per cercare di massimizzare l'accuratezza dell'estrazione delle righe fattura.
     * Questi bounds vengono poi usati nelle funzioni di filtraggio degli item per costruire le righe fattura, e per assegnare i dati estratti alle colonne corrette (descrizione, quantità, prezzo, ecc.) in base alla loro posizione rispetto al codice.
     * Questa logica di calcolo dei bounds è particolarmente importante per gestire i casi in cui le righe fattura sono molto lunghe o ci sono più righe agganciate allo stesso codice, e per cercare di includere tutti i dati rilevanti senza includere troppo rumore.
     */
    private function rowBoundsForCodeItem(array $codeItems, int $index, array $tableBounds): array
    {
        $currentCodeY = (float) ($codeItems[$index]['center_y'] ?? 0);
        $nextCodeY = isset($codeItems[$index + 1])
            ? (float) ($codeItems[$index + 1]['center_y'] ?? 0)
            : null;

        $tableEndY = $tableBounds['end_y'] ?? null;

        $contentEndY = $nextCodeY !== null
            ? $nextCodeY - 8.0
            : ($tableEndY ?? PHP_FLOAT_MAX);

        /*
        |--------------------------------------------------------------------------
        | Grace sulle colonne importo
        |--------------------------------------------------------------------------
        |
        | Nelle scansioni gli importi possono essere agganciati visivamente alla
        | riga del codice successivo. Il grace è applicato solo agli item numerici,
        | non alle descrizioni.
        */
        $amountEndY = $nextCodeY !== null
            ? $nextCodeY + 14.0
            : ($tableEndY ?? PHP_FLOAT_MAX);

        return [
            'start_y' => max(0.0, $currentCodeY - 18.0),
            'content_end_y' => $contentEndY,
            'amount_end_y' => $amountEndY,
            'table_end_y' => $tableEndY,
        ];
    }
    
    /**
     * Verifica se un item è all'interno dei bounds verticali della tabella, basandosi sulle coordinate y1 e y2 dell'item e sui bounds calcolati per la tabella.
     * Questa funzione viene usata per filtrare gli item che appartengono alla tabella fattura, e per escludere quelli che sono al di fuori dei bounds, come header, footer, o altre parti del documento che non fanno parte della tabella.
     * I bounds vengono calcolati in modo da cercare di includere tutte le righe che appartengono alla tabella, anche a costo di includere un po' di rumore, per cercare di massimizzare l'accuratezza dell'estrazione delle righe fattura.
     * In particolare, il bound inferiore della tabella viene usato come fallback per includere tutte le righe fino alla fine della tabella, anche se questo può includere rumore in caso di righe molto lunghe o di righe multiple agganciate al codice, ma permette di evitare di perdere dati importanti che si trovano in queste situazioni.
     * Questa funzione è fondamentale per l'estrazione basata sulla geometria degli item OCR, e permette di gestire una varietà di layout e formati di fattura, cercando di massimizzare l'accuratezza dell'estrazione delle righe fattura.
     * Questi bounds non sono rigidi, ma servono come guida per filtrare gli item e costruire le righe fattura, e possono essere adattati o modificati in base alle specificità dei documenti e dei layout incontrati.
     */
    private function buildRowCandidate(
        array $items,
        array $codeItem,
        array $columns,
        array $rowBounds
    ): ?InvoiceRowCandidate {
        $code = $this->normalizeCode((string) ($codeItem['text'] ?? ''));

        $descriptionItems = $this->findDescriptionItems($items, $columns, $rowBounds);

        if ($descriptionItems === []) {
            return null;
        }

        $amountItems = $this->findAmountItems(
            items: $items,
            columns: $columns,
            rowBounds: $rowBounds,
            descriptionItems: $descriptionItems,
            codeItem: $codeItem
        );

        $description = null;
        $descriptionParts = [];
        $supportingLines = [];

        foreach ($descriptionItems as $item) {
            $text = trim((string) ($item['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            if ($this->lineLooksLikeTechnicalSupportingInfo($text)) {
                $supportingLines[] = $text;

                continue;
            }

            if ($description === null) {
                $description = $text;

                continue;
            }

            $descriptionParts[] = $text;
            $supportingLines[] = $text;
        }

        if ($description === null || $description === '') {
            return null;
        }

        $quantity = isset($amountItems['quantity'])
            ? $this->parseQuantity((string) $amountItems['quantity']['text'])
            : null;

        $vatRate = isset($amountItems['vat'])
            ? trim((string) $amountItems['vat']['text'])
            : null;

        $unitPrice = isset($amountItems['unit_price'])
            ? $this->parseMoney((string) $amountItems['unit_price']['text'])
            : null;

        $totalPrice = isset($amountItems['total'])
            ? $this->parseMoney((string) $amountItems['total']['text'])
            : null;

        if ($quantity === null && $unitPrice !== null && $totalPrice !== null && abs($unitPrice - $totalPrice) < 0.01) {
            $quantity = 1.0;
        }

        if ($unitPrice === null || $totalPrice === null) {
            return new InvoiceRowCandidate(
                code: $code,
                description: $description,
                descriptionParts: $descriptionParts,
                quantity: $quantity,
                vatRate: $vatRate,
                unitPrice: $unitPrice,
                totalPrice: $totalPrice,
                supportingLines: $supportingLines,
                ean: $this->extractEanFromLines($supportingLines),
                serialNumber: $this->extractSerialNumberFromLines($supportingLines),
                sourceItemIds: $this->sourceIds([$codeItem, ...$descriptionItems, ...array_values($amountItems)]),
                warnings: ['row_without_price'],
                metadata: [
                    'row_bounds' => $rowBounds,
                    'amount_anchor_y' => $this->amountAnchorY($descriptionItems, $codeItem),
                ],
            );
        }

        return new InvoiceRowCandidate(
            code: $code,
            description: $description,
            descriptionParts: $descriptionParts,
            quantity: $quantity,
            vatRate: $vatRate,
            unitPrice: $unitPrice,
            totalPrice: $totalPrice,
            supportingLines: $supportingLines,
            ean: $this->extractEanFromLines($supportingLines),
            serialNumber: $this->extractSerialNumberFromLines($supportingLines),
            sourceItemIds: $this->sourceIds([$codeItem, ...$descriptionItems, ...array_values($amountItems)]),
            warnings: [],
            metadata: [
                'row_bounds' => $rowBounds,
                'amount_anchor_y' => $this->amountAnchorY($descriptionItems, $codeItem),
            ],
        );
    }

    /**
     * La funzione findDescriptionItems è responsabile di identificare gli item OCR che appartengono alla descrizione di una riga fattura, basandosi sulla posizione rispetto agli header e sui bounds calcolati per la riga.
     * Gli item devono essere all'interno dei bounds della riga, avere un centro x tra la colonna della descrizione e la colonna della quantità, e non devono sembrare codici o importi per evitare di prendere righe che si estendono troppo a sinistra o a destra.
     * Vengono ordinati top-left to bottom-right per mantenere l'ordine originale del documento, e per facilitare la costruzione della descrizione completa basata sulla vicinanza verticale tra gli item.
     * Vengono esclusi i testi che sembrano essere informazioni tecniche di supporto, come "matricola", "seriale", "ean", ecc., che non fanno parte della descrizione principale ma possono essere utili come supporting lines.
     */
    private function findDescriptionItems(array $items, array $columns, array $rowBounds): array
    {
        $descriptionX = (float) $columns['description']['x'];
        $quantityX = (float) $columns['quantity']['x'];

        $matches = [];

        foreach ($items as $item) {
            if (! $this->itemInsideContentBounds($item, $rowBounds)) {
                continue;
            }

            $text = trim((string) ($item['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            if ($this->looksLikeCodeOrAccountingCode($text) || $this->looksLikeMoney($text) || $this->looksLikeVat($text)) {
                continue;
            }

            if ($this->textLooksLikeHeader($text) || $this->textLooksLikeAccountingAdjustment($text)) {
                continue;
            }

            $centerX = $this->floatOrNull($item['center_x'] ?? null);

            if ($centerX === null) {
                continue;
            }

            if ($centerX < ($descriptionX - 130) || $centerX > ($quantityX - 40)) {
                continue;
            }

            $matches[] = $item;
        }

        usort(
            $matches,
            fn (array $a, array $b): int => ((float) $a['center_y']) <=> ((float) $b['center_y'])
                ?: ((float) $a['center_x']) <=> ((float) $b['center_x'])
        );

        return $matches;
    }

    /**
     * La funzione findAmountItems è responsabile di identificare gli item OCR che appartengono alle colonne degli importi di una riga fattura, basandosi sulla posizione rispetto agli header e sui bounds calcolati per la riga.
     * Gli item devono essere all'interno dei bounds della riga e avere un centro x vicino alla colonna corrispondente, entro una tolleranza specificata.
     * Vengono esclusi i testi che sembrano essere aggiustamenti contabili, per evitare di confondere gli importi principali con informazioni di supporto.
     */
    private function findAmountItems(
        array $items,
        array $columns,
        array $rowBounds,
        array $descriptionItems,
        array $codeItem
    ): array {
        $result = [];
        $anchorY = $this->amountAnchorY($descriptionItems, $codeItem);

        $quantity = $this->findBestColumnItem(
            items: $items,
            columnX: (float) $columns['quantity']['x'],
            rowBounds: $rowBounds,
            kind: 'quantity',
            toleranceX: 75.0,
            anchorY: $anchorY
        );

        if ($quantity) {
            $result['quantity'] = $quantity;
        }

        $vat = $this->findBestColumnItem(
            items: $items,
            columnX: (float) $columns['vat']['x'],
            rowBounds: $rowBounds,
            kind: 'vat',
            toleranceX: 80.0,
            anchorY: $anchorY
        );

        if ($vat) {
            $result['vat'] = $vat;
        }

        $unitPrice = $this->findBestColumnItem(
            items: $items,
            columnX: (float) $columns['unit_price']['x'],
            rowBounds: $rowBounds,
            kind: 'money',
            toleranceX: 90.0,
            anchorY: $anchorY
        );

        if ($unitPrice) {
            $result['unit_price'] = $unitPrice;
        }

        $total = $this->findBestColumnItem(
            items: $items,
            columnX: (float) $columns['total']['x'],
            rowBounds: $rowBounds,
            kind: 'money',
            toleranceX: 90.0,
            anchorY: $anchorY
        );

        if ($total) {
            $result['total'] = $total;
        }

        return $result;
    }

    /**
     * La funzione findBestColumnItem è responsabile di identificare l'item OCR che meglio rappresenta una colonna specifica (quantità, IVA, prezzo unitario, totale) all'interno di una riga fattura.
     * Viene selezionato l'item più vicino al centro della colonna, rispettando una tolleranza orizzontale specificata, e ordinando i candidati per distanza orizzontale e posizione verticale.
     * Vengono esclusi i testi che sembrano essere aggiustamenti contabili, per evitare di confondere gli importi principali con informazioni di supporto.
     */
    private function findBestColumnItem(
        array $items,
        float $columnX,
        array $rowBounds,
        string $kind,
        float $toleranceX,
        float $anchorY
    ): ?array {
        $candidates = [];

        foreach ($items as $item) {
            if (! $this->itemInsideAmountBounds($item, $rowBounds)) {
                continue;
            }

            $text = trim((string) ($item['text'] ?? ''));

            if (! $this->itemMatchesKind($text, $kind)) {
                continue;
            }

            if ($this->textLooksLikeAccountingAdjustment($text)) {
                continue;
            }

            $centerX = $this->floatOrNull($item['center_x'] ?? null);
            $centerY = $this->floatOrNull($item['center_y'] ?? null);

            if ($centerX === null || $centerY === null) {
                continue;
            }

            $horizontalDistance = abs($centerX - $columnX);

            if ($horizontalDistance > $toleranceX) {
                continue;
            }

            $verticalDistance = abs($centerY - $anchorY);

            $candidates[] = [
                'item' => $item,
                'vertical_distance' => $verticalDistance,
                'horizontal_distance' => $horizontalDistance,
                'vertical_position' => $centerY,
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, function (array $a, array $b): int {
            /*
            |--------------------------------------------------------------------------
            | Priorità verticale
            |--------------------------------------------------------------------------
            |
            | In OCR scannerizzati gli importi possono appartenere alla riga precedente
            | pur essendo molto vicini alla colonna X della riga corrente.
            | Per questo scegliamo prima l'item più vicino al blocco descrittivo
            | della riga, poi la distanza orizzontale dalla colonna.
            */
            $vertical = $a['vertical_distance'] <=> $b['vertical_distance'];

            if ($vertical !== 0) {
                return $vertical;
            }

            $horizontal = $a['horizontal_distance'] <=> $b['horizontal_distance'];

            if ($horizontal !== 0) {
                return $horizontal;
            }

            return $a['vertical_position'] <=> $b['vertical_position'];
        });

        return $candidates[0]['item'];
    }

    /**
     * Calcola l'ancora verticale per scegliere gli importi della riga.
     *
     * Usiamo l'ultima riga descrittiva/supporting line del blocco:
     * - per prodotti multi-riga gli importi sono spesso allineati alla riga finale;
     * - evita di prendere importi slittati appartenenti al prodotto precedente.
     */
    private function amountAnchorY(array $descriptionItems, array $codeItem): float
    {
        $values = [];

        foreach ($descriptionItems as $item) {
            $centerY = $this->floatOrNull($item['center_y'] ?? null);

            if ($centerY !== null) {
                $values[] = $centerY;
            }
        }

        if ($values !== []) {
            return max($values);
        }

        return $this->floatOrNull($codeItem['center_y'] ?? null) ?? 0.0;
    }

    /**
     * Verifica se un item è all'interno dei bounds verticali della tabella, basandosi sulle coordinate y1 e y2 dell'item e sui bounds calcolati per la tabella.
     * Questa funzione viene usata per filtrare gli item che appartengono alla tabella fattura, e per escludere quelli che sono al di fuori dei bounds, come header, footer, o altre parti del documento che non fanno parte della tabella.
     * I bounds vengono calcolati in modo da cercare di includere tutte le righe che appartengono alla tabella, anche a costo di includere un po' di rumore, per cercare di massimizzare l'accuratezza dell'estrazione delle righe fattura.
     * In particolare, il bound inferiore della tabella viene usato come fallback per includere tutte le righe fino alla fine della tabella, anche se questo può includere rumore in caso di righe molto lunghe o di righe multiple agganciate al codice, ma permette di evitare di perdere dati importanti che si trovano in queste situazioni.
     * Questa funzione è fondamentale per l'estrazione basata sulla geometria degli item OCR, e permette di gestire una varietà di layout e formati di fattura, cercando di massimizzare l'accuratezza dell'estrazione delle righe fattura.
     * Questi bounds non sono rigidi, ma servono come guida per filtrare gli item e costruire le righe fattura, e possono essere adattati o modificati in base alle specificità dei documenti e dei layout incontrati.
     */
    private function itemInsideTableBounds(array $item, array $bounds): bool
    {
        $centerY = $this->floatOrNull($item['center_y'] ?? null);

        if ($centerY === null) {
            return false;
        }

        if ($centerY < (float) ($bounds['start_y'] ?? 0.0)) {
            return false;
        }

        if (($bounds['end_y'] ?? null) !== null && $centerY > (float) $bounds['end_y']) {
            return false;
        }

        return true;
    }

    /**
     * Verifica se un item è all'interno dei bounds del contenuto di una riga, basandosi sulle coordinate y1 e y2 dell'item e sui bounds calcolati per la riga.
     * Questa funzione viene usata per filtrare gli item che appartengono al contenuto principale della riga, escludendo eventuali header, footer o altre informazioni di supporto.
     */
    private function itemInsideContentBounds(array $item, array $rowBounds): bool
    {
        $centerY = $this->floatOrNull($item['center_y'] ?? null);

        if ($centerY === null) {
            return false;
        }

        return $centerY >= (float) $rowBounds['start_y']
            && $centerY <= (float) $rowBounds['content_end_y'];
    }

    /**
     * Verifica se un item è all'interno dei bounds degli importi di una riga, basandosi sulle coordinate y1 e y2 dell'item e sui bounds calcolati per la riga.
     * Questa funzione viene usata per filtrare gli item che appartengono alle colonne degli importi della riga, escludendo eventuali header, footer o altre informazioni di supporto.
     */ 
    private function itemInsideAmountBounds(array $item, array $rowBounds): bool
    {
        $centerY = $this->floatOrNull($item['center_y'] ?? null);

        if ($centerY === null) {
            return false;
        }

        return $centerY >= (float) $rowBounds['start_y']
            && $centerY <= (float) $rowBounds['amount_end_y'];
    }

    /**
     * Verifica se un testo sembra appartenere a una certa categoria (quantità, IVA, importo) basandosi su pattern comuni per ciascuna categoria.
     * Questa funzione viene usata per filtrare gli item che appartengono alle colonne degli importi, e per escludere quelli che non corrispondono al tipo di dato atteso, come descrizioni o codici.
     * I pattern usati sono abbastanza generici per cercare di includere la maggior parte dei casi, ma possono essere adattati o modificati in base alle specificità dei documenti e dei layout incontrati.
     */
    private function itemMatchesKind(string $text, string $kind): bool
    {
        return match ($kind) {
            'quantity' => preg_match('/^\d{1,3}(?:[,.]\d+)?$/u', trim($text)) === 1,
            'vat' => $this->looksLikeVat($text),
            'money' => $this->looksLikeMoney($text),
            default => false,
        };
    }

    /**
     * Verifica se un testo sembra essere un codice prodotto o un codice contabile, basandosi su pattern comuni.
     * Questo permette di escludere righe che non rappresentano voci fattura reali, come numeri di serie o codici a barre.
     * I pattern usati sono abbastanza generici per cercare di includere la maggior parte dei casi, ma possono essere adattati o modificati in base alle specificità dei documenti e dei layout incontrati.
     */
    private function looksLikeCodeOrAccountingCode(string $text): bool
    {
        $text = $this->normalizeCode($text);

        if ($text === '') {
            return false;
        }

        if (in_array($text, ['SCONTO', 'ACCONTO', 'STORNO', 'RIMBORSO'], true)) {
            return true;
        }

        return preg_match('/^[A-Z]{2,}(?:-[A-Z0-9]+)+$/u', $text) === 1
            || preg_match('/^[A-Z]{2,}\d[A-Z0-9\-\/\.]*$/u', $text) === 1;
    }

    /**
     * Normalizza un codice prodotto o contabile rimuovendo spazi, punti, trattini e altri caratteri non alfanumerici, e convertendo in maiuscolo.
     * Questo permette di confrontare i codici in modo più robusto, escludendo le variazioni di formattazione che non sono significative per l'identificazione del codice.
     */
    private function codeShouldBeSkipped(string $code): bool
    {
        $code = $this->normalizeCode($code);

        return in_array($code, [
            'SCONTO',
            'NOTA',
            'NOTE',
            'ACCONTO',
            'TOTALE',
            'SUBTOTALE',
            'RIEPILOGO',
            'IMPORTO',
            'STORNO',
            'RIMBORSO',
        ], true);
    }

    /**
     * Verifica se un testo sembra rappresentare un importo monetario, basandosi su pattern comuni.
     * I pattern usati sono abbastanza generici per cercare di includere la maggior parte dei casi, ma possono essere adattati o modificati in base alle specificità dei documenti e dei layout incontrati.
     */
    private function looksLikeMoney(string $text): bool
    {
        return preg_match('/^-?\d{1,3}(?:[.\s]\d{3})*,\d{2}$|^-?\d+,\d{2}$|^-?\d+\.\d{2}$/u', trim($text)) === 1;
    }

    /**
     * Verifica se un testo sembra rappresentare un'IVA, basandosi su pattern comuni.
     * I pattern usati sono abbastanza generici per cercare di includere la maggior parte dei casi, ma possono essere adattati o modificati in base alle specificità dei documenti e dei layout incontrati.
     */
    private function looksLikeVat(string $text): bool
    {
        return preg_match('/^\d{1,2}(?:[,.]\d{2})?%$/u', trim($text)) === 1;
    }

    /**
     * Verifica se una linea sembra segnare la fine di una tabella, basandosi su pattern comuni.
     * I pattern usati sono abbastanza generici per cercare di includere la maggior parte dei casi, ma possono essere adattati o modificati in base alle specificità dei documenti e dei layout incontrati.
     */
    private function lineEndsTable(string $line): bool
    {
        $normalized = mb_strtolower(trim($line));

        foreach ([
            'note per test parser',
            'riepilogo',
            'subtotale',
            'imponibile',
            'totale iva',
            'totale documento',
            'totale fattura',
            'totaie fattura',
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
     * Verifica se una linea sembra contenere informazioni tecniche di supporto, come numeri di serie, codici a barre, EAN, ecc., basandosi su pattern comuni.
     * Queste linee non fanno parte della descrizione principale della riga fattura, ma possono essere utili come supporting lines per fornire informazioni aggiuntive sul prodotto o sulla riga fattura.
     * I pattern usati sono abbastanza generici per cercare di includere la maggior parte dei casi, ma possono essere adattati o modificati in base alle specificità dei documenti e dei layout incontrati.
     */
    private function lineLooksLikeTechnicalSupportingInfo(string $line): bool
    {
        $normalized = mb_strtolower(trim($line));

        foreach (['ean ', 's/n ', 'sn ', 'serial ', 'seriale ', 'imei ', 'barcode ', 'cod. bar'] as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return true;
            }
        }

        return $this->extractEanFromLines([$line]) !== null
            || $this->extractSerialNumberFromLines([$line]) !== null;
    }

    /**
     * Verifica se un testo sembra essere un header di tabella, basandosi su pattern comuni.
     * Questi header non fanno parte delle righe fattura reali, e possono essere usati per identificare la posizione della tabella e per escludere questi item dall'estrazione delle righe fattura.
     * I pattern usati sono abbastanza generici per cercare di includere la maggior parte dei casi, ma possono essere adattati o modificati in base alle specificità dei documenti e dei layout incontrati.
     */
    private function textLooksLikeHeader(string $text): bool
    {
        $normalized = mb_strtolower(trim($text));

        return in_array($normalized, [
            'cod.',
            'codice',
            'descrizione',
            'qta',
            'qtà',
            'iva',
            'unitario',
            'totale',
        ], true);
    }

    /**
     * Verifica se un testo sembra essere un segnale di aggiustamento contabile, basandosi su pattern comuni come "sconto", "acconto", "storno", "rimborso", ecc.
     * Questi testi non fanno parte delle righe fattura reali, e possono essere usati per identificare informazioni di supporto o aggiustamenti che non rappresentano voci fattura reali.
     * I pattern usati sono abbastanza generici per cercare di includere la maggior parte dei casi, ma possono essere adattati o modificati in base alle specificità dei documenti e dei layout incontrati.
     */
    private function textLooksLikeAccountingAdjustment(string $text): bool
    {
        $normalized = mb_strtolower(trim($text));

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
     * Estrae un possibile codice EAN da linee di testo, cercando pattern comuni come "EAN" seguito da una sequenza di 8, 12, 13 o 14 cifre.
     * Restituisce il primo codice EAN plausibile trovato, o null se non ne viene trovato alcuno.
     * Viene data priorità alle linee che contengono esplicitamente la parola "EAN", per evitare di confondere i numeri di serie o altri codici con l'EAN.
     */
    private function extractEanFromLines(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (preg_match('/\bEAN\s*(?<ean>\d{8}|\d{12}|\d{13}|\d{14})\b/iu', $line, $matches)) {
                return $matches['ean'];
            }

            $normalized = mb_strtolower($line);

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
     * Estrae un possibile numero seriale da linee di testo, cercando pattern comuni come "SN", "S/N", "Seriale", "IMEI", ecc.
     * Restituisce il primo seriale plausibile trovato, o null se non ne viene trovato alcuno.
     * I pattern usati sono abbastanza generici per cercare di includere la maggior parte dei casi, ma possono essere adattati o modificati in base alle specificità dei documenti e dei layout incontrati.
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
     * Estrae gli ID unici degli item coinvolti nella costruzione di una riga fattura.
     * Utile per tracciare la provenienza dei dati estratti e per eventuali debug o visualizzazioni.
     */
    private function sourceIds(array $items): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (array $item): mixed => $item['id'] ?? null,
            $items
        ))));
    }

    /**
     * Parsa un importo da stringa, gestendo sia il punto che la virgola come separatore decimale,
     * e rimuovendo eventuali spazi o punti usati come separatori delle migliaia.
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
     * Parsa una quantità da stringa, gestendo sia il punto che la virgola come separatore decimale.
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
     * Normalizza un codice prodotto rimuovendo spazi e convertendo in maiuscolo.
     * Utile per normalizzare i codici degli item OCR prima di valutarli o confrontarli.
     */
    private function normalizeCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    /**
     * Normalizza una linea di testo rimuovendo spazi multipli e trim. Utile per normalizzare i testi degli item OCR
     */
    private function normalizeLine(string $line): string
    {
        return trim(preg_replace('/\s+/', ' ', $line) ?: $line);
    }

    /**
     * Converte un valore in float se è numerico, altrimenti restituisce null.
     * Utilizzato per normalizzare le coordinate degli item OCR.
     */
    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}