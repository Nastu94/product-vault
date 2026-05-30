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
     */
    private function extractMerchantName(string $text): ?string
    {
        $lines = preg_split('/\R/u', $text) ?: [];

        foreach (array_slice($lines, 0, 12) as $line) {
            $line = trim(preg_replace('/\s+/', ' ', $line) ?: '');

            if ($line === '') {
                continue;
            }

            $lowerLine = mb_strtolower($line);

            if ($this->lineIsNotMerchantName($lowerLine)) {
                continue;
            }

            if (mb_strlen($line) < 2 || mb_strlen($line) > 120) {
                continue;
            }

            return $line;
        }

        return null;
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
        ];

        foreach ($excludedSignals as $signal) {
            if (str_contains($line, $signal)) {
                return true;
            }
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