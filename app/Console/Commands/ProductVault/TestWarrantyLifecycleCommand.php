<?php

namespace App\Console\Commands\ProductVault;

use App\Models\Category;
use App\Models\Currency;
use App\Models\IdentificationStatus;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Team;
use App\Models\User;
use App\Models\Warranty;
use App\Services\Warranties\DefaultWarrantyCreator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TestWarrantyLifecycleCommand extends Command
{
    protected $signature = 'product-vault:test-warranty-lifecycle';

    protected $description = 'Run controlled Warranty lifecycle checks';

    public function handle(DefaultWarrantyCreator $defaultWarrantyCreator): int
    {
        $user = $this->testUser();
        $team = $this->testTeam($user);

        $this->cleanupSyntheticProducts($team);

        $currencyId = Currency::query()
            ->where('code', 'EUR')
            ->value('id');

        $identificationStatusId = IdentificationStatus::query()
            ->where('code', 'user_confirmed')
            ->value('id');

        $electronicsCategory = Category::query()
            ->where('slug', 'electronics')
            ->first();

        $rows = [];
        $failures = [];

        $record = function (string $scenario, string $assertion, bool $passed, mixed $expected = null, mixed $actual = null) use (&$rows, &$failures): void {
            $rows[] = [
                $scenario,
                $assertion,
                $passed ? 'OK' : 'FAIL',
            ];

            if (! $passed) {
                $failures[] = [
                    'scenario' => $scenario,
                    'assertion' => $assertion,
                    'expected' => $expected,
                    'actual' => $actual,
                ];
            }
        };

        $assertEquals = function (string $scenario, string $assertion, mixed $expected, mixed $actual) use ($record): void {
            $record($scenario, $assertion, $expected === $actual, $expected, $actual);
        };

        $assertNotNull = function (string $scenario, string $assertion, mixed $actual) use ($record): void {
            $record($scenario, $assertion, $actual !== null, 'not null', $actual);
        };

        /*
        |--------------------------------------------------------------------------
        | Scenario 1: garanzia legale generica da purchase_date
        |--------------------------------------------------------------------------
        */
        $genericProduct = Product::query()->create([
            'team_id' => $team->id,
            'created_by_user_id' => $user->id,
            'category_id' => null,
            'brand_id' => null,
            'merchant_id' => null,
            'identification_status_id' => $identificationStatusId,
            'currency_id' => $currencyId,
            'name' => 'Warranty Fixture Generic Product',
            'model' => 'WFG-001',
            'serial_number' => 'WFGSN001',
            'ean_code' => '8099999900011',
            'purchase_date' => '2026-06-10',
            'purchase_price' => 199.90,
            'reliability_score' => 90,
            'notes' => null,
        ]);

        $genericWarranty = $defaultWarrantyCreator->createForProduct($genericProduct);
        $genericWarrantyAgain = $defaultWarrantyCreator->createForProduct($genericProduct);

        $assertNotNull('generic_legal_warranty', 'warranty created', $genericWarranty);
        $assertEquals('generic_legal_warranty', 'idempotent same warranty id', $genericWarranty?->id, $genericWarrantyAgain?->id);
        $assertEquals('generic_legal_warranty', 'duration_months', 24, $genericWarranty?->duration_months);
        $assertEquals('generic_legal_warranty', 'starts_at', '2026-06-10', optional($genericWarranty?->starts_at)->format('Y-m-d'));
        $assertEquals('generic_legal_warranty', 'ends_at', '2028-06-10', optional($genericWarranty?->ends_at)->format('Y-m-d'));
        $assertEquals('generic_legal_warranty', 'source', 'calculated', $genericWarranty?->source);
        $assertEquals('generic_legal_warranty', 'confidence_score', 70, $genericWarranty?->confidence_score);
        $assertEquals('generic_legal_warranty', 'rule_type', 'legal_estimate', data_get($genericWarranty?->metadata, 'rule_type'));
        $assertEquals('generic_legal_warranty', 'warranty count remains 1', 1, $genericProduct->warranties()->count());

        /*
        |--------------------------------------------------------------------------
        | Scenario 2: regola categoria più specifica
        |--------------------------------------------------------------------------
        */
        $assertNotNull('category_legal_warranty', 'electronics category exists', $electronicsCategory);

        if ($electronicsCategory) {
            $categoryProduct = Product::query()->create([
                'team_id' => $team->id,
                'created_by_user_id' => $user->id,
                'category_id' => $electronicsCategory->id,
                'brand_id' => null,
                'merchant_id' => null,
                'identification_status_id' => $identificationStatusId,
                'currency_id' => $currencyId,
                'name' => 'Warranty Fixture Electronics Product',
                'model' => 'WFE-001',
                'serial_number' => 'WFESN001',
                'ean_code' => '8099999900028',
                'purchase_date' => '2026-06-10',
                'purchase_price' => 399.90,
                'reliability_score' => 90,
                'notes' => null,
            ]);

            $categoryWarranty = $defaultWarrantyCreator->createForProduct($categoryProduct);

            $assertNotNull('category_legal_warranty', 'warranty created', $categoryWarranty);
            $assertEquals('category_legal_warranty', 'duration_months', 24, $categoryWarranty?->duration_months);
            $assertEquals('category_legal_warranty', 'rule_type', 'category_legal_estimate', data_get($categoryWarranty?->metadata, 'rule_type'));
            $assertEquals('category_legal_warranty', 'rule_priority', 20, data_get($categoryWarranty?->metadata, 'rule_priority'));
            $assertEquals('category_legal_warranty', 'product_category_id', $electronicsCategory->id, data_get($categoryWarranty?->metadata, 'product_category_id'));
        }

        /*
        |--------------------------------------------------------------------------
        | Scenario 3: prodotto senza data acquisto
        |--------------------------------------------------------------------------
        */
        $noDateProduct = Product::query()->create([
            'team_id' => $team->id,
            'created_by_user_id' => $user->id,
            'category_id' => null,
            'brand_id' => null,
            'merchant_id' => null,
            'identification_status_id' => $identificationStatusId,
            'currency_id' => $currencyId,
            'name' => 'Warranty Fixture Product Without Purchase Date',
            'model' => 'WFNODATE-001',
            'serial_number' => 'WFNODATESN001',
            'ean_code' => '8099999900035',
            'purchase_date' => null,
            'purchase_price' => 99.90,
            'reliability_score' => 80,
            'notes' => null,
        ]);

        $noDateWarranty = $defaultWarrantyCreator->createForProduct($noDateProduct);

        $assertEquals('no_purchase_date', 'warranty not created', null, $noDateWarranty);
        $assertEquals('no_purchase_date', 'warranty count remains 0', 0, $noDateProduct->warranties()->count());

        $this->table(['Scenario', 'Assertion', 'Status'], $rows);

        if ($failures !== []) {
            $this->error('Warranty lifecycle checks failed.');

            foreach ($failures as $failure) {
                $this->line('');
                $this->warn($failure['scenario'].' / '.$failure['assertion']);
                $this->line('Expected: '.json_encode($failure['expected'], JSON_UNESCAPED_UNICODE));
                $this->line('Actual:   '.json_encode($failure['actual'], JSON_UNESCAPED_UNICODE));
            }

            return self::FAILURE;
        }

        $this->info('Warranty lifecycle checks passed.');

        return self::SUCCESS;
    }

    private function testUser(): User
    {
        return User::query()->updateOrCreate(
            ['email' => 'warranty-lifecycle@example.com'],
            [
                'name' => 'Warranty Lifecycle Test User',
                'password' => Hash::make('password'),
            ],
        );
    }

    private function testTeam(User $user): Team
    {
        $freePlan = Plan::query()
            ->where('code', 'free')
            ->first();

        $team = Team::query()
            ->where('user_id', $user->id)
            ->where('name', 'Warranty Lifecycle Test Workspace')
            ->first();

        if (! $team) {
            $team = Team::forceCreate([
                'user_id' => $user->id,
                'name' => 'Warranty Lifecycle Test Workspace',
                'personal_team' => true,
                'plan_id' => $freePlan?->id,
            ]);
        } else {
            $team->forceFill([
                'personal_team' => true,
                'plan_id' => $freePlan?->id,
            ])->save();
        }

        $user->forceFill([
            'current_team_id' => $team->id,
        ])->save();

        return $team;
    }

    private function cleanupSyntheticProducts(Team $team): void
    {
        $productIds = Product::withTrashed()
            ->where('team_id', $team->id)
            ->where('name', 'like', 'Warranty Fixture%')
            ->pluck('id');

        if ($productIds->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($productIds): void {
            Warranty::query()
                ->whereIn('product_id', $productIds)
                ->delete();

            Product::withTrashed()
                ->whereIn('id', $productIds)
                ->forceDelete();
        });
    }
}