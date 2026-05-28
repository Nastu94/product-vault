<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\DocumentLineType;

class DocumentLineParser
{
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

                DocumentLine::query()->create([
                    'document_id' => $document->id,
                    'document_line_type_id' => $lineTypeId,
                    'line_number' => $index + 1,
                    'raw_text' => $rawLine,
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
            'carta',
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