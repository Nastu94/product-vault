<?php

namespace Database\Seeders;

use App\Models\Currency;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\PlanLimit;
use App\Support\Monetization\MonetizationKeys;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $eur = Currency::query()
                ->where('code', 'EUR')
                ->first();

            $catalog = $this->catalog();

            foreach ($catalog as $planDefinition) {
                $plan = Plan::updateOrCreate(
                    ['code' => $planDefinition['code']],
                    [
                        'name' => $planDefinition['name'],
                        'description' => $planDefinition['description'],
                        'monthly_price_cents' =>
                            $planDefinition['monthly_price_cents'],
                        'currency_id' => $eur?->id,
                        'is_active' => true,
                        'sort_order' => $planDefinition['sort_order'],
                    ]
                );

                $this->seedLimits($plan, $planDefinition['limits']);
                $this->seedFeatures($plan, $planDefinition['features']);
            }

            $freePlanId = Plan::query()
                ->where('code', 'free')
                ->value('id');

            if ($freePlanId !== null) {
                DB::table('teams')
                    ->whereNull('plan_id')
                    ->update(['plan_id' => $freePlanId]);
            }
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function catalog(): array
    {
        $baseFeatures = [
            MonetizationKeys::FEATURE_MANUAL_UPLOAD => true,
            MonetizationKeys::FEATURE_BASE_EXTRACTION => true,
            MonetizationKeys::FEATURE_MANUAL_REVIEW => true,
            MonetizationKeys::FEATURE_PRODUCT_ARCHIVE => true,
            MonetizationKeys::FEATURE_ESTIMATED_COVERAGE => true,
            MonetizationKeys::FEATURE_ESSENTIAL_REMINDERS => true,
            MonetizationKeys::FEATURE_ADVANCED_ASSISTED_REVIEW => false,
            MonetizationKeys::FEATURE_EMAIL_IMPORT => false,
            MonetizationKeys::FEATURE_SHARED_WORKSPACE => false,
            MonetizationKeys::FEATURE_MULTIPLE_PRODUCT_CASES => false,
            MonetizationKeys::FEATURE_EXPORT_ASSISTANCE_DOSSIER => false,
            MonetizationKeys::FEATURE_ADVANCED_NOTIFICATIONS => false,
            MonetizationKeys::FEATURE_FULL_HISTORY => false,
            MonetizationKeys::FEATURE_BUSINESS_ASSET_ASSIGNMENT => false,
            MonetizationKeys::FEATURE_BUSINESS_AUDIT_LOG => false,
            MonetizationKeys::FEATURE_API_INTEGRATIONS => false,
        ];

        $premiumFeatures = array_replace($baseFeatures, [
            MonetizationKeys::FEATURE_ADVANCED_ASSISTED_REVIEW => true,
            MonetizationKeys::FEATURE_EMAIL_IMPORT => true,
            MonetizationKeys::FEATURE_MULTIPLE_PRODUCT_CASES => true,
            MonetizationKeys::FEATURE_EXPORT_ASSISTANCE_DOSSIER => true,
            MonetizationKeys::FEATURE_ADVANCED_NOTIFICATIONS => true,
            MonetizationKeys::FEATURE_FULL_HISTORY => true,
        ]);

        $familyFeatures = array_replace($premiumFeatures, [
            MonetizationKeys::FEATURE_SHARED_WORKSPACE => true,
        ]);

        $businessFeatures = array_replace($familyFeatures, [
            MonetizationKeys::FEATURE_BUSINESS_ASSET_ASSIGNMENT => true,
            MonetizationKeys::FEATURE_BUSINESS_AUDIT_LOG => true,
            MonetizationKeys::FEATURE_API_INTEGRATIONS => true,
        ]);

        return [
            [
                'code' => 'free',
                'name' => 'Free',
                'description' => 'Archivio personale essenziale con caricamento, riconoscimento base e revisione manuale.',
                'monthly_price_cents' => 0,
                'sort_order' => 1,
                'limits' => [
                    MonetizationKeys::LIMIT_MAX_DOCUMENTS => [50, 'none'],
                    MonetizationKeys::LIMIT_MAX_PRODUCTS => [50, 'none'],
                    MonetizationKeys::LIMIT_MAX_STORAGE_MB => [500, 'none'],
                    MonetizationKeys::LIMIT_MAX_OCR_PER_MONTH => [30, 'monthly'],
                    MonetizationKeys::LIMIT_MAX_TEAM_MEMBERS => [1, 'none'],
                    MonetizationKeys::LIMIT_MAX_OPEN_PRODUCT_CASES => [1, 'none'],
                ],
                'features' => $baseFeatures,
            ],
            [
                'code' => 'premium_personal',
                'name' => 'Premium personale',
                'description' => 'Più capacità, automazioni avanzate, pratiche multiple ed esportazioni operative.',
                'monthly_price_cents' => 0,
                'sort_order' => 2,
                'limits' => [
                    MonetizationKeys::LIMIT_MAX_DOCUMENTS => [500, 'none'],
                    MonetizationKeys::LIMIT_MAX_PRODUCTS => [500, 'none'],
                    MonetizationKeys::LIMIT_MAX_STORAGE_MB => [10240, 'none'],
                    MonetizationKeys::LIMIT_MAX_OCR_PER_MONTH => [300, 'monthly'],
                    MonetizationKeys::LIMIT_MAX_TEAM_MEMBERS => [1, 'none'],
                    MonetizationKeys::LIMIT_MAX_OPEN_PRODUCT_CASES => [20, 'none'],
                ],
                'features' => $premiumFeatures,
            ],
            [
                'code' => 'family',
                'name' => 'Famiglia',
                'description' => 'Archivio condiviso per nucleo familiare con più membri e maggiore capacità.',
                'monthly_price_cents' => 0,
                'sort_order' => 3,
                'limits' => [
                    MonetizationKeys::LIMIT_MAX_DOCUMENTS => [1500, 'none'],
                    MonetizationKeys::LIMIT_MAX_PRODUCTS => [1000, 'none'],
                    MonetizationKeys::LIMIT_MAX_STORAGE_MB => [30720, 'none'],
                    MonetizationKeys::LIMIT_MAX_OCR_PER_MONTH => [1000, 'monthly'],
                    MonetizationKeys::LIMIT_MAX_TEAM_MEMBERS => [6, 'none'],
                    MonetizationKeys::LIMIT_MAX_OPEN_PRODUCT_CASES => [50, 'none'],
                ],
                'features' => $familyFeatures,
            ],
            [
                'code' => 'business',
                'name' => 'Business',
                'description' => 'Gestione beni in team, audit e integrazioni per organizzazioni e sedi operative.',
                'monthly_price_cents' => 0,
                'sort_order' => 4,
                'limits' => [
                    MonetizationKeys::LIMIT_MAX_DOCUMENTS => [null, 'none'],
                    MonetizationKeys::LIMIT_MAX_PRODUCTS => [null, 'none'],
                    MonetizationKeys::LIMIT_MAX_STORAGE_MB => [null, 'none'],
                    MonetizationKeys::LIMIT_MAX_OCR_PER_MONTH => [null, 'monthly'],
                    MonetizationKeys::LIMIT_MAX_TEAM_MEMBERS => [50, 'none'],
                    MonetizationKeys::LIMIT_MAX_OPEN_PRODUCT_CASES => [null, 'none'],
                ],
                'features' => $businessFeatures,
            ],
        ];
    }

    /**
     * @param array<string, array{0: int|null, 1: string}> $limits
     */
    private function seedLimits(Plan $plan, array $limits): void
    {
        foreach ($limits as $key => [$value, $resetPeriod]) {
            PlanLimit::updateOrCreate(
                [
                    'plan_id' => $plan->id,
                    'limit_key' => $key,
                ],
                [
                    'limit_value' => $value,
                    'reset_period' => $resetPeriod,
                    'description' => $this->limitDescription($key),
                    'metadata' => ['catalog_version' => 'monetization_v1'],
                    'is_active' => true,
                ]
            );
        }

        PlanLimit::query()
            ->where('plan_id', $plan->id)
            ->whereNotIn('limit_key', array_keys($limits))
            ->update(['is_active' => false]);
    }

    /** @param array<string, bool> $features */
    private function seedFeatures(Plan $plan, array $features): void
    {
        foreach ($features as $key => $enabled) {
            PlanFeature::updateOrCreate(
                [
                    'plan_id' => $plan->id,
                    'feature_key' => $key,
                ],
                [
                    'is_enabled' => $enabled,
                    'description' => $this->featureDescription($key),
                    'metadata' => ['catalog_version' => 'monetization_v1'],
                ]
            );
        }

        PlanFeature::query()
            ->where('plan_id', $plan->id)
            ->whereNotIn('feature_key', array_keys($features))
            ->delete();
    }

    private function limitDescription(string $key): string
    {
        return match ($key) {
            MonetizationKeys::LIMIT_MAX_DOCUMENTS => 'Numero massimo di documenti presenti nel workspace.',
            MonetizationKeys::LIMIT_MAX_PRODUCTS => 'Numero massimo di prodotti presenti nel workspace.',
            MonetizationKeys::LIMIT_MAX_STORAGE_MB => 'Spazio massimo occupato dai documenti, espresso in MB.',
            MonetizationKeys::LIMIT_MAX_OCR_PER_MONTH => 'Numero massimo di esecuzioni OCR nel mese corrente.',
            MonetizationKeys::LIMIT_MAX_TEAM_MEMBERS => 'Numero massimo di membri del workspace, incluso il proprietario.',
            MonetizationKeys::LIMIT_MAX_OPEN_PRODUCT_CASES => 'Numero massimo di pratiche contemporaneamente aperte.',
            default => $key,
        };
    }

    private function featureDescription(string $key): string
    {
        return match ($key) {
            MonetizationKeys::FEATURE_MANUAL_UPLOAD => 'Caricamento manuale di documenti.',
            MonetizationKeys::FEATURE_BASE_EXTRACTION => 'Estrazione e riconoscimento documentale di base.',
            MonetizationKeys::FEATURE_MANUAL_REVIEW => 'Revisione manuale dei candidati prodotto.',
            MonetizationKeys::FEATURE_PRODUCT_ARCHIVE => 'Archivio delle schede prodotto.',
            MonetizationKeys::FEATURE_ESTIMATED_COVERAGE => 'Calcolo e gestione delle coperture stimate.',
            MonetizationKeys::FEATURE_ESSENTIAL_REMINDERS => 'Promemoria essenziali sulle scadenze.',
            MonetizationKeys::FEATURE_ADVANCED_ASSISTED_REVIEW => 'Assisted review avanzata.',
            MonetizationKeys::FEATURE_EMAIL_IMPORT => 'Importazione di documenti da email.',
            MonetizationKeys::FEATURE_SHARED_WORKSPACE => 'Workspace condiviso con più membri.',
            MonetizationKeys::FEATURE_MULTIPLE_PRODUCT_CASES => 'Gestione contemporanea di più pratiche prodotto.',
            MonetizationKeys::FEATURE_EXPORT_ASSISTANCE_DOSSIER => 'Esportazione del fascicolo completo di assistenza.',
            MonetizationKeys::FEATURE_ADVANCED_NOTIFICATIONS => 'Notifiche e promemoria avanzati.',
            MonetizationKeys::FEATURE_FULL_HISTORY => 'Cronologia operativa completa.',
            MonetizationKeys::FEATURE_BUSINESS_ASSET_ASSIGNMENT => 'Assegnazione di beni a persone, sedi o reparti.',
            MonetizationKeys::FEATURE_BUSINESS_AUDIT_LOG => 'Audit log per attività del workspace.',
            MonetizationKeys::FEATURE_API_INTEGRATIONS => 'API e integrazioni applicative.',
            default => $key,
        };
    }
}
