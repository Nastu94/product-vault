<?php

namespace App\Services\Documents\AssistedReview;

use App\Models\Brand;
use App\Models\Category;
use App\Models\ProductIdentificationCandidate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class AssistedReviewDecisionService
{
    /**
     * Campi gestiti dal contratto Assisted Review v1.
     *
     * @var array<int, string>
     */
    private const SUPPORTED_FIELDS = [
        'brand',
        'category',
        'model',
    ];

    /**
     * Accetta esplicitamente il suggerimento di un campo.
     *
     * Il suggerimento viene applicato al candidato soltanto dopo questa
     * decisione utente. Il candidato resta pending e non viene creato alcun
     * prodotto.
     */
    public function acceptSuggestion(
        ProductIdentificationCandidate $candidate,
        string $fieldName,
        int $userId
    ): ProductIdentificationCandidate {
        $this->assertSupportedField($fieldName);

        if ($userId <= 0) {
            throw new InvalidArgumentException(
                'L’utente che accetta il suggerimento non è valido.'
            );
        }

        return DB::transaction(function () use (
            $candidate,
            $fieldName,
            $userId
        ): ProductIdentificationCandidate {
            $lockedCandidate = ProductIdentificationCandidate::query()
                ->with('document')
                ->lockForUpdate()
                ->findOrFail($candidate->getKey());

            $this->assertReviewable($lockedCandidate);

            $metadata = is_array($lockedCandidate->metadata)
                ? $lockedCandidate->metadata
                : [];

            $field = data_get(
                $metadata,
                "assisted_review.fields.{$fieldName}",
                []
            );

            if (! is_array($field)) {
                throw new RuntimeException(
                    "Metadata Assisted Review non validi per {$fieldName}."
                );
            }

            /*
             * Un retry della stessa decisione non deve produrre nuove scritture
             * o nuove entità.
             */
            if (
                ($field['state'] ?? null) === 'confirmed'
                && data_get(
                    $field,
                    'decision.action'
                ) === 'accepted_suggestion'
            ) {
                return $lockedCandidate->fresh([
                    'brand',
                    'category',
                    'document',
                ]);
            }

            if (($field['state'] ?? null) !== 'suggested') {
                throw new RuntimeException(
                    "Il campo {$fieldName} non contiene un suggerimento accettabile."
                );
            }

            $suggestion = is_array($field['suggestion'] ?? null)
                ? $field['suggestion']
                : [];

            $suggestedValue = $this->nullableString(
                $suggestion['value'] ?? null
            );

            if ($suggestedValue === null) {
                throw new RuntimeException(
                    "Il suggerimento per {$fieldName} non contiene un valore valido."
                );
            }

            $current = match ($fieldName) {
                'brand' => $this->acceptBrandSuggestion(
                    candidate: $lockedCandidate,
                    suggestion: $suggestion,
                    suggestedValue: $suggestedValue,
                ),

                'category' => $this->acceptCategorySuggestion(
                    candidate: $lockedCandidate,
                    suggestion: $suggestion,
                    suggestedValue: $suggestedValue,
                ),

                'model' => $this->acceptModelSuggestion(
                    candidate: $lockedCandidate,
                    suggestedValue: $suggestedValue,
                ),
            };

            $field['state'] = 'confirmed';
            $field['required'] = false;
            $field['current'] = $current;
            $field['suggestion'] = null;
            $field['decision'] = [
                'action' => 'accepted_suggestion',
                'suggested_value' => $suggestedValue,
                'suggestion_source' => $this->nullableString(
                    $suggestion['source'] ?? null
                ),
                'suggestion_method' => $this->nullableString(
                    $suggestion['method'] ?? null
                ),
                'decided_by_user_id' => $userId,
                'decided_at' => now()->toISOString(),
            ];

            unset($field['issues']);

            data_set(
                $metadata,
                "assisted_review.fields.{$fieldName}",
                $field
            );

            $lockedCandidate->metadata = $this->recalculateCompletion(
                $metadata
            );

            /*
             * Brand, categoria o modello sono già stati assegnati in memoria
             * dai metodi dedicati. Un singolo save rende atomica la decisione.
             */
            $lockedCandidate->save();

            return $lockedCandidate->fresh([
                'brand',
                'category',
                'document',
            ]);
        });
    }

    /**
     * Applica un valore inserito o selezionato manualmente dall'utente.
     *
     * Il campo assume lo stato protetto "modified", così una successiva
     * rigenerazione automatica non può sovrascrivere la decisione.
     */
    public function setManualValue(
        ProductIdentificationCandidate $candidate,
        string $fieldName,
        mixed $value,
        int $userId
    ): ProductIdentificationCandidate {
        $this->assertSupportedField($fieldName);

        if ($userId <= 0) {
            throw new InvalidArgumentException(
                'L’utente che modifica il campo non è valido.'
            );
        }

        $normalizedValue = $this->normalizeManualValue(
            fieldName: $fieldName,
            value: $value,
        );

        return DB::transaction(function () use (
            $candidate,
            $fieldName,
            $normalizedValue,
            $userId
        ): ProductIdentificationCandidate {
            $lockedCandidate = ProductIdentificationCandidate::query()
                ->with('document')
                ->lockForUpdate()
                ->findOrFail($candidate->getKey());

            $this->assertReviewable($lockedCandidate);

            $metadata = is_array($lockedCandidate->metadata)
                ? $lockedCandidate->metadata
                : [];

            $field = data_get(
                $metadata,
                "assisted_review.fields.{$fieldName}",
                []
            );

            if (! is_array($field)) {
                throw new RuntimeException(
                    "Metadata Assisted Review non validi per {$fieldName}."
                );
            }

            /*
            * Un retry con lo stesso valore non deve riscrivere la decisione
            * o creare nuovamente entità di supporto.
            */
            if ($this->manualValueAlreadyApplied(
                candidate: $lockedCandidate,
                field: $field,
                fieldName: $fieldName,
                value: $normalizedValue,
            )) {
                return $lockedCandidate->fresh([
                    'brand',
                    'category',
                    'document',
                ]);
            }

            $previousState = $this->nullableString(
                $field['state'] ?? null
            );

            $previousCurrentValue = $this->nullableString(
                data_get($field, 'current.value')
            );

            $previousSuggestedValue = $this->nullableString(
                data_get($field, 'suggestion.value')
            );

            $current = match ($fieldName) {
                'brand' => $this->applyManualBrandValue(
                    candidate: $lockedCandidate,
                    value: (string) $normalizedValue,
                ),

                'category' => $this->applyManualCategoryValue(
                    candidate: $lockedCandidate,
                    categoryId: (int) $normalizedValue,
                ),

                'model' => $this->applyManualModelValue(
                    candidate: $lockedCandidate,
                    value: (string) $normalizedValue,
                ),
            };

            $field['state'] = 'modified';
            $field['required'] = false;
            $field['current'] = $current;
            $field['suggestion'] = null;
            $field['decision'] = [
                'action' => 'manual_value',
                'previous_state' => $previousState,
                'previous_current_value' => $previousCurrentValue,
                'previous_suggested_value' => $previousSuggestedValue,
                'decided_by_user_id' => $userId,
                'decided_at' => now()->toISOString(),
            ];

            /*
            * Gli issue automatici non restano attivi dopo una decisione
            * manuale esplicita dell'utente.
            */
            unset($field['issues']);

            data_set(
                $metadata,
                "assisted_review.fields.{$fieldName}",
                $field
            );

            $lockedCandidate->metadata = $this->recalculateCompletion(
                $metadata
            );

            $lockedCandidate->save();

            return $lockedCandidate->fresh([
                'brand',
                'category',
                'document',
            ]);
        });
    }

    /**
     * Segna esplicitamente un campo come non disponibile.
     *
     * La decisione è ammessa per campi mancanti o suggeriti. Un eventuale
     * valore corrente non affidabile viene rimosso dal candidato, ma resta
     * conservato nei metadata della decisione per tracciabilità.
     */
    public function declineField(
        ProductIdentificationCandidate $candidate,
        string $fieldName,
        int $userId
    ): ProductIdentificationCandidate {
        $this->assertSupportedField($fieldName);

        if ($userId <= 0) {
            throw new InvalidArgumentException(
                'L’utente che completa la revisione non è valido.'
            );
        }

        return DB::transaction(function () use (
            $candidate,
            $fieldName,
            $userId
        ): ProductIdentificationCandidate {
            $lockedCandidate = ProductIdentificationCandidate::query()
                ->with('document')
                ->lockForUpdate()
                ->findOrFail($candidate->getKey());

            $this->assertReviewable($lockedCandidate);

            $metadata = is_array($lockedCandidate->metadata)
                ? $lockedCandidate->metadata
                : [];

            $field = data_get(
                $metadata,
                "assisted_review.fields.{$fieldName}",
                []
            );

            if (! is_array($field)) {
                throw new RuntimeException(
                    "Metadata Assisted Review non validi per {$fieldName}."
                );
            }

            /*
            * Un retry della stessa decisione non deve aggiornare nuovamente
            * timestamp o metadata.
            */
            if (
                ($field['state'] ?? null) === 'declined'
                && data_get(
                    $field,
                    'decision.action'
                ) === 'marked_unavailable'
            ) {
                return $lockedCandidate->fresh([
                    'brand',
                    'category',
                    'document',
                ]);
            }

            $previousState = $this->nullableString(
                $field['state'] ?? null
            );

            if (! in_array(
                $previousState,
                ['missing', 'suggested'],
                true
            )) {
                throw new RuntimeException(
                    "Il campo {$fieldName} non può essere segnato come non disponibile nello stato corrente."
                );
            }

            $previousCurrent = is_array(
                $field['current'] ?? null
            )
                ? $field['current']
                : null;

            $previousSuggestion = is_array(
                $field['suggestion'] ?? null
            )
                ? $field['suggestion']
                : null;

            /*
            * Il campo reale del candidato deve essere svuotato. In particolare,
            * un valore tecnico non affidabile non deve arrivare al Product.
            */
            $this->clearCandidateField(
                candidate: $lockedCandidate,
                fieldName: $fieldName,
            );

            $field['state'] = 'declined';
            $field['required'] = false;
            $field['current'] = null;
            $field['suggestion'] = null;
            $field['decision'] = [
                'action' => 'marked_unavailable',
                'previous_state' => $previousState,
                'previous_current' => $previousCurrent,
                'previous_suggestion' => $previousSuggestion,
                'decided_by_user_id' => $userId,
                'decided_at' => now()->toISOString(),
            ];

            unset($field['issues']);

            data_set(
                $metadata,
                "assisted_review.fields.{$fieldName}",
                $field
            );

            $lockedCandidate->metadata = $this->recalculateCompletion(
                $metadata
            );

            $lockedCandidate->save();

            return $lockedCandidate->fresh([
                'brand',
                'category',
                'document',
            ]);
        });
    }

    /**
     * Applica un suggerimento brand.
     *
     * Se il suggerimento non punta a un brand esistente, la conferma esplicita
     * crea o riusa un brand privato del team.
     *
     * @param  array<string, mixed>  $suggestion
     * @return array<string, mixed>
     */
    private function acceptBrandSuggestion(
        ProductIdentificationCandidate $candidate,
        array $suggestion,
        string $suggestedValue
    ): array {
        $teamId = $candidate->document?->team_id;

        if ($teamId === null) {
            throw new RuntimeException(
                'Il candidato non appartiene a un workspace valido.'
            );
        }

        $referenceId = $this->positiveInteger(
            data_get($suggestion, 'ref.id')
        );

        $brand = null;

        if ($referenceId !== null) {
            $brand = Brand::query()
                ->whereKey($referenceId)
                ->where('is_active', true)
                ->where(function ($query) use ($teamId): void {
                    $query
                        ->whereNull('team_id')
                        ->orWhere('team_id', $teamId);
                })
                ->first();

            if ($brand === null) {
                throw new RuntimeException(
                    'Il brand suggerito non è disponibile nel workspace corrente.'
                );
            }
        }

        $normalizedName = $this->normalizeName($suggestedValue);

        if ($brand === null) {
            $brand = Brand::query()
                ->whereNull('team_id')
                ->where('normalized_name', $normalizedName)
                ->where('is_active', true)
                ->first();
        }

        if ($brand === null) {
            $brand = Brand::query()
                ->where('team_id', $teamId)
                ->where('normalized_name', $normalizedName)
                ->where('is_active', true)
                ->first();
        }

        if ($brand === null) {
            $brand = Brand::query()->create([
                'team_id' => $teamId,
                'name' => $suggestedValue,
                'normalized_name' => $normalizedName,
                'website' => null,
                'is_verified' => false,
                'is_active' => true,
            ]);
        }

        $candidate->brand_id = $brand->id;

        return [
            'value' => $brand->name,
            'ref' => [
                'type' => 'brand',
                'id' => $brand->id,
                'key' => $brand->normalized_name,
            ],
            'origin' => 'user_confirmed',
            'source' => 'user_review',
            'method' => 'accepted_assisted_review_suggestion',
            'confidence' => null,
        ];
    }

    /**
     * Applica un suggerimento categoria senza creare nuove categorie.
     *
     * @param  array<string, mixed>  $suggestion
     * @return array<string, mixed>
     */
    private function acceptCategorySuggestion(
        ProductIdentificationCandidate $candidate,
        array $suggestion,
        string $suggestedValue
    ): array {
        $teamId = $candidate->document?->team_id;

        if ($teamId === null) {
            throw new RuntimeException(
                'Il candidato non appartiene a un workspace valido.'
            );
        }

        $referenceId = $this->positiveInteger(
            data_get($suggestion, 'ref.id')
        );

        $referenceKey = $this->nullableString(
            data_get($suggestion, 'ref.key')
        );

        $categoryQuery = Category::query()
            ->where('is_active', true)
            ->where(function ($query) use ($teamId): void {
                $query
                    ->whereNull('team_id')
                    ->orWhere('team_id', $teamId);
            });

        if ($referenceId !== null) {
            $categoryQuery->whereKey($referenceId);
        } elseif ($referenceKey !== null) {
            $categoryQuery->where('slug', $referenceKey);
        } else {
            throw new RuntimeException(
                'Il suggerimento categoria non contiene un riferimento valido.'
            );
        }

        $category = $categoryQuery->first();

        if ($category === null) {
            throw new RuntimeException(
                'La categoria suggerita non è disponibile nel workspace corrente.'
            );
        }

        $candidate->category_id = $category->id;

        return [
            'value' => $category->name ?: $suggestedValue,
            'ref' => [
                'type' => 'category',
                'id' => $category->id,
                'key' => $category->slug,
            ],
            'origin' => 'user_confirmed',
            'source' => 'user_review',
            'method' => 'accepted_assisted_review_suggestion',
            'confidence' => null,
        ];
    }

    /**
     * Applica un suggerimento modello.
     *
     * @return array<string, mixed>
     */
    private function acceptModelSuggestion(
        ProductIdentificationCandidate $candidate,
        string $suggestedValue
    ): array {
        $candidate->model = $suggestedValue;

        return [
            'value' => $suggestedValue,
            'ref' => null,
            'origin' => 'user_confirmed',
            'source' => 'user_review',
            'method' => 'accepted_assisted_review_suggestion',
            'confidence' => null,
        ];
    }

    /**
     * Applica un brand inserito manualmente.
     *
     * Il brand viene riutilizzato se esiste già globalmente o nel team.
     * In caso contrario viene creato come brand privato e non verificato.
     *
     * @return array<string, mixed>
     */
    private function applyManualBrandValue(
        ProductIdentificationCandidate $candidate,
        string $value
    ): array {
        $teamId = $candidate->document?->team_id;

        if ($teamId === null) {
            throw new RuntimeException(
                'Il candidato non appartiene a un workspace valido.'
            );
        }

        $normalizedName = $this->normalizeName($value);

        $brand = Brand::query()
            ->whereNull('team_id')
            ->where('normalized_name', $normalizedName)
            ->where('is_active', true)
            ->first();

        if ($brand === null) {
            $brand = Brand::query()
                ->where('team_id', $teamId)
                ->where('normalized_name', $normalizedName)
                ->where('is_active', true)
                ->first();
        }

        if ($brand === null) {
            $brand = Brand::query()->create([
                'team_id' => $teamId,
                'name' => $value,
                'normalized_name' => $normalizedName,
                'website' => null,
                'is_verified' => false,
                'is_active' => true,
            ]);
        }

        $candidate->brand_id = $brand->id;

        return [
            'value' => $brand->name,
            'ref' => [
                'type' => 'brand',
                'id' => $brand->id,
                'key' => $brand->normalized_name,
            ],
            'origin' => 'user_modified',
            'source' => 'user_review',
            'method' => 'manual_value',
            'confidence' => null,
        ];
    }

    /**
     * Applica una categoria selezionata manualmente.
     *
     * Non vengono create nuove categorie dalla revisione: la categoria deve
     * già esistere ed essere accessibile nel workspace corrente.
     *
     * @return array<string, mixed>
     */
    private function applyManualCategoryValue(
        ProductIdentificationCandidate $candidate,
        int $categoryId
    ): array {
        $teamId = $candidate->document?->team_id;

        if ($teamId === null) {
            throw new RuntimeException(
                'Il candidato non appartiene a un workspace valido.'
            );
        }

        $category = Category::query()
            ->whereKey($categoryId)
            ->where('is_active', true)
            ->where(function ($query) use ($teamId): void {
                $query
                    ->whereNull('team_id')
                    ->orWhere('team_id', $teamId);
            })
            ->first();

        if ($category === null) {
            throw new RuntimeException(
                'La categoria selezionata non è disponibile nel workspace corrente.'
            );
        }

        $candidate->category_id = $category->id;

        return [
            'value' => $category->name,
            'ref' => [
                'type' => 'category',
                'id' => $category->id,
                'key' => $category->slug,
            ],
            'origin' => 'user_modified',
            'source' => 'user_review',
            'method' => 'manual_value',
            'confidence' => null,
        ];
    }

    /**
     * Applica un modello inserito manualmente.
     *
     * @return array<string, mixed>
     */
    private function applyManualModelValue(
        ProductIdentificationCandidate $candidate,
        string $value
    ): array {
        $candidate->model = $value;

        return [
            'value' => $value,
            'ref' => null,
            'origin' => 'user_modified',
            'source' => 'user_review',
            'method' => 'manual_value',
            'confidence' => null,
        ];
    }

    /**
     * Rimuove dal candidato il valore operativo del campo dichiarato
     * non disponibile.
     */
    private function clearCandidateField(
        ProductIdentificationCandidate $candidate,
        string $fieldName
    ): void {
        switch ($fieldName) {
            case 'brand':
                $candidate->brand_id = null;

                break;

            case 'category':
                $candidate->category_id = null;

                break;

            case 'model':
                $candidate->model = null;

                break;

            default:
                throw new InvalidArgumentException(
                    "Campo Assisted Review non supportato: {$fieldName}."
                );
        }
    }

    /**
     * Ricalcola i campi che richiedono ancora intervento.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function recalculateCompletion(array $metadata): array
    {
        $completionFields = [];

        foreach (self::SUPPORTED_FIELDS as $fieldName) {
            $state = data_get(
                $metadata,
                "assisted_review.fields.{$fieldName}.state"
            );

            if (in_array($state, ['missing', 'suggested'], true)) {
                $completionFields[] = $fieldName;
            }
        }

        data_set(
            $metadata,
            'assisted_review.completion_fields',
            $completionFields
        );

        data_set(
            $metadata,
            'assisted_review.needs_user_completion',
            $completionFields !== []
        );

        return $metadata;
    }

    /**
     * Verifica che il candidato possa ancora essere revisionato.
     */
    private function assertReviewable(
        ProductIdentificationCandidate $candidate
    ): void {
        if (
            $candidate->product_id !== null
            || $candidate->review_status !== 'pending'
        ) {
            throw new RuntimeException(
                'Il candidato non è più disponibile per la revisione.'
            );
        }
    }

    /**
     * Verifica che il campo appartenga al contratto v1.
     */
    private function assertSupportedField(string $fieldName): void
    {
        if (! in_array($fieldName, self::SUPPORTED_FIELDS, true)) {
            throw new InvalidArgumentException(
                "Campo Assisted Review non supportato: {$fieldName}."
            );
        }
    }

    /**
     * Normalizza e valida il valore manuale in base al campo.
     */
    private function normalizeManualValue(
        string $fieldName,
        mixed $value
    ): string|int {
        if ($fieldName === 'category') {
            $categoryId = $this->positiveInteger($value);

            if ($categoryId === null) {
                throw new InvalidArgumentException(
                    'La categoria selezionata non è valida.'
                );
            }

            return $categoryId;
        }

        if (! is_string($value) && ! is_numeric($value)) {
            throw new InvalidArgumentException(
                "Il valore manuale per {$fieldName} non è valido."
            );
        }

        $normalized = trim((string) $value);

        if ($normalized === '') {
            throw new InvalidArgumentException(
                "Il valore manuale per {$fieldName} non può essere vuoto."
            );
        }

        if (mb_strlen($normalized) > 255) {
            throw new InvalidArgumentException(
                "Il valore manuale per {$fieldName} è troppo lungo."
            );
        }

        return $normalized;
    }

    /**
     * Verifica se la stessa modifica manuale è già stata applicata.
     *
     * @param  array<string, mixed>  $field
     */
    private function manualValueAlreadyApplied(
        ProductIdentificationCandidate $candidate,
        array $field,
        string $fieldName,
        string|int $value
    ): bool {
        if (
            ($field['state'] ?? null) !== 'modified'
            || data_get($field, 'decision.action') !== 'manual_value'
        ) {
            return false;
        }

        return match ($fieldName) {
            'brand' => (
                $candidate->brand_id !== null
                && $this->positiveInteger(
                    data_get($field, 'current.ref.id')
                ) === (int) $candidate->brand_id
                && $this->normalizeName(
                    (string) data_get($field, 'current.value')
                ) === $this->normalizeName((string) $value)
            ),

            'category' => (
                $candidate->category_id !== null
                && (int) $candidate->category_id === (int) $value
                && $this->positiveInteger(
                    data_get($field, 'current.ref.id')
                ) === (int) $value
            ),

            'model' => (
                $candidate->model === (string) $value
                && $this->nullableString(
                    data_get($field, 'current.value')
                ) === (string) $value
            ),

            default => false,
        };
    }

    /**
     * Normalizza il nome di un'entità testuale.
     */
    private function normalizeName(string $value): string
    {
        $normalized = Str::ascii($value);
        $normalized = mb_strtolower($normalized);

        $normalized = preg_replace(
            '/[^a-z0-9]+/i',
            ' ',
            $normalized
        ) ?: $normalized;

        return trim(
            preg_replace('/\s+/', ' ', $normalized)
                ?: $normalized
        );
    }

    /**
     * Converte un valore in intero positivo.
     */
    private function positiveInteger(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $integer = (int) $value;

        return $integer > 0 ? $integer : null;
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