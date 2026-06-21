<?php

namespace App\Services\Documents\AssistedReview;

use App\Models\ProductIdentificationCandidate;

class AssistedReviewBrandSuggestionResolver
{
    /**
     * Termini descrittivi che indicano tipi prodotto, accessori,
     * modificatori o specifiche e non devono diventare brand.
     *
     * La lista descrive ruoli strutturali, non una knowledge base di marchi.
     *
     * @var array<int, string>
     */
    private const GENERIC_TOKENS = [
        'accessorio',
        'accessori',
        'adattatore',
        'aspirapolvere',
        'audio',
        'bank',
        'batterie',
        'bilancia',
        'bluetooth',
        'bundle',
        'camera',
        'card',
        'caricatore',
        'cavo',
        'console',
        'controller',
        'cover',
        'creator',
        'custodia',
        'deumidificatore',
        'dock',
        'docking',
        'documentale',
        'fotocamera',
        'filtro',
        'gimbal',
        'hdmi',
        'hdd',
        'kit',
        'lampada',
        'laptop',
        'laser',
        'lavapavimenti',
        'memory',
        'microfono',
        'modello',
        'monitor',
        'nas',
        'notebook',
        'nvme',
        'obiettivo',
        'power',
        'powerbank',
        'professionale',
        'ricaricabili',
        'robot',
        'router',
        'scanner',
        'smart',
        'spazzole',
        'ssd',
        'stabilizzatore',
        'stampante',
        'station',
        'tablet',
        'televisore',
        'tv',
        'ups',
        'usb',
        'usbc',
        'variante',
        'webcam',
        'wifi',
        'wireless',
        'dual',
        'quad',
        'single',
        'triple',
    ];

    /**
     * Termini che rappresentano direttamente il tipo di prodotto.
     *
     * Questa lista è più restrittiva di GENERIC_TOKENS ed è usata soltanto
     * quando una semplice parola Title Case viene valutata come possibile brand.
     *
     * @var array<int, string>
     */
    private const PRODUCT_TYPE_TOKENS = [
        'aspirapolvere',
        'bilancia',
        'camera',
        'cavo',
        'console',
        'controller',
        'deumidificatore',
        'dock',
        'fotocamera',
        'gimbal',
        'lampada',
        'laptop',
        'memory',
        'monitor',
        'nas',
        'notebook',
        'obiettivo',
        'powerbank',
        'robot',
        'router',
        'scanner',
        'ssd',
        'stabilizzatore',
        'stampante',
        'tablet',
        'televisore',
        'tv',
        'ups',
        'webcam',
    ];

    /**
     * Risolve il miglior suggerimento brand disponibile.
     *
     * Ordine di precedenza:
     * - snapshot della knowledge iniziale;
     * - segnale del Product Line Analyzer;
     * - struttura prudente del nome candidato.
     *
     * Nessun risultato viene applicato automaticamente a brand_id.
     *
     * @return array<string, mixed>|null
     */
    public function resolve(
        ProductIdentificationCandidate $candidate
    ): ?array {
        $metadata = is_array($candidate->metadata)
            ? $candidate->metadata
            : [];

        return $this->fromInitialKnowledge($metadata)
            ?? $this->fromLineAnalysis($metadata)
            ?? $this->fromNameStructure($candidate);
    }

    /**
     * Usa uno snapshot brand già prodotto dalla knowledge iniziale.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>|null
     */
    private function fromInitialKnowledge(array $metadata): ?array
    {
        $brandContext = $metadata['product_understanding_brand'] ?? null;

        if (! is_array($brandContext)) {
            return null;
        }

        if (($brandContext['matched'] ?? false) !== true) {
            return null;
        }

        $brandName = $this->validBrandValue(
            $brandContext['brand_name'] ?? null
        );

        if ($brandName === null) {
            return null;
        }

        $brandId = filter_var(
            $brandContext['brand_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $normalizedName = $this->nullableString(
            $brandContext['normalized_name'] ?? null
        );

        $reference = null;

        if ($brandId !== false && $brandId > 0) {
            $reference = [
                'type' => 'brand',
                'id' => $brandId,
                'key' => $normalizedName,
            ];
        }

        return [
            'value' => $brandName,
            'ref' => $reference,
            'origin' => 'derived',
            'source' => 'initial_knowledge',
            'method' => $this->nullableString(
                $brandContext['match_type'] ?? null
            ) ?? 'matched_brand_snapshot',
            'confidence' => $this->normalizeConfidence(
                $brandContext['alias_confidence_score'] ?? null
            ),
        ];
    }

    /**
     * Usa il segnale brand prodotto dal Product Line Analyzer.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>|null
     */
    private function fromLineAnalysis(array $metadata): ?array
    {
        $analysis = $metadata['product_understanding'] ?? null;

        if (! is_array($analysis)) {
            return null;
        }

        $brandCandidate = $this->validBrandValue(
            $analysis['brand_candidate'] ?? null
        );

        if ($brandCandidate === null) {
            return null;
        }

        return [
            'value' => $brandCandidate,
            'ref' => null,
            'origin' => 'derived',
            'source' => 'product_line_analysis',
            'method' => 'detected_brand_candidate',
            'confidence' => null,
        ];
    }

    /**
     * Cerca un possibile brand nella struttura del nome candidato.
     *
     * Il resolver non assume che la prima parola sia un brand. Cerca soltanto
     * token con una forma sufficientemente caratteristica e scarta tipi
     * prodotto, specifiche tecniche e bundle ambigui.
     *
     * @return array<string, mixed>|null
     */
    private function fromNameStructure(
        ProductIdentificationCandidate $candidate
    ): ?array {
        $name = $this->nullableString($candidate->name);

        if ($name === null) {
            return null;
        }

        $tokens = array_slice($this->tokenize($name), 0, 12);

        if ($tokens === []) {
            return null;
        }

        $matches = [];

        foreach ($tokens as $index => $token) {
            if (
                $this->isGenericToken($token)
                || $this->isTechnicalToken($token)
            ) {
                continue;
            }

            $method = null;
            $confidence = null;

            if ($this->isPascalBrandToken($token)) {
                $method = 'name_structure_pascal_token';
                $confidence = 76;
            } elseif ($this->isUppercaseBrandToken($token)) {
                $method = 'name_structure_uppercase_token';
                $confidence = 70;
            } elseif ($this->isSimpleTitleBrandToken(
                token: $token,
                previousToken: $tokens[$index - 1] ?? null,
                nextToken: $tokens[$index + 1] ?? null
            )) {
                $method = 'name_structure_title_before_model';
                $confidence = 64;
            }

            if ($method === null) {
                continue;
            }

            $matches[] = [
                'index' => $index,
                'value' => $token,
                'method' => $method,
                'confidence' => $confidence,
            ];
        }

        if ($matches === []) {
            return null;
        }

        /*
         * Un bundle con più token che sembrano brand può rappresentare più
         * prodotti o più marchi. In quel caso non scegliamo arbitrariamente.
         */
        if ($this->isAmbiguousMultiProductName($name, $matches)) {
            return null;
        }

        $selected = $matches[0];

        return [
            'value' => $selected['value'],
            'ref' => null,
            'origin' => 'derived',
            'source' => 'name_structure',
            'method' => $selected['method'],
            /*
             * È una confidenza deterministica della regola, non una
             * probabilità statistica né una conferma del brand.
             */
            'confidence' => $selected['confidence'],
        ];
    }

    /**
     * Divide il nome in token mantenendo lettere, numeri e trattini.
     *
     * @return array<int, string>
     */
    private function tokenize(string $value): array
    {
        preg_match_all(
            '/[\p{L}\p{N}]+(?:-[\p{L}\p{N}]+)*/u',
            $value,
            $matches
        );

        return array_values(array_filter(
            $matches[0] ?? [],
            fn (mixed $token): bool => is_string($token)
                && trim($token) !== ''
        ));
    }

    /**
     * Riconosce token CamelCase o PascalCase, come ViewMax o CasaBot.
     */
    private function isPascalBrandToken(string $token): bool
    {
        $length = mb_strlen($token);

        if ($length < 4 || $length > 40) {
            return false;
        }

        if (preg_match('/^[\p{L}]+$/u', $token) !== 1) {
            return false;
        }

        return preg_match('/\p{Ll}\p{Lu}/u', $token) === 1;
    }

    /**
     * Riconosce token alfabetici completamente maiuscoli e abbastanza lunghi,
     * come AQUABOT o FLASHCORE.
     */
    private function isUppercaseBrandToken(string $token): bool
    {
        $length = mb_strlen($token);

        if ($length < 5 || $length > 30) {
            return false;
        }

        if (preg_match('/^\p{Lu}+$/u', $token) !== 1) {
            return false;
        }

        /*
         * La presenza di una vocale evita molti acronimi tecnici casuali.
         */
        return preg_match('/[AEIOUÀÈÉÌÒÙ]/u', $token) === 1;
    }

    /**
     * Accetta una parola Title Case semplice solo quando:
     * - segue un termine descrittivo del prodotto;
     * - precede un token chiaramente tecnico o simile a un modello.
     *
     * Esempio ammesso: SSD Kingston NV3.
     */
    private function isSimpleTitleBrandToken(
        string $token,
        ?string $previousToken,
        ?string $nextToken
    ): bool {
        if ($previousToken === null || $nextToken === null) {
            return false;
        }

        /*
        * Una parola semplice può essere proposta come brand soltanto quando
        * segue direttamente un tipo prodotto reale.
        *
        * Token tecnici o modificatori generici, come USB-C, Dual o Wireless,
        * non costituiscono un contesto sufficiente.
        */
        if (! $this->isProductTypeToken($previousToken)) {
            return false;
        }

        if (! $this->isModelLikeToken($nextToken)) {
            return false;
        }

        if (mb_strlen($token) < 4 || mb_strlen($token) > 30) {
            return false;
        }

        return preg_match(
            '/^\p{Lu}\p{Ll}+$/u',
            $token
        ) === 1;
    }

    /**
     * Individua specifiche, codici, capacità, misure e acronimi tecnici.
     */
    private function isTechnicalToken(string $token): bool
    {
        $normalized = $this->normalizeToken($token);

        if ($normalized === '' || mb_strlen($normalized) <= 2) {
            return true;
        }

        if (preg_match('/\d/u', $token) === 1) {
            return true;
        }

        if (
            str_contains($token, '-')
            || str_contains($token, '/')
        ) {
            return true;
        }

        if (in_array($normalized, [
            'bt',
            'fhd',
            'hdr',
            'hdmi',
            'hepa',
            'nvme',
            'pd',
            'qhd',
            'uhd',
            'usb',
            'usbc',
            'wifi',
        ], true)) {
            return true;
        }

        return preg_match('/^(?:i|v|x){1,5}$/iu', $token) === 1;
    }

    /**
     * Riconosce un token successivo simile a modello o specifica.
     */
    private function isModelLikeToken(string $token): bool
    {
        if (preg_match('/\d/u', $token) === 1) {
            return true;
        }

        if (
            str_contains($token, '-')
            || str_contains($token, '/')
        ) {
            return true;
        }

        return preg_match('/^\p{Lu}{2,6}$/u', $token) === 1;
    }

    /**
     * Controlla se il token ha un ruolo descrittivo e non identificativo.
     */
    private function isGenericToken(string $token): bool
    {
        return in_array(
            $this->normalizeToken($token),
            self::GENERIC_TOKENS,
            true
        );
    }

    /**
     * Controlla se il token rappresenta direttamente un tipo prodotto.
     */
    private function isProductTypeToken(string $token): bool
    {
        return in_array(
            $this->normalizeToken($token),
            self::PRODUCT_TYPE_TOKENS,
            true
        );
    }

    /**
     * Evita di scegliere un brand arbitrario in bundle multi-prodotto.
     *
     * @param  array<int, array<string, mixed>>  $matches
     */
    private function isAmbiguousMultiProductName(
        string $name,
        array $matches
    ): bool {
        $distinctValues = collect($matches)
            ->pluck('value')
            ->map(fn (mixed $value): string => mb_strtolower(
                trim((string) $value)
            ))
            ->filter()
            ->unique()
            ->count();

        if ($distinctValues < 2) {
            return false;
        }

        $normalizedName = mb_strtolower($name);

        return str_contains($name, '+')
            || preg_match(
                '/(?<![\p{L}\p{N}])bundle(?![\p{L}\p{N}])/u',
                $normalizedName
            ) === 1;
    }

    /**
     * Valida il valore testuale prima di esporlo come brand.
     */
    private function validBrandValue(mixed $value): ?string
    {
        $brand = $this->nullableString($value);

        if ($brand === null || mb_strlen($brand) > 80) {
            return null;
        }

        if (preg_match('/^\d+$/u', $brand) === 1) {
            return null;
        }

        return $brand;
    }

    /**
     * Normalizza una confidenza già prodotta da una fonte precedente.
     */
    private function normalizeConfidence(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $confidence = (int) round((float) $value);

        return $confidence >= 0 && $confidence <= 100
            ? $confidence
            : null;
    }

    /**
     * Normalizza un token per confronti strutturali.
     */
    private function normalizeToken(string $token): string
    {
        $normalized = mb_strtolower(trim($token));

        return preg_replace(
            '/[^\p{L}\p{N}]+/u',
            '',
            $normalized
        ) ?: '';
    }

    /**
     * Normalizza una stringa opzionale.
     */
    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}