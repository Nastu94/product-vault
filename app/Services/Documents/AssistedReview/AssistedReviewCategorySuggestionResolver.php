<?php

namespace App\Services\Documents\AssistedReview;

use App\Models\Category;
use App\Models\ProductIdentificationCandidate;

class AssistedReviewCategorySuggestionResolver
{
    /**
     * Mapping macro basato su tipi prodotto chiaramente presenti nel nome.
     *
     * Le regole puntano esclusivamente a categorie globali già esistenti.
     * L'ordine non assegna automaticamente la categoria: in caso di conflitto
     * tra slug differenti il resolver non propone alcun risultato.
     *
     * @var array<int, array<string, mixed>>
     */
    private const NAME_RULES = [
        [
            'slug' => 'smartphones',
            'signals' => [
                'smartphone',
                'telefono',
                'iphone',
            ],
            'confidence' => 90,
        ],
        [
            'slug' => 'consoles-videogames',
            'signals' => [
                'console',
                'controller',
                'playstation',
                'xbox',
                'nintendo switch',
            ],
            'confidence' => 90,
        ],
        [
            'slug' => 'tv-audio',
            'signals' => [
                'smart tv',
                'televisore',
                'tv',
                'cuffie',
                'auricolari',
                'speaker',
                'soundbar',
                'microfono',
                'cavo audio',
            ],
            'confidence' => 86,
        ],
        [
            'slug' => 'climate-control',
            'signals' => [
                'deumidificatore',
                'condizionatore',
                'climatizzatore',
                'ventilatore',
                'termoventilatore',
            ],
            'confidence' => 88,
        ],
        [
            'slug' => 'large-appliances',
            'signals' => [
                'lavatrice',
                'lavastoviglie',
                'frigorifero',
                'asciugatrice',
                'forno',
            ],
            'confidence' => 88,
        ],
        [
            'slug' => 'small-appliances',
            'signals' => [
                'aspirapolvere',
                'lavapavimenti',
                'bilancia',
            ],
            'confidence' => 84,
        ],
        [
            'slug' => 'computers',
            'signals' => [
                'notebook',
                'laptop',
                'monitor',
                'nas',
                'router',
                'scanner',
                'ups',
                'ssd',
                'webcam',
                'docking station',
                'docking',
                'dock',
                'cavo rete',
            ],
            'confidence' => 84,
        ],
        [
            'slug' => 'home',
            'signals' => [
                'lampada smart',
                'lampadina smart',
                'smart light',
            ],
            'confidence' => 78,
        ],
        [
            'slug' => 'electronics',
            'signals' => [
                'tablet',
                'fotocamera',
                'mirrorless',
                'camera',
                'obiettivo',
                'gimbal',
                'stabilizzatore',
                'powerbank',
                'power bank',
                'memory card',
                'cavo usb',
                'usb cable',
            ],
            'confidence' => 80,
        ],
    ];

    /**
     * Mapping dei tipi semantici prodotti dal Product Line Analyzer.
     *
     * Ogni tipo richiede anche un segnale diretto nel nome. Questo evita,
     * per esempio, che una Smart TV venga classificata come cavo soltanto
     * perché nel testo compare HDMI.
     *
     * @var array<string, array<string, mixed>>
     */
    private const ANALYSIS_RULES = [
        'smartphone' => [
            'slug' => 'smartphones',
            'signals' => ['smartphone', 'telefono', 'iphone'],
        ],
        'tablet' => [
            'slug' => 'electronics',
            'signals' => ['tablet', 'ipad'],
        ],
        'notebook' => [
            'slug' => 'computers',
            'signals' => ['notebook', 'laptop'],
        ],
        'monitor' => [
            'slug' => 'computers',
            'signals' => ['monitor'],
        ],
        'printer' => [
            'slug' => 'computers',
            'signals' => ['stampante', 'printer'],
        ],
        'network_device' => [
            'slug' => 'computers',
            'signals' => ['router', 'modem', 'access point'],
        ],
        'docking_station' => [
            'slug' => 'computers',
            'signals' => ['docking station', 'docking', 'dock'],
        ],
        'console' => [
            'slug' => 'consoles-videogames',
            'signals' => ['console', 'playstation', 'xbox'],
        ],
        'tv' => [
            'slug' => 'tv-audio',
            'signals' => ['smart tv', 'televisore', 'tv'],
        ],
        'audio_device' => [
            'slug' => 'tv-audio',
            'signals' => [
                'cuffie',
                'auricolari',
                'speaker',
                'soundbar',
                'microfono',
            ],
        ],
        'vacuum_cleaner' => [
            'slug' => 'small-appliances',
            'signals' => ['aspirapolvere', 'lavapavimenti'],
        ],
        'smart_light' => [
            'slug' => 'home',
            'signals' => [
                'lampada smart',
                'lampadina smart',
                'smart light',
            ],
        ],
        'powerbank' => [
            'slug' => 'electronics',
            'signals' => ['powerbank', 'power bank'],
        ],
        'cable' => [
            'slug' => 'electronics',
            'signals' => ['cavo', 'cable'],
        ],
        'charger' => [
            'slug' => 'electronics',
            'signals' => ['caricatore', 'charger', 'alimentatore'],
        ],
        'adapter' => [
            'slug' => 'electronics',
            'signals' => ['adattatore', 'adapter'],
        ],
    ];

    /**
     * Segnali per i quali una proposta macro sarebbe troppo aggressiva.
     *
     * Questi nomi rappresentano spesso consumabili o ricambi e devono restare
     * da completare manualmente.
     *
     * @var array<int, string>
     */
    private const UNSAFE_NAME_SIGNALS = [
        'filtro hepa',
        'kit spazzole',
        'batterie ricaricabili',
        'vendita al metro',
    ];

    /**
     * Cache locale delle categorie risolte durante la singola esecuzione.
     *
     * @var array<string, Category|null>
     */
    private array $categoryCache = [];

    /**
     * Restituisce il miglior suggerimento categoria disponibile.
     *
     * Nessun valore viene assegnato direttamente a category_id.
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
            ?? $this->fromNameMapping($candidate)
            ?? $this->fromCorroboratedAnalysis($candidate, $metadata);
    }

    /**
     * Recupera una categoria già risolta dalla knowledge iniziale.
     *
     * Supporta sia lo snapshot product_understanding_category sia la summary
     * dei line pattern per compatibilità con metadata precedenti.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>|null
     */
    private function fromInitialKnowledge(array $metadata): ?array
    {
        $categoryContext = $metadata[
            'product_understanding_category'
        ] ?? null;

        if (
            is_array($categoryContext)
            && ($categoryContext['matched'] ?? false) === true
        ) {
            $slug = $this->nullableString(
                $categoryContext['category_slug']
                    ?? $categoryContext['suggested_category_slug']
                    ?? null
            );

            if ($slug !== null) {
                return $this->suggestionForSlug(
                    slug: $slug,
                    source: 'initial_knowledge',
                    method: $this->nullableString(
                        $categoryContext['match_type'] ?? null
                    ) ?? 'matched_category_snapshot',
                    confidence: null
                );
            }
        }

        $summary = data_get(
            $metadata,
            'product_understanding_initial_knowledge.summary'
        );

        if (! is_array($summary)) {
            return null;
        }

        $slug = $this->nullableString(
            $summary['best_suggested_category_slug'] ?? null
        );

        if ($slug === null) {
            return null;
        }

        return $this->suggestionForSlug(
            slug: $slug,
            source: 'initial_knowledge',
            method: 'initial_line_pattern_summary',
            confidence: null
        );
    }

    /**
     * Applica un mapping prudente del tipo prodotto presente nel nome.
     *
     * Un bundle o un nome con più categorie differenti resta senza proposta.
     *
     * @return array<string, mixed>|null
     */
    private function fromNameMapping(
        ProductIdentificationCandidate $candidate
    ): ?array {
        $name = $this->nullableString($candidate->name);

        if ($name === null) {
            return null;
        }

        if (
            $this->isAmbiguousMultiProductName($name)
            || $this->containsAnySignal(
                $name,
                self::UNSAFE_NAME_SIGNALS
            )
        ) {
            return null;
        }

        $matches = [];

        foreach (self::NAME_RULES as $rule) {
            if (! $this->containsAnySignal(
                $name,
                $rule['signals']
            )) {
                continue;
            }

            /*
             * La chiave per slug elimina duplicati della stessa macro-categoria
             * senza nascondere conflitti tra categorie differenti.
             */
            $matches[$rule['slug']] = $rule;
        }

        if (count($matches) !== 1) {
            return null;
        }

        $match = array_values($matches)[0];

        return $this->suggestionForSlug(
            slug: $match['slug'],
            source: 'product_type_mapping',
            method: 'name_product_type_mapping',
            confidence: $match['confidence']
        );
    }

    /**
     * Usa la categoria semantica dell'analyzer soltanto quando il nome contiene
     * anche un segnale diretto coerente.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>|null
     */
    private function fromCorroboratedAnalysis(
        ProductIdentificationCandidate $candidate,
        array $metadata
    ): ?array {
        $analysis = $metadata['product_understanding'] ?? null;

        if (! is_array($analysis)) {
            return null;
        }

        $analysisCategory = $this->nullableString(
            $analysis['suggested_category'] ?? null
        );

        if (
            $analysisCategory === null
            || ! isset(self::ANALYSIS_RULES[$analysisCategory])
        ) {
            return null;
        }

        $name = $this->nullableString($candidate->name);

        if ($name === null) {
            return null;
        }

        $rule = self::ANALYSIS_RULES[$analysisCategory];

        if (! $this->containsAnySignal($name, $rule['signals'])) {
            return null;
        }

        return $this->suggestionForSlug(
            slug: $rule['slug'],
            source: 'product_line_analysis',
            method: 'corroborated_semantic_category_mapping',
            confidence: null
        );
    }

    /**
     * Costruisce il payload finale risolvendo una categoria globale attiva.
     *
     * @return array<string, mixed>|null
     */
    private function suggestionForSlug(
        string $slug,
        string $source,
        string $method,
        ?int $confidence
    ): ?array {
        $category = $this->findGlobalCategory($slug);

        if ($category === null) {
            return null;
        }

        return [
            'value' => $category->name,
            'ref' => [
                'type' => 'category',
                'id' => $category->getKey(),
                'key' => $category->slug,
            ],
            'origin' => 'derived',
            'source' => $source,
            'method' => $method,
            'confidence' => $confidence,
        ];
    }

    /**
     * Recupera una categoria globale attiva evitando query duplicate.
     */
    private function findGlobalCategory(string $slug): ?Category
    {
        if (array_key_exists($slug, $this->categoryCache)) {
            return $this->categoryCache[$slug];
        }

        $category = Category::query()
            ->whereNull('team_id')
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();

        $this->categoryCache[$slug] = $category;

        return $category;
    }

    /**
     * Verifica la presenza di almeno uno dei segnali richiesti.
     *
     * @param  array<int, string>  $signals
     */
    private function containsAnySignal(
        string $text,
        array $signals
    ): bool {
        foreach ($signals as $signal) {
            if ($this->containsSignal($text, $signal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cerca un segnale evitando match dentro parole più lunghe.
     */
    private function containsSignal(
        string $text,
        string $signal
    ): bool {
        $text = $this->normalizeText($text);
        $signal = $this->normalizeText($signal);

        if ($text === '' || $signal === '') {
            return false;
        }

        if (str_contains($signal, ' ')) {
            return str_contains($text, $signal);
        }

        return preg_match(
            '/(?<![\p{L}\p{N}])'
            . preg_quote($signal, '/')
            . '(?![\p{L}\p{N}])/u',
            $text
        ) === 1;
    }

    /**
     * Evita classificazioni arbitrarie sui bundle espliciti.
     *
     * Il solo carattere "+" non è sufficiente: può separare specifiche,
     * quantità o porte, come "2 USB-C + 1 USB-A". Gli eventuali conflitti tra
     * tipi prodotto differenti vengono già gestiti dal numero di slug trovati
     * nel mapping del nome.
     */
    private function isAmbiguousMultiProductName(string $name): bool
    {
        return $this->containsSignal($name, 'bundle');
    }

    /**
     * Normalizza il testo per il matching strutturale.
     */
    private function normalizeText(string $value): string
    {
        $normalized = mb_strtolower($value);

        $normalized = preg_replace(
            '/[^\p{L}\p{N}]+/u',
            ' ',
            $normalized
        ) ?: $normalized;

        return trim(
            preg_replace('/\s+/', ' ', $normalized)
                ?: $normalized
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