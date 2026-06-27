<?php

namespace App\Services\Documents\ReviewSignals;

use Stringable;
use Traversable;

final class ReviewSignalAggregator
{
    public const VERSION = 'review_signal_aggregation_v1';

    /**
     * Ordine semantico usato dalla UI primaria.
     */
    private const GROUP_PRIORITY = [
        'attention' => 4000,
        'missing' => 3000,
        'positive' => 2000,
        'diagnostic' => 1000,
    ];

    /**
     * I warning hanno precedenza sui normali segnali.
     */
    private const KIND_PRIORITY = [
        'warning' => 200,
        'signal' => 100,
    ];

    /**
     * Ordine delle severity restituite dal presenter.
     */
    private const SEVERITY_PRIORITY = [
        'warning' => 40,
        'info' => 30,
        'success' => 20,
        'neutral' => 10,
    ];

    /**
     * Gruppi sempre presenti nel contratto di output.
     */
    private const GROUPS = [
        'attention',
        'positive',
        'missing',
        'diagnostic',
    ];

    public function __construct(
        private readonly ReviewSignalPresenter $presenter
    ) {
    }

    /**
     * Presenta, ordina, raggruppa e deduplica semanticamente i segnali.
     *
     * Il servizio è read-only: non modifica input, candidati, metadata
     * o dati persistiti.
     *
     * Ogni raccolta può avere questa struttura:
     *
     * [
     *     'source' => 'python',
     *     'kind' => 'warning',
     *     'values' => ['unusable_similarity_match'],
     * ]
     *
     * @param  array<int, array<string, mixed>>  $collections
     * @return array<string, mixed>
     */
    public function aggregate(array $collections): array
    {
        $entries = [];
        $inputIndex = 0;

        foreach ($collections as $collection) {
            if (! is_array($collection)) {
                continue;
            }

            $source = $this->normalizeSource(
                $collection['source'] ?? null
            );

            $kind = $this->normalizeKind(
                $collection['kind'] ?? null
            );

            foreach (
                $this->normalizeValues($collection['values'] ?? [])
                as $value
            ) {
                $signal = $this->normalizeSignal($value);

                if ($signal === null) {
                    continue;
                }

                $item = $this->presenter->present(
                    signal: $signal,
                    source: $source,
                    kind: $kind,
                );

                $entries[] = [
                    'item' => $item,
                    'priority' => $this->priority($item),
                    'input_index' => $inputIndex,
                ];

                $inputIndex++;
            }
        }

        /*
         * La diagnostica conserva l'ordine e ogni occorrenza originale.
         */
        $allItems = array_map(
            fn (array $entry): array => $entry['item'],
            $entries
        );

        /*
         * Solo gli elementi esplicitamente abilitati dal presenter
         * partecipano alla UI primaria.
         */
        $primaryEntries = array_values(array_filter(
            $entries,
            fn (array $entry): bool =>
                ($entry['item']['show_in_primary_ui'] ?? false) === true
        ));

        usort(
            $primaryEntries,
            function (array $left, array $right): int {
                $priorityComparison =
                    $right['priority'] <=> $left['priority'];

                if ($priorityComparison !== 0) {
                    return $priorityComparison;
                }

                return $left['input_index'] <=> $right['input_index'];
            }
        );

        $primaryItems = [];
        $representativesByKey = [];
        $suppressedDuplicates = [];

        foreach ($primaryEntries as $entry) {
            $item = $entry['item'];
            $deduplicationKey = $this->deduplicationKey($item);

            if (isset($representativesByKey[$deduplicationKey])) {
                $keptItem =
                    $representativesByKey[$deduplicationKey];

                $suppressedDuplicates[] = [
                    'deduplication_key' => $deduplicationKey,
                    'kept' => $this->diagnosticReference($keptItem),
                    'suppressed' => $this->diagnosticReference($item),
                ];

                continue;
            }

            $representativesByKey[$deduplicationKey] = $item;
            $primaryItems[] = $item;
        }

        $primaryGroups = $this->groupItems($primaryItems);
        $diagnosticGroups = $this->groupItems($allItems);

        return [
            'version' => self::VERSION,

            'primary' => [
                'items' => $primaryItems,
                'groups' => $primaryGroups,
            ],

            'diagnostics' => [
                'items' => $allItems,
                'groups' => $diagnosticGroups,
                'suppressed_duplicates' => $suppressedDuplicates,
            ],

            'counts' => [
                'received' => count($allItems),
                'primary' => count($primaryItems),
                'suppressed_duplicates' =>
                    count($suppressedDuplicates),
                'primary_by_group' =>
                    $this->countGroups($primaryGroups),
                'diagnostics_by_group' =>
                    $this->countGroups($diagnosticGroups),
            ],
        ];
    }

    /**
     * Calcola una priorità generica usando esclusivamente il contratto
     * restituito dal presenter.
     *
     * @param  array<string, mixed>  $item
     */
    private function priority(array $item): int
    {
        $groupPriority = self::GROUP_PRIORITY[
            $item['group'] ?? ''
        ] ?? 0;

        $kindPriority = self::KIND_PRIORITY[
            $item['kind'] ?? ''
        ] ?? 0;

        $severityPriority = self::SEVERITY_PRIORITY[
            $item['severity'] ?? ''
        ] ?? 0;

        $knownBonus = ($item['known'] ?? false) === true
            ? 1
            : 0;

        return $groupPriority
            + $kindPriority
            + $severityPriority
            + $knownBonus;
    }

    /**
     * Recupera la chiave semantica usata per la deduplica primaria.
     *
     * In assenza della chiave prevista dal contratto viene usato un
     * fallback tecnico deterministico.
     *
     * @param  array<string, mixed>  $item
     */
    private function deduplicationKey(array $item): string
    {
        $key = trim((string) (
            $item['deduplication_key'] ?? ''
        ));

        if ($key !== '') {
            return $key;
        }

        return implode(':', [
            (string) ($item['technical_code'] ?? 'unknown'),
            (string) ($item['source'] ?? 'unknown'),
            (string) ($item['kind'] ?? 'signal'),
        ]);
    }

    /**
     * Raggruppa gli elementi senza eliminarne alcuno.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function groupItems(array $items): array
    {
        $groups = $this->emptyGroups();

        foreach ($items as $item) {
            $group = (string) ($item['group'] ?? 'diagnostic');

            if (! array_key_exists($group, $groups)) {
                $group = 'diagnostic';
            }

            $groups[$group][] = $item;
        }

        return $groups;
    }

    /**
     * Crea la forma stabile dei gruppi.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function emptyGroups(): array
    {
        $groups = [];

        foreach (self::GROUPS as $group) {
            $groups[$group] = [];
        }

        return $groups;
    }

    /**
     * Conta gli elementi presenti in ogni gruppo.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $groups
     * @return array<string, int>
     */
    private function countGroups(array $groups): array
    {
        $counts = [];

        foreach (self::GROUPS as $group) {
            $counts[$group] = count($groups[$group] ?? []);
        }

        return $counts;
    }

    /**
     * Conserva i riferimenti tecnici necessari a spiegare la deduplica.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function diagnosticReference(array $item): array
    {
        return [
            'technical_code' =>
                $item['technical_code'] ?? null,
            'raw_value' =>
                $item['raw_value'] ?? null,
            'source' =>
                $item['source'] ?? null,
            'kind' =>
                $item['kind'] ?? null,
            'group' =>
                $item['group'] ?? null,
            'severity' =>
                $item['severity'] ?? null,
        ];
    }

    /**
     * Normalizza una raccolta senza modificarne il valore originale.
     *
     * @return array<int, mixed>
     */
    private function normalizeValues(mixed $values): array
    {
        if ($values === null || $values === '') {
            return [];
        }

        if (is_array($values)) {
            return array_values($values);
        }

        if ($values instanceof Traversable) {
            return array_values(iterator_to_array(
                $values,
                false
            ));
        }

        return [$values];
    }

    /**
     * Accetta soltanto valori rappresentabili come codice testuale.
     */
    private function normalizeSignal(mixed $value): ?string
    {
        if (
            ! is_string($value)
            && ! $value instanceof Stringable
        ) {
            return null;
        }

        $signal = trim((string) $value);

        return $signal !== ''
            ? $signal
            : null;
    }

    /**
     * Conserva la sorgente fornita dal chiamante.
     */
    private function normalizeSource(mixed $source): string
    {
        if (
            ! is_string($source)
            && ! $source instanceof Stringable
        ) {
            return 'unknown';
        }

        $source = trim((string) $source);

        return $source !== ''
            ? $source
            : 'unknown';
    }

    /**
     * Il contratto distingue warning e normali segnali.
     */
    private function normalizeKind(mixed $kind): string
    {
        return $kind === 'warning'
            ? 'warning'
            : 'signal';
    }
}