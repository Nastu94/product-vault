<?php

namespace App\Services\Documents\AssistedReview;

use App\Models\Brand;
use App\Models\ProductIdentificationCandidate;

class AssistedReviewModelSuggestionResolver
{
    /**
     * Placeholder e tipi prodotto generici che non identificano un modello.
     *
     * @var array<int, string>
     */
    private const GENERIC_MODEL_VALUES = [
        'unit',
        'webcam',
        'router',
        'monitor',
        'tablet',
        'laptop',
        'notebook',
        'dock',
        'docking',
        'camera',
        'gimbal',
        'ssd',
        'nas',
    ];

    /**
     * Specifiche e standard tecnici che non identificano un modello.
     *
     * @var array<int, string>
     */
    private const TECHNICAL_MODEL_VALUES = [
        'hdmi',
        'homi',
        'usb',
        'nvme',
        'fhd',
        'qhd',
        'uhd',
        'wifi',
        'wireless',
    ];

    /**
     * Prefissi di righe accessorio o ricambio per cui il nome del prodotto
     * compatibile non deve essere suggerito come modello dell'accessorio.
     *
     * @var array<int, string>
     */
    private const UNSAFE_NAME_PREFIXES = [
        'cover',
        'custodia',
        'filtro',
        'kit',
        'spazzole',
        'batterie',
        'cavo',
    ];

    /**
     * Token che interrompono una possibile sequenza di modello.
     *
     * @var array<int, string>
     */
    private const MODEL_BOUNDARY_TOKENS = [
        'aspirapolvere',
        'robot',
        'nas',
        'cuffie',
        'auricolari',
        'wireless',
        'wifi',
        'usb',
        'hdmi',
        'homi',
        'nvme',
        'mirrorless',
        'monitor',
        'notebook',
        'laptop',
        'router',
        'webcam',
        'tablet',
        'ssd',
        'camera',
        'fotocamera',
        'obiettivo',
        'gimbal',
        'stabilizzatore',
        'lampada',
        'bilancia',
        'powerbank',
        'dock',
        'docking',
        'station',
        'cover',
        'filtro',
        'kit',
        'spazzole',
    ];

    /**
     * Token descrittivi iniziali da non includere nel modello.
     *
     * @var array<int, string>
     */
    private const LEADING_PRODUCT_TOKENS = [
        'aspirapolvere',
        'bilancia',
        'camera',
        'console',
        'controller',
        'deumidificatore',
        'dock',
        'docking',
        'fotocamera',
        'gimbal',
        'lampada',
        'laptop',
        'monitor',
        'mouse',
        'nas',
        'notebook',
        'obiettivo',
        'powerbank',
        'robot',
        'router',
        'scanner',
        'smart',
        'ssd',
        'stabilizzatore',
        'stampante',
        'station',
        'tablet',
        'tv',
        'ups',
        'webcam',
    ];

    /**
     * Crea il resolver usando il riconoscimento brand già disponibile.
     */
    public function __construct(
        private readonly AssistedReviewBrandSuggestionResolver
            $brandSuggestionResolver
    ) {
    }

    /**
     * Valuta il modello corrente e produce un eventuale suggerimento.
     *
     * Il metodo non modifica il candidato e non sostituisce model.
     *
     * @return array{
     *     current_is_usable: bool,
     *     issues: array<int, string>,
     *     suggestion: array<string, mixed>|null
     * }
     */
    public function assess(
        ProductIdentificationCandidate $candidate
    ): array {
        $current = $this->nullableString($candidate->model);
        $issues = $current !== null
            ? $this->currentModelIssues($candidate, $current)
            : [];

        $currentIsUsable = $current !== null && $issues === [];

        return [
            'current_is_usable' => $currentIsUsable,
            'issues' => $issues,
            'suggestion' => $currentIsUsable
                ? null
                : (
                    $this->fromLineAnalysis($candidate)
                    ?? $this->fromNameStructure($candidate)
                ),
        ];
    }

    /**
     * Valuta perché il modello corrente non è affidabile.
     *
     * @return array<int, string>
     */
    private function currentModelIssues(
        ProductIdentificationCandidate $candidate,
        string $model
    ): array {
        $issues = [];

        if ($this->isGenericModelValue($model)) {
            $issues[] = 'generic_current_model';
        }

        if ($this->looksLikeTechnicalSpecification($model)) {
            $issues[] = 'technical_specification_used_as_model';
        }

        if ($this->looksLikeEan($model)) {
            $issues[] = 'ean_used_as_model';
        }

        if ($this->matchesCandidateSerialOrEan($candidate, $model)) {
            $issues[] = 'identifier_used_as_model';
        }

        if ($this->matchesBrand($candidate, $model)) {
            $issues[] = 'brand_used_as_model';
        }

        return array_values(array_unique($issues));
    }

    /**
     * Usa il model candidate dell'analizzatore soltanto se è plausibile.
     *
     * @return array<string, mixed>|null
     */
    private function fromLineAnalysis(
        ProductIdentificationCandidate $candidate
    ): ?array {
        $metadata = is_array($candidate->metadata)
            ? $candidate->metadata
            : [];

        $value = data_get(
            $metadata,
            'product_understanding.model_candidate'
        );

        $model = $this->nullableString($value);

        if (
            $model === null
            || ! $this->isPlausibleModel(
                candidate: $candidate,
                value: $model,
                allowPlainPhrase: false
            )
        ) {
            return null;
        }

        return $this->suggestion(
            value: $model,
            source: 'product_line_analysis',
            method: 'detected_model_candidate',
            confidence: null
        );
    }

    /**
     * Cerca un modello prudente nella struttura del nome candidato.
     *
     * @return array<string, mixed>|null
     */
    private function fromNameStructure(
        ProductIdentificationCandidate $candidate
    ): ?array {
        $name = $this->nullableString($candidate->name);

        if ($name === null || $this->hasUnsafeNamePrefix($name)) {
            return null;
        }

        $explicit = $this->extractExplicitModel($name, $candidate);

        if ($explicit !== null) {
            return $this->suggestion(
                value: $explicit,
                source: 'name_structure',
                method: 'explicit_model_label',
                confidence: 88
            );
        }

        $generation = $this->extractGenerationModel(
            $name,
            $candidate
        );

        if ($generation !== null) {
            return $this->suggestion(
                value: $generation,
                source: 'name_structure',
                method: 'generation_model_sequence',
                confidence: 82
            );
        }

        $spacedCode = $this->extractSpacedTechnicalModel(
            $name,
            $candidate
        );

        if ($spacedCode !== null) {
            return $this->suggestion(
                value: $spacedCode,
                source: 'name_structure',
                method: 'spaced_model_code',
                confidence: 78
            );
        }

        $mixedToken = $this->extractMixedTokenModel(
            $name,
            $candidate
        );

        if ($mixedToken !== null) {
            return $this->suggestion(
                value: $mixedToken,
                source: 'name_structure',
                method: 'mixed_alphanumeric_model',
                confidence: 74
            );
        }

        $brandFollowingPhrase = $this->extractPhraseAfterBrand(
            $name,
            $candidate
        );

        if ($brandFollowingPhrase !== null) {
            return $this->suggestion(
                value: $brandFollowingPhrase,
                source: 'name_structure',
                method: 'phrase_after_brand',
                confidence: 68
            );
        }

        return null;
    }

    /**
     * Estrae un valore preceduto esplicitamente da "modello" o "model".
     */
    private function extractExplicitModel(
        string $name,
        ProductIdentificationCandidate $candidate
    ): ?string {
        if (
            preg_match(
                '/\b(?:modello|model)\s*[:#\-]?\s*'
                . '(?<model>[A-Z0-9][A-Z0-9._\/\-]{1,39})\b/iu',
                $name,
                $matches
            ) !== 1
        ) {
            return null;
        }

        $model = trim((string) $matches['model']);

        return $this->isPlausibleModel(
            candidate: $candidate,
            value: $model,
            allowPlainPhrase: false
        )
            ? $model
            : null;
    }

    /**
     * Estrae sequenze generazionali come "ThinkPad X1 Carbon Gen 11".
     */
    private function extractGenerationModel(
        string $name,
        ProductIdentificationCandidate $candidate
    ): ?string {
        if (
            preg_match(
                '/\b(?<model>'
                . '(?:[A-Z][A-Za-z0-9\-]*\s+){0,3}'
                . '[A-Z]\d[A-Za-z0-9\-]*'
                . '(?:\s+[A-Z][A-Za-z0-9\-]*){0,2}'
                . '\s+Gen(?:eration)?\s+\d{1,2}'
                . ')\b/u',
                $name,
                $matches
            ) !== 1
        ) {
            return null;
        }

        $model = $this->cleanModelPhrase(
            (string) $matches['model'],
            $candidate
        );

        return $this->isPlausibleModel(
            candidate: $candidate,
            value: $model,
            allowPlainPhrase: true
        )
            ? $model
            : null;
    }

    /**
     * Estrae codici distribuiti su più token, come "WH 1000 XM5"
     * oppure "XR 27".
     */
    private function extractSpacedTechnicalModel(
        string $name,
        ProductIdentificationCandidate $candidate
    ): ?string {
        $patterns = [
            '/\b(?<model>[A-Z]{2,5}\s+\d{3,5}\s+[A-Z]{1,4}\d{1,4})\b/u',
            '/\b(?<model>[A-Z]{2,5}\s+\d{2,4})\b/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $name, $matches) !== 1) {
                continue;
            }

            $model = trim((string) $matches['model']);

            if ($this->isBlockedTechnicalPrefix($model)) {
                continue;
            }

            if ($this->isPlausibleModel(
                candidate: $candidate,
                value: $model,
                allowPlainPhrase: true
            )) {
                return $model;
            }
        }

        return null;
    }

    /**
     * Estrae un token alfanumerico e, quando utile, il breve contesto che lo
     * precede. Esempio: "MX Master 3S".
     */
    private function extractMixedTokenModel(
        string $name,
        ProductIdentificationCandidate $candidate
    ): ?string {
        $tokens = $this->tokenize($name);

        foreach ($tokens as $index => $token) {
            if (! $this->containsLettersAndDigits($token)) {
                continue;
            }

            if (! $this->isPlausibleModel(
                candidate: $candidate,
                value: $token,
                allowPlainPhrase: false
            )) {
                continue;
            }

            $parts = [$token];

            for ($offset = 1; $offset <= 2; $offset++) {
                $previousIndex = $index - $offset;

                if ($previousIndex < 0) {
                    break;
                }

                $previous = $tokens[$previousIndex];

                if (
                    $this->isLeadingProductToken($previous)
                    || $this->matchesBrand($candidate, $previous)
                    || $this->isBoundaryToken($previous)
                    || $this->looksLikeTechnicalSpecification($previous)
                ) {
                    break;
                }

                if (! $this->isPossibleModelWord($previous)) {
                    break;
                }

                array_unshift($parts, $previous);
            }

            $model = trim(implode(' ', $parts));

            if ($this->isPlausibleModel(
                candidate: $candidate,
                value: $model,
                allowPlainPhrase: true
            )) {
                return $model;
            }
        }

        return null;
    }

    /**
     * Estrae una breve frase successiva al brand.
     *
     * Esempi plausibili:
     * - CasaBot Mappa Pro 900
     * - TerraVault Home Duo
     */
    private function extractPhraseAfterBrand(
        string $name,
        ProductIdentificationCandidate $candidate
    ): ?string {
        $brand = $this->brandValue($candidate);

        if ($brand === null) {
            return null;
        }

        $tokens = $this->tokenize($name);
        $brandTokens = $this->tokenize($brand);

        if ($tokens === [] || $brandTokens === []) {
            return null;
        }

        $brandEndIndex = $this->findTokenSequenceEnd(
            $tokens,
            $brandTokens
        );

        if ($brandEndIndex === null) {
            return null;
        }

        $parts = [];

        for (
            $index = $brandEndIndex + 1;
            $index < count($tokens) && count($parts) < 4;
            $index++
        ) {
            $token = $tokens[$index];

            if (
                $this->isBoundaryToken($token)
                || $this->looksLikeTechnicalSpecification($token)
            ) {
                break;
            }

            if (
                preg_match('/^\d{2,4}$/u', $token) === 1
                && count($parts) >= 2
            ) {
                $parts[] = $token;

                break;
            }

            if (! $this->isPossibleModelWord($token)) {
                break;
            }

            $parts[] = $token;
        }

        if (count($parts) < 2) {
            return null;
        }

        $model = trim(implode(' ', $parts));

        return $this->isPlausibleModel(
            candidate: $candidate,
            value: $model,
            allowPlainPhrase: true
        )
            ? $model
            : null;
    }

    /**
     * Verifica se un modello proposto è sufficientemente plausibile.
     */
    private function isPlausibleModel(
        ProductIdentificationCandidate $candidate,
        string $value,
        bool $allowPlainPhrase
    ): bool {
        $model = $this->nullableString($value);

        if ($model === null) {
            return false;
        }

        $length = mb_strlen($model);

        if ($length < 2 || $length > 80) {
            return false;
        }

        if (
            $this->isGenericModelValue($model)
            || $this->looksLikeTechnicalSpecification($model)
            || $this->looksLikeEan($model)
            || $this->matchesCandidateSerialOrEan($candidate, $model)
            || $this->matchesBrand($candidate, $model)
        ) {
            return false;
        }

        if ($this->containsLettersAndDigits($model)) {
            return true;
        }

        if (
            $allowPlainPhrase
            && str_contains($model, ' ')
            && count($this->tokenize($model)) >= 2
        ) {
            return true;
        }

        return false;
    }

    /**
     * Controlla valori tecnici, capacità, misure, risoluzioni e classi Wi-Fi.
     */
    private function looksLikeTechnicalSpecification(
        string $value
    ): bool {
        $normalized = mb_strtoupper(
            preg_replace('/\s+/', '', trim($value)) ?: trim($value)
        );

        if ($normalized === '') {
            return true;
        }

        if (preg_match('/^\d+$/u', $normalized) === 1) {
            return true;
        }

        if (
            preg_match(
                '/^\d+(?:[.,]\d+)?'
                . '(?:TB|GB|MB|KB|MAH|WH|W|V|A|L|ML|MM|CM|M|'
                . 'HZ|KHZ|MHZ|GHZ|P)$/u',
                $normalized
            ) === 1
        ) {
            return true;
        }

        if (
            preg_match('/^(?:4K|8K|\d{3,4}P)$/u', $normalized) === 1
        ) {
            return true;
        }

        if (
            preg_match('/^(?:AX|AC)\d{3,5}$/u', $normalized) === 1
        ) {
            return true;
        }

        if (preg_match('/^E\d{2}$/u', $normalized) === 1) {
            return true;
        }

        if (
            preg_match('/^F\d+(?:[.,]\d+)?$/u', $normalized) === 1
        ) {
            return true;
        }

        return in_array(
            mb_strtolower($normalized),
            self::TECHNICAL_MODEL_VALUES,
            true
        );
    }

    /**
     * Blocca prefissi tecnici nelle sequenze lettera-numero separate.
     */
    private function isBlockedTechnicalPrefix(string $value): bool
    {
        $prefix = mb_strtoupper(
            $this->tokenize($value)[0] ?? ''
        );

        return in_array($prefix, [
            'AC',
            'AX',
            'BT',
            'CAT',
            'FHD',
            'HDMI',
            'HDR',
            'PD',
            'QHD',
            'UHD',
            'USB',
            'WIFI',
        ], true);
    }

    /**
     * Verifica se il valore coincide con EAN o seriale del candidato.
     */
    private function matchesCandidateSerialOrEan(
        ProductIdentificationCandidate $candidate,
        string $value
    ): bool {
        $normalized = $this->normalizeComparable($value);

        foreach ([
            $candidate->ean_code,
            $candidate->serial_number,
            data_get($candidate->metadata, 'ean_code_candidate'),
            data_get($candidate->metadata, 'serial_number_candidate'),
        ] as $identifier) {
            $identifier = $this->nullableString($identifier);

            if (
                $identifier !== null
                && $normalized === $this->normalizeComparable($identifier)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica se il valore coincide con il brand corrente o suggerito.
     */
    private function matchesBrand(
        ProductIdentificationCandidate $candidate,
        string $value
    ): bool {
        $brand = $this->brandValue($candidate);

        if ($brand === null) {
            return false;
        }

        return $this->normalizeComparable($brand)
            === $this->normalizeComparable($value);
    }

    /**
     * Recupera il brand corrente o, in sua assenza, quello suggerito.
     */
    private function brandValue(
        ProductIdentificationCandidate $candidate
    ): ?string {
        if ($candidate->brand_id !== null) {
            if ($candidate->relationLoaded('brand')) {
                $brand = $candidate->getRelation('brand');

                if ($brand instanceof Brand) {
                    return $this->nullableString($brand->name);
                }
            }

            $brand = Brand::query()->find($candidate->brand_id);

            if ($brand !== null) {
                return $this->nullableString($brand->name);
            }
        }

        return $this->nullableString(
            data_get(
                $this->brandSuggestionResolver->resolve($candidate),
                'value'
            )
        );
    }

    /**
     * Ripulisce una frase rimuovendo tipo prodotto e brand iniziali.
     */
    private function cleanModelPhrase(
        string $phrase,
        ProductIdentificationCandidate $candidate
    ): string {
        $tokens = $this->tokenize($phrase);

        while (
            $tokens !== []
            && $this->isLeadingProductToken($tokens[0])
        ) {
            array_shift($tokens);
        }

        $brand = $this->brandValue($candidate);

        if ($brand !== null) {
            $brandTokens = $this->tokenize($brand);

            while (
                $brandTokens !== []
                && count($tokens) >= count($brandTokens)
                && $this->tokenSequencesEqual(
                    array_slice($tokens, 0, count($brandTokens)),
                    $brandTokens
                )
            ) {
                $tokens = array_slice(
                    $tokens,
                    count($brandTokens)
                );
            }
        }

        return trim(implode(' ', $tokens));
    }

    /**
     * Trova la fine di una sequenza di token all'interno del nome.
     *
     * @param  array<int, string>  $tokens
     * @param  array<int, string>  $needle
     */
    private function findTokenSequenceEnd(
        array $tokens,
        array $needle
    ): ?int {
        $needleCount = count($needle);

        for (
            $index = 0;
            $index <= count($tokens) - $needleCount;
            $index++
        ) {
            $slice = array_slice($tokens, $index, $needleCount);

            if ($this->tokenSequencesEqual($slice, $needle)) {
                return $index + $needleCount - 1;
            }
        }

        return null;
    }

    /**
     * Confronta due sequenze normalizzate.
     *
     * @param  array<int, string>  $left
     * @param  array<int, string>  $right
     */
    private function tokenSequencesEqual(
        array $left,
        array $right
    ): bool {
        if (count($left) !== count($right)) {
            return false;
        }

        foreach ($left as $index => $token) {
            if (
                $this->normalizeComparable($token)
                !== $this->normalizeComparable($right[$index])
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Divide il testo in token mantenendo trattini, slash e punti interni.
     *
     * @return array<int, string>
     */
    private function tokenize(string $value): array
    {
        preg_match_all(
            '/[\p{L}\p{N}]+(?:[\-\/.][\p{L}\p{N}]+)*/u',
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
     * Controlla se il token contiene almeno una lettera e un numero.
     */
    private function containsLettersAndDigits(string $value): bool
    {
        return preg_match('/\p{L}/u', $value) === 1
            && preg_match('/\d/u', $value) === 1;
    }

    /**
     * Controlla parole utilizzabili all'interno di una frase modello.
     */
    private function isPossibleModelWord(string $token): bool
    {
        if (mb_strlen($token) < 2 || mb_strlen($token) > 30) {
            return false;
        }

        return preg_match(
            '/^(?:\p{Lu}[\p{L}\p{N}\-]*|\d{2,4})$/u',
            $token
        ) === 1;
    }

    /**
     * Controlla se il token interrompe una frase modello.
     */
    private function isBoundaryToken(string $token): bool
    {
        return in_array(
            mb_strtolower($token),
            self::MODEL_BOUNDARY_TOKENS,
            true
        );
    }

    /**
     * Controlla i token descrittivi iniziali.
     */
    private function isLeadingProductToken(string $token): bool
    {
        return in_array(
            mb_strtolower($token),
            self::LEADING_PRODUCT_TOKENS,
            true
        );
    }

    /**
     * Controlla se il nome appartiene a un ricambio/accessorio ambiguo.
     */
    private function hasUnsafeNamePrefix(string $name): bool
    {
        $firstToken = mb_strtolower(
            $this->tokenize($name)[0] ?? ''
        );

        return in_array(
            $firstToken,
            self::UNSAFE_NAME_PREFIXES,
            true
        );
    }

    /**
     * Controlla i placeholder generici.
     */
    private function isGenericModelValue(string $value): bool
    {
        return in_array(
            mb_strtolower(trim($value)),
            self::GENERIC_MODEL_VALUES,
            true
        );
    }

    /**
     * Verifica una possibile sequenza EAN/GTIN.
     */
    private function looksLikeEan(string $value): bool
    {
        $digits = preg_replace('/\D+/', '', $value) ?: '';

        return preg_match(
            '/^(?:\d{8}|\d{12}|\d{13}|\d{14})$/',
            $digits
        ) === 1;
    }

    /**
     * Costruisce il payload del suggerimento.
     *
     * @return array<string, mixed>
     */
    private function suggestion(
        string $value,
        string $source,
        string $method,
        ?int $confidence
    ): array {
        return [
            'value' => $value,
            'ref' => null,
            'origin' => 'derived',
            'source' => $source,
            'method' => $method,
            'confidence' => $confidence,
        ];
    }

    /**
     * Normalizza una stringa per confronti non sensibili alla formattazione.
     */
    private function normalizeComparable(string $value): string
    {
        return mb_strtolower(
            preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?: ''
        );
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