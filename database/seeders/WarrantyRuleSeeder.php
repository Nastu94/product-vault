<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Country;
use App\Models\WarrantyRule;
use App\Models\WarrantyType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WarrantyRuleSeeder extends Seeder
{
    /**
     * Popola le regole garanzia iniziali.
     *
     * Le regole sono globali, quindi team_id resta null.
     * Sono stime configurabili per MVP e possono essere sovrascritte manualmente.
     */
    public function run(): void
    {
        DB::transaction(function () {
            $italy = Country::query()
                ->where('code', 'IT')
                ->first();

            $legalWarranty = WarrantyType::query()
                ->where('code', 'legal')
                ->first();

            if (! $italy || ! $legalWarranty) {
                return;
            }

            $this->createRule(
                countryId: $italy->id,
                warrantyTypeId: $legalWarranty->id,
                categoryId: null,
                durationMonths: 24,
                ruleType: 'legal_estimate',
                sourceNote: 'Default MVP per garanzia legale italiana sui beni nuovi di consumo. Da verificare in base al caso specifico.',
                priority: 10
            );

            $categorySlugs = [
                'electronics',
                'smartphones',
                'computers',
                'consoles-videogames',
                'tv-audio',
                'home',
                'large-appliances',
                'small-appliances',
                'climate-control',
                'mobility',
                'bicycles',
                'electric-scooters',
            ];

            foreach ($categorySlugs as $slug) {
                $category = Category::query()
                    ->where('slug', $slug)
                    ->first();

                if (! $category) {
                    continue;
                }

                $this->createRule(
                    countryId: $italy->id,
                    warrantyTypeId: $legalWarranty->id,
                    categoryId: $category->id,
                    durationMonths: 24,
                    ruleType: 'category_legal_estimate',
                    sourceNote: 'Default MVP categoria per mercato italiano. Da considerare come stima modificabile.',
                    priority: 20
                );
            }
        });
    }

    /**
     * Crea o aggiorna una regola garanzia.
     */
    private function createRule(
        ?int $countryId,
        ?int $warrantyTypeId,
        ?int $categoryId,
        int $durationMonths,
        string $ruleType,
        ?string $sourceNote = null,
        int $priority = 0
    ): WarrantyRule {
        return WarrantyRule::updateOrCreate(
            [
                'team_id' => null,
                'country_id' => $countryId,
                'category_id' => $categoryId,
                'warranty_type_id' => $warrantyTypeId,
                'rule_type' => $ruleType,
            ],
            [
                'duration_months' => $durationMonths,
                'source_note' => $sourceNote,
                'priority' => $priority,
                'is_active' => true,
            ]
        );
    }
}