<?php

namespace App\Console\Commands\ProductVault;

use App\Models\Plan;
use App\Models\ProductUnderstandingFeedback;
use App\Models\ProductUnderstandingGlobalFact;
use App\Models\Team;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

#[Signature('product-vault:seed-understanding-knowledge')]
#[Description('Seed controlled Product Understanding knowledge for development scenarios')]
class SeedUnderstandingKnowledgeCommand extends Command
{

    /**
     * Execute the console command.
     */
    public function handle()
    {
        DB::transaction(function () {
            $freePlan = Plan::query()
                ->where('code', 'free')
                ->first();

            $user = User::query()->updateOrCreate(
                ['email' => 'understanding@example.com'],
                [
                    'name' => 'Product Understanding Test User',
                    'password' => Hash::make('password'),
                ],
            );

            $team = Team::query()
                ->where('user_id', $user->id)
                ->where('name', 'Product Understanding Test Workspace')
                ->first();

            if (! $team) {
                $team = Team::forceCreate([
                    'user_id' => $user->id,
                    'name' => 'Product Understanding Test Workspace',
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

            /*
            |--------------------------------------------------------------------------
            | Global facts forti
            |--------------------------------------------------------------------------
            |
            | Solo EAN-based. Questa è conoscenza globale sintetica, privacy-safe e
            | controllata. Non deriva da documenti reali dell'utente.
            */
            $globalFacts = [
                [
                    'fact_type' => 'ean',
                    'fact_value' => '0196388123456',
                    'canonical_name' => 'Notebook Lenovo ThinkPad X1 Carbon Gen 11',
                    'suggested_category' => 'notebook',
                    'suggested_line_type' => 'durable_product',
                    'seen_count' => 2,
                    'confirmed_count' => 2,
                    'ignored_count' => 0,
                    'global_registration_rate' => 100.00,
                    'global_product_confidence_score' => 69,
                    'canonical_name_counts' => [
                        'Notebook Lenovo ThinkPad X1 Carbon Gen 11' => 2,
                    ],
                    'category_counts' => [
                        'notebook' => 2,
                    ],
                    'line_type_counts' => [
                        'durable_product' => 2,
                    ],
                ],
                [
                    'fact_type' => 'ean',
                    'fact_value' => '8055555012222',
                    'canonical_name' => 'Docking Station USB-C Dual HDMI 4K',
                    'suggested_category' => 'docking_station',
                    'suggested_line_type' => 'accessory',
                    'seen_count' => 2,
                    'confirmed_count' => 1,
                    'ignored_count' => 1,
                    'global_registration_rate' => 50.00,
                    'global_product_confidence_score' => 62,
                    'canonical_name_counts' => [
                        'Docking Station USB-C Dual HDMI 4K' => 2,
                    ],
                    'category_counts' => [
                        'docking_station' => 2,
                    ],
                    'line_type_counts' => [
                        'accessory' => 2,
                    ],
                ],
            ];

            foreach ($globalFacts as $fact) {
                ProductUnderstandingGlobalFact::query()->updateOrCreate(
                    [
                        'fact_type' => $fact['fact_type'],
                        'fact_key' => hash('sha256', $fact['fact_value']),
                    ],
                    $fact + [
                        'metadata' => [
                            'seeded_by' => 'product-vault:seed-understanding-knowledge',
                            'purpose' => 'development_scenario_knowledge',
                        ],
                        'first_seen_at' => now(),
                        'last_seen_at' => now(),
                    ],
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Feedback workspace controllato
            |--------------------------------------------------------------------------
            |
            | Simula revisioni utente già avvenute nello stesso workspace.
            | Non è conoscenza globale: è team-scoped.
            */
            $feedbackRows = [
                [
                    'review_status' => 'confirmed',
                    'candidate_name' => 'Notebook Lenovo ThinkPad X1 Carbon Gen 11',
                    'final_product_name' => 'Notebook Lenovo ThinkPad X1 Carbon Gen 11',
                    'line_description' => 'Notebook Lenovo ThinkPad X1 Carbon Gen 11',
                    'normalized_line_description' => 'notebook lenovo thinkpad x1 carbon gen 11',
                    'analyzer_line_type' => 'durable_product',
                    'analyzer_suggested_category' => 'notebook',
                    'registerable_score' => 84,
                    'non_product_score' => 0,
                ],
                [
                    'review_status' => 'confirmed',
                    'candidate_name' => 'Docking Station USB-C Dual HDMI 4K',
                    'final_product_name' => 'Docking Station USB-C Dual HDMI 4K',
                    'line_description' => 'Docking Station USB-C Duat HOMI 4K',
                    'normalized_line_description' => 'docking station usb c duat homi 4k',
                    'analyzer_line_type' => 'accessory',
                    'analyzer_suggested_category' => 'docking_station',
                    'registerable_score' => 68,
                    'non_product_score' => 0,
                ],
                [
                    'review_status' => 'confirmed',
                    'candidate_name' => 'Sony WH-1000XM5 cuffie wireless nero',
                    'final_product_name' => 'Sony WH-1000XM5 cuffie wireless nero',
                    'line_description' => 'Sony WH-1000XM5 cuffie wireless nero',
                    'normalized_line_description' => 'sony wh 1000xm5 cuffie wireless nero',
                    'analyzer_line_type' => 'durable_product',
                    'analyzer_suggested_category' => 'audio_device',
                    'registerable_score' => 84,
                    'non_product_score' => 0,
                ],
                [
                    'review_status' => 'confirmed',
                    'candidate_name' => 'Sony WH1000XM5 wireless nero',
                    'final_product_name' => 'Sony WH1000XM5 wireless nero',
                    'line_description' => 'Sony WH1000XM5 wireless nero',
                    'normalized_line_description' => 'sony wh1000xm5 wireless nero',
                    'analyzer_line_type' => 'unknown',
                    'analyzer_suggested_category' => null,
                    'registerable_score' => 54,
                    'non_product_score' => 0,
                ],
                [
                    'review_status' => 'ignored',
                    'ignored_reason' => 'not_worth_registering',
                    'candidate_name' => 'ADATTATORE HDMI 4K USB-C',
                    'final_product_name' => null,
                    'line_description' => 'ADATTATORE HDMI 4K USB-C',
                    'normalized_line_description' => 'adattatore hdmi 4k usb c',
                    'analyzer_line_type' => 'accessory',
                    'analyzer_suggested_category' => 'cable',
                    'registerable_score' => 42,
                    'non_product_score' => 0,
                ],
            ];

            ProductUnderstandingFeedback::query()
                ->where('team_id', $team->id)
                ->where('metadata->seeded_by', 'product-vault:seed-understanding-knowledge')
                ->delete();

            foreach ($feedbackRows as $row) {
                ProductUnderstandingFeedback::query()->create($row + [
                    'team_id' => $team->id,
                    'reviewed_by_user_id' => $user->id,
                    'candidate_price' => null,
                    'candidate_ean_code' => null,
                    'raw_text_hash' => hash('sha256', $row['normalized_line_description']),
                    'analyzer_version' => 'seeded_product_understanding_fixture_v1',
                    'signals' => [],
                    'negative_signals' => [],
                    'warnings' => [],
                    'score_breakdown' => [],
                    'metadata' => [
                        'seeded_by' => 'product-vault:seed-understanding-knowledge',
                        'purpose' => 'development_scenario_knowledge',
                    ],
                    'reviewed_at' => now(),
                ]);
            }

            $this->info('Product Understanding knowledge seeded.');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['user_id', $user->id],
                    ['team_id', $team->id],
                    ['global_facts', ProductUnderstandingGlobalFact::count()],
                    ['workspace_feedback', ProductUnderstandingFeedback::query()->where('team_id', $team->id)->count()],
                ],
            );
        });
        return self::SUCCESS;
    }
}
