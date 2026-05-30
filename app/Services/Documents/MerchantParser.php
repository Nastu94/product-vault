<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\Merchant;
use Illuminate\Support\Str;

class MerchantParser
{
    /**
     * Estrae o crea un merchant a partire dal testo del documento.
     *
     * Strategia MVP:
     * - prova a individuare il nome merchant dalle prime righe;
     * - prova a estrarre P.IVA, ma scarta valori fittizi/non utilizzabili;
     * - prova a estrarre email;
     * - prova a estrarre indirizzo;
     * - collega il merchant al documento.
     */
    public function parse(Document $document): ?Merchant
    {
        $text = trim((string) $document->raw_text);

        if ($text === '') {
            return null;
        }

        $merchantName = $this->extractMerchantName($text);

        if (! $merchantName) {
            return null;
        }

        $vatNumber = $this->extractVatNumber($text);
        $email = $this->extractEmail($text);
        $address = $this->extractAddress($text);

        $normalizedName = $this->normalizeName($merchantName);

        /*
        |--------------------------------------------------------------------------
        | Ricerca merchant esistente
        |--------------------------------------------------------------------------
        |
        | Prima proviamo con la P.IVA solo se è realmente utilizzabile.
        | P.IVA fittizie come 00000000000 non devono causare merge sbagliati.
        | Se la P.IVA manca o non è affidabile, usiamo il nome normalizzato.
        |
        */
        $merchant = null;

        if ($vatNumber) {
            $merchant = Merchant::query()
                ->where('team_id', $document->team_id)
                ->where('vat_number', $vatNumber)
                ->first();
        }

        if (! $merchant) {
            $merchant = Merchant::query()
                ->where('team_id', $document->team_id)
                ->where('normalized_name', $normalizedName)
                ->first();
        }

    if (! $merchant) {
        $merchant = Merchant::query()->create([
            'team_id' => $document->team_id,
            'name' => $merchantName,
            'normalized_name' => $normalizedName,
            'vat_number' => $vatNumber,
            'email' => $email,
            'address' => $address,
            'is_verified' => false,
            'is_active' => true,
        ]);
    } else {
        /*
        |--------------------------------------------------------------------------
        | Auto-correzione merchant non verificati
        |--------------------------------------------------------------------------
        |
        | Se un merchant è stato creato in precedenza con un nome chiaramente
        | descrittivo/tecnico, ma ha una P.IVA corretta, possiamo correggere il nome
        | usando un candidato più forte.
        |
        | Non tocchiamo merchant verificati manualmente.
        |
        */
        if (
            ! $merchant->is_verified
            && $this->merchantNameShouldReplaceExisting(
                existingName: (string) $merchant->name,
                newName: $merchantName
            )
        ) {
            $merchant->name = $merchantName;
            $merchant->normalized_name = $normalizedName;
        }

        /*
        |--------------------------------------------------------------------------
        | Arricchimento leggero
        |--------------------------------------------------------------------------
        |
        | Non sovrascriviamo dati già presenti, ma completiamo quelli mancanti.
        |
        */
        $merchant->fill([
            'vat_number' => $merchant->vat_number ?: $vatNumber,
            'email' => $merchant->email ?: $email,
            'address' => $merchant->address ?: $address,
        ])->save();
    }

        $document->update([
            'merchant_id' => $merchant->id,
        ]);

        return $merchant->refresh();
    }

    /**
     * Prova a ricavare il nome merchant dalle prime righe del documento.
     *
     * Non prende più semplicemente la prima riga valida: assegna un punteggio
     * alle righe candidate e sceglie quella più probabile come merchant.
     */
    private function extractMerchantName(string $text): ?string
    {
        $lines = collect(preg_split('/\R/u', $text) ?: [])
            ->map(fn (string $line): string => trim(preg_replace('/\s+/', ' ', $line) ?: ''))
            ->filter(fn (string $line): bool => $line !== '')
            ->values()
            ->all();

        if (empty($lines)) {
            return null;
        }

        $candidateLines = array_slice($lines, 0, 18);
        $candidates = [];

        foreach ($candidateLines as $index => $line) {
            $lowerLine = mb_strtolower($line);

            if ($this->lineIsNotMerchantName($lowerLine)) {
                continue;
            }

            if (mb_strlen($line) < 2 || mb_strlen($line) > 120) {
                continue;
            }

            if ($this->lineLooksLikeAddressCandidate($line)) {
                continue;
            }

            $score = $this->scoreMerchantNameCandidate($line, $candidateLines, $index);

            if ($score < 8) {
                continue;
            }

            $candidates[] = [
                'line' => $line,
                'score' => $score,
                'index' => $index,
            ];
        }

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, function (array $a, array $b): int {
            $scoreComparison = $b['score'] <=> $a['score'];

            if ($scoreComparison !== 0) {
                return $scoreComparison;
            }

            return $a['index'] <=> $b['index'];
        });

        return $candidates[0]['line'];
    }

    /**
     * Assegna un punteggio a una riga candidata merchant.
     */
    private function scoreMerchantNameCandidate(string $line, array $nearbyLines, int $index): int
    {
        $score = 0;
        $normalized = mb_strtolower($line);

        /*
        |--------------------------------------------------------------------------
        | Segnali forti di ragione sociale
        |--------------------------------------------------------------------------
        */
        if ($this->lineLooksLikeLegalEntity($line)) {
            $score += 40;
        }

        if ($this->lineLooksLikeFoodServiceTradingName($line)) {
            $score += 65;
        }

        if (str_contains($line, '&')) {
            $score += 8;
        }

        if ($this->uppercaseRatio($line) >= 0.55) {
            $score += 10;
        }

        /*
        |--------------------------------------------------------------------------
        | Posizione nel documento
        |--------------------------------------------------------------------------
        |
        | Il merchant di solito è nella parte alta, ma non necessariamente la prima
        | riga: spesso prima ci sono titolo o sottotitolo del documento.
        |
        */
        if ($index <= 8) {
            $score += 10;
        }

        if ($index <= 3) {
            $score += 5;
        }

        /*
        |--------------------------------------------------------------------------
        | Contesto vicino
        |--------------------------------------------------------------------------
        |
        | Merchant e dati fiscali/contatto tendono a stare nello stesso blocco.
        */
        $context = mb_strtolower(implode(' ', array_slice($nearbyLines, $index, 4)));

        if (preg_match('/(?:p\.?\s*iva|partita\s+iva|piva)\D*\d{11}/iu', $context)) {
            $score += 25;
        }

        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', $context)) {
            $score += 12;
        }

        if ($this->contextContainsAddressSignal($context)) {
            $score += 12;
        }

        /*
        |--------------------------------------------------------------------------
        | Penalità per sottotitoli o descrizioni tecniche
        |--------------------------------------------------------------------------
        */
        if ($this->lineLooksLikeDocumentSubtitle($line)) {
            $score -= 65;
        }

        if (str_contains($line, ':') && ! $this->lineLooksLikeLegalEntity($line)) {
            $score -= 15;
        }

        $wordCount = str_word_count(str_replace(['&', '-', '.'], ' ', $line));

        if ($wordCount > 10 && ! $this->lineLooksLikeLegalEntity($line)) {
            $score -= 20;
        }

        if (preg_match('/\b\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4}\b/u', $line)) {
            $score -= 40;
        }

        return $score;
    }

    /**
     * Riconosce nomi commerciali food service.
     *
     * Molti scontrini mostrano prima il nome pubblico del locale
     * e solo dopo la ragione sociale fiscale.
     */
    private function lineLooksLikeFoodServiceTradingName(string $line): bool
    {
        $normalized = mb_strtolower($line);

        foreach ([
            'ristorante',
            'trattoria',
            'pizzeria',
            'osteria',
            'taverna',
            'bar ',
            'caffè',
            'caffe',
            'gelateria',
            'panificio',
            'bistrot',
            'pub',
        ] as $signal) {
            if (str_contains($normalized, $signal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Riconosce ragioni sociali o forme aziendali comuni.
     */
    private function lineLooksLikeLegalEntity(string $line): bool
    {
        return (bool) preg_match(
            '/\b(?:s\.?\s*r\.?\s*l\.?|srl|s\.?\s*n\.?\s*c\.?|snc|s\.?\s*a\.?\s*s\.?|sas|s\.?\s*p\.?\s*a\.?|spa|società|societa|ditta|impresa)\b/iu',
            $line
        );
    }

    /**
     * Riconosce righe descrittive/sottotitoli del documento.
     */
    private function lineLooksLikeDocumentSubtitle(string $line): bool
    {
        $normalized = mb_strtolower($line);

        $signals = [
            'layout',
            'stress test',
            'test ocr',
            'ocr',
            'pdfparser',
            'colonne',
            'colonna',
            'ravvicinate',
            'documento sintetico',
            'generato per test',
            'dati interamente fittizi',
            'servizi',
            'consumabili',
            'usato e sconti',
        ];

        $matches = 0;

        foreach ($signals as $signal) {
            if (str_contains($normalized, $signal)) {
                $matches++;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Evitiamo un hard block su singole parole
        |--------------------------------------------------------------------------
        |
        | Una società potrebbe chiamarsi "OCR Solutions SRL".
        | Per questo blocchiamo solo quando la riga ha più segnali descrittivi
        | oppure è una frase lunga senza forma societaria.
        |
        */
        if ($this->lineLooksLikeLegalEntity($line)) {
            return false;
        }

        if ($matches >= 2) {
            return true;
        }

        return $matches >= 1 && str_word_count($line) >= 7;
    }

    /**
     * Calcola quanta parte alfabetica della riga è maiuscola.
     */
    private function uppercaseRatio(string $line): float
    {
        preg_match_all('/[A-ZÀ-Ý]/u', $line, $uppercaseMatches);
        preg_match_all('/[A-ZÀ-Ýa-zà-ÿ]/u', $line, $letterMatches);

        $letters = count($letterMatches[0] ?? []);

        if ($letters === 0) {
            return 0.0;
        }

        return count($uppercaseMatches[0] ?? []) / $letters;
    }

    /**
     * Riconosce una riga indirizzo candidata.
     */
    private function lineLooksLikeAddressCandidate(string $line): bool
    {
        $lowerLine = mb_strtolower($line);

        return str_contains($lowerLine, ' via ')
            || str_starts_with($lowerLine, 'via ')
            || str_contains($lowerLine, 'viale ')
            || str_starts_with($lowerLine, 'viale ')
            || str_contains($lowerLine, 'corso ')
            || str_starts_with($lowerLine, 'corso ')
            || str_contains($lowerLine, 'piazza ')
            || str_starts_with($lowerLine, 'piazza ')
            || preg_match('/\b\d{5}\b/u', $line);
    }

    /**
     * Riconosce se nel contesto vicino ci sono segnali di indirizzo.
     */
    private function contextContainsAddressSignal(string $context): bool
    {
        return str_contains($context, ' via ')
            || str_contains($context, 'via ')
            || str_contains($context, 'viale ')
            || str_contains($context, 'corso ')
            || str_contains($context, 'piazza ')
            || preg_match('/\b\d{5}\b/u', $context);
    }

    /**
     * Decide se il nuovo nome merchant è abbastanza migliore da correggere
     * un merchant non verificato già esistente.
     */
    private function merchantNameShouldReplaceExisting(string $existingName, string $newName): bool
    {
        $existingName = trim($existingName);
        $newName = trim($newName);

        if ($existingName === '' || $newName === '') {
            return false;
        }

        if ($this->normalizeName($existingName) === $this->normalizeName($newName)) {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Il vecchio nome sembra un sottotitolo o una riga tecnica
        |--------------------------------------------------------------------------
        */
        if (
            $this->lineLooksLikeDocumentSubtitle($existingName)
            || $this->lineIsNotMerchantName(mb_strtolower($existingName))
        ) {
            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Il nuovo nome ha forma societaria, il vecchio no
        |--------------------------------------------------------------------------
        */
        if (
            $this->lineLooksLikeLegalEntity($newName)
            && ! $this->lineLooksLikeLegalEntity($existingName)
        ) {
            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Il vecchio nome è una frase lunga, il nuovo sembra una ragione sociale
        |--------------------------------------------------------------------------
        */
        if (
            mb_strlen($existingName) > 80
            && $this->lineLooksLikeLegalEntity($newName)
        ) {
            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Nome commerciale food service più utile della ragione sociale
        |--------------------------------------------------------------------------
        |
        | Se il merchant non è verificato, preferiamo il nome visibile sullo scontrino
        | rispetto alla sola ragione sociale fiscale.
        |
        */
        if (
            $this->lineLooksLikeFoodServiceTradingName($newName)
            && ! $this->lineLooksLikeFoodServiceTradingName($existingName)
        ) {
            return true;
        }

        return false;
    }

    /**
     * Esclude righe tecniche che non sono il nome merchant.
     */
    private function lineIsNotMerchantName(string $line): bool
    {
        $excludedSignals = [
            'e-mail',
            'email',
            'pec',
            'p.iva',
            'piva',
            'partita iva',
            'c.f.',
            'codice fiscale',
            'iban',
            'banca',
            'doc. di trasporto',
            'documento di trasporto',
            'documento sintetico',
            'documento generato',
            'documento non fiscale',
            'documento di test',
            'fattura',
            'scontrino',
            'fac-simile',
            'non fiscale',
            'dati fittizi',
            'interamente fittizi',
            'cliente intestatario',
            'destinatario',
            'destinazione',
            'codice descrizione',
            'pagamento',
            'totale',
            'pag.',
            'data documento',
            'data doc',
            'data fattura',
            'fattura n',
            'fattura nr',
            'numero fattura',
            'n. fattura',
            'scadenza',
            'valuta',
            'pagamento carta',
            'pagamento bancomat',
            'pagamento',
            'layout compatto',
            'stress test',
            'test ocr',
            'colonne ravvicinate',
        ];

        foreach ($excludedSignals as $signal) {
            if (str_contains($line, $signal)) {
                return true;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Date e riferimenti documento
        |--------------------------------------------------------------------------
        |
        | Se una riga contiene una data ed è già semanticamente legata a documento,
        | fattura, scadenza o pagamento, non può essere il nome merchant.
        |
        */
        if (preg_match('/\b\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4}\b/u', $line)) {
            return true;
        }

        return false;
    }

    /**
     * Estrae una P.IVA italiana candidata.
     */
    private function extractVatNumber(string $text): ?string
    {
        if (! preg_match('/(?:p\.?\s*iva|partita\s+iva|piva)\D*(?<vat>\d{11})/iu', $text, $matches)) {
            return null;
        }

        $vatNumber = $matches['vat'];

        if (! $this->vatNumberIsUsable($vatNumber)) {
            return null;
        }

        return $vatNumber;
    }

    /**
     * Verifica se una P.IVA è abbastanza affidabile da essere usata come chiave merchant.
     */
    private function vatNumberIsUsable(?string $vatNumber): bool
    {
        if (! $vatNumber) {
            return false;
        }

        $digits = preg_replace('/\D+/', '', $vatNumber) ?: '';

        if (strlen($digits) !== 11) {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | P.IVA fittizie
        |--------------------------------------------------------------------------
        |
        | Nei file di test o in OCR rumorosi può comparire 00000000000.
        | Non deve mai essere usata per collegare merchant diversi.
        |
        */
        if (preg_match('/^0+$/', $digits)) {
            return false;
        }

        return true;
    }

    /**
     * Estrae la prima email trovata.
     */
    private function extractEmail(string $text): ?string
    {
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', $text, $matches)) {
            return mb_strtolower($matches[0]);
        }

        return null;
    }

    /**
     * Estrae una riga indirizzo candidata.
     */
    private function extractAddress(string $text): ?string
    {
        $lines = preg_split('/\R/u', $text) ?: [];

        foreach (array_slice($lines, 0, 12) as $line) {
            $line = trim(preg_replace('/\s+/', ' ', $line) ?: '');

            if ($line === '') {
                continue;
            }

            $lowerLine = mb_strtolower($line);

            $looksLikeAddress =
                str_contains($lowerLine, ' via ')
                || str_starts_with($lowerLine, 'via ')
                || str_contains($lowerLine, 'viale ')
                || str_contains($lowerLine, 'corso ')
                || str_contains($lowerLine, 'piazza ')
                || str_contains($lowerLine, 'sp ')
                || preg_match('/\b\d{5}\b/u', $line);

            if ($looksLikeAddress) {
                return $line;
            }
        }

        return null;
    }

    /**
     * Normalizza il nome per evitare duplicati banali.
     */
    private function normalizeName(string $name): string
    {
        $name = Str::ascii($name);
        $name = mb_strtolower($name);
        $name = preg_replace('/[^a-z0-9]+/i', ' ', $name) ?: $name;
        $name = trim(preg_replace('/\s+/', ' ', $name) ?: $name);

        return $name;
    }
}