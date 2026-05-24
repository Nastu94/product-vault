<?php

namespace Database\Seeders;

use App\Models\Currency;
use App\Models\Plan;
use App\Models\PlanLimit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlanSeeder extends Seeder
{
    /**
     * Crea i piani applicativi iniziali e assegna il piano free
     * ai team/workspace già esistenti.
     */
    public function run(): void
    {
        DB::transaction(function () {
            $eur = Currency::query()
                ->where('code', 'EUR')
                ->first();

            $freePlan = Plan::updateOrCreate(
                ['code' => 'free'],
                [
                    'name' => 'Free',
                    'description' => 'Piano gratuito iniziale per validare il flusso MVP.',
                    'monthly_price_cents' => 0,
                    'currency_id' => $eur?->id,
                    'is_active' => true,
                    'sort_order' => 1,
                ]
            );

            $this->seedFreePlanLimits($freePlan);

            DB::table('teams')
                ->whereNull('plan_id')
                ->update([
                    'plan_id' => $freePlan->id,
                ]);
        });
    }

    /**
     * Limiti iniziali del piano gratuito.
     */
    private function seedFreePlanLimits(Plan $plan): void
    {
        $limits = [
            [
                'limit_key' => 'max_documents',
                'limit_value' => 50,
                'reset_period' => 'none',
                'description' => 'Numero massimo di documenti caricabili nel piano gratuito.',
            ],
            [
                'limit_key' => 'max_products',
                'limit_value' => 50,
                'reset_period' => 'none',
                'description' => 'Numero massimo di prodotti gestibili nel piano gratuito.',
            ],
            [
                'limit_key' => 'max_storage_mb',
                'limit_value' => 500,
                'reset_period' => 'none',
                'description' => 'Spazio massimo indicativo disponibile nel piano gratuito.',
            ],
            [
                'limit_key' => 'max_ocr_per_month',
                'limit_value' => 30,
                'reset_period' => 'monthly',
                'description' => 'Numero massimo di elaborazioni OCR mensili nel piano gratuito.',
            ],
            [
                'limit_key' => 'max_team_members',
                'limit_value' => 1,
                'reset_period' => 'none',
                'description' => 'Numero massimo di membri nel workspace gratuito.',
            ],
        ];

        foreach ($limits as $limit) {
            PlanLimit::updateOrCreate(
                [
                    'plan_id' => $plan->id,
                    'limit_key' => $limit['limit_key'],
                ],
                $limit + [
                    'is_active' => true,
                    'metadata' => null,
                ]
            );
        }
    }
}