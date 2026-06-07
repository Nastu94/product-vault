<?php

namespace App\Services\Warranties;

use App\Models\Country;
use App\Models\Product;
use App\Models\Warranty;
use App\Models\WarrantyRule;
use App\Models\WarrantyType;
use Carbon\CarbonInterface;

class DefaultWarrantyCreator
{
    /**
     * Crea una garanzia legale stimata per un prodotto appena confermato.
     *
     * Questo service non decide se il prodotto è corretto: lavora solo dopo
     * la conferma manuale del candidato e produce una garanzia calcolata,
     * modificabile in futuro dall'utente.
     */
    public function createForProduct(Product $product): ?Warranty
    {
        $product->loadMissing([
            'team',
            'category',
            'documents',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Precondizioni minime
        |--------------------------------------------------------------------------
        |
        | Per MVP1 creiamo automaticamente la garanzia solo se abbiamo una data
        | di acquisto. Senza data, creare una garanzia con scadenza sconosciuta
        | sarebbe poco utile e rischierebbe di generare confusione.
        */
        if (! $product->purchase_date instanceof CarbonInterface) {
            return null;
        }

        $warrantyType = WarrantyType::query()
            ->where('code', 'legal')
            ->where('is_active', true)
            ->first();

        if (! $warrantyType) {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | Idempotenza
        |--------------------------------------------------------------------------
        |
        | Se il prodotto ha già una garanzia legale, non ne creiamo una seconda.
        | Questo rende sicuro rilanciare test o processi futuri.
        */
        $existingWarranty = Warranty::query()
            ->where('product_id', $product->id)
            ->where('warranty_type_id', $warrantyType->id)
            ->first();

        if ($existingWarranty) {
            return $existingWarranty;
        }

        $country = Country::query()
            ->where('code', 'IT')
            ->first();

        $rule = $this->bestRuleForProduct(
            product: $product,
            warrantyTypeId: $warrantyType->id,
            countryId: $country?->id,
        );

        if (! $rule) {
            return null;
        }

        $startsAt = $product->purchase_date->copy()->startOfDay();
        $endsAt = $startsAt->copy()->addMonthsNoOverflow($rule->duration_months);

        $sourceDocument = $product->documents()
            ->orderByPivot('created_at')
            ->first();

        return Warranty::query()->create([
            'product_id' => $product->id,
            'warranty_type_id' => $warrantyType->id,
            'source_document_id' => $sourceDocument?->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'duration_months' => $rule->duration_months,
            'source' => 'calculated',
            'confidence_score' => 70,
            'notes' => null,
            'metadata' => [
                'creator' => 'default_warranty_creator_v1',
                'rule_id' => $rule->id,
                'rule_type' => $rule->rule_type,
                'rule_priority' => $rule->priority,
                'country_code' => $country?->code,
                'product_category_id' => $product->category_id,
                'source_note' => $rule->source_note,
                'calculation' => [
                    'starts_at_source' => 'product.purchase_date',
                    'duration_months_source' => 'warranty_rule',
                    'ends_at_formula' => 'starts_at + duration_months',
                ],
            ],
        ]);
    }

    /**
     * Seleziona la regola migliore:
     * - prima regole team-specific, poi globali;
     * - prima categoria specifica, poi generica;
     * - priorità più alta.
     */
    private function bestRuleForProduct(Product $product, int $warrantyTypeId, ?int $countryId): ?WarrantyRule
    {
        return WarrantyRule::query()
            ->where('is_active', true)
            ->where('warranty_type_id', $warrantyTypeId)
            ->when($countryId !== null, fn ($query) => $query->where(function ($query) use ($countryId) {
                $query->where('country_id', $countryId)
                    ->orWhereNull('country_id');
            }))
            ->where(function ($query) use ($product) {
                $query->where('team_id', $product->team_id)
                    ->orWhereNull('team_id');
            })
            ->where(function ($query) use ($product) {
                if ($product->category_id) {
                    $query->where('category_id', $product->category_id)
                        ->orWhereNull('category_id');
                } else {
                    $query->whereNull('category_id');
                }
            })
            ->orderByRaw('CASE WHEN team_id IS NULL THEN 0 ELSE 1 END DESC')
            ->orderByRaw('CASE WHEN category_id IS NULL THEN 0 ELSE 1 END DESC')
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->first();
    }
}