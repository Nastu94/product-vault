<?php

namespace Database\Seeders;

use App\Models\TeamType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TeamTypeSeeder extends Seeder
{
    /**
     * Crea i tipi di workspace/team previsti dal progetto.
     */
    public function run(): void
    {
        DB::transaction(function () {
            $items = [
                [
                    'code' => 'personal',
                    'name' => 'Personale',
                    'description' => 'Workspace personale creato automaticamente alla registrazione.',
                ],
                [
                    'code' => 'family',
                    'name' => 'Famiglia',
                    'description' => 'Workspace condiviso tra più membri di una famiglia.',
                ],
                [
                    'code' => 'shop',
                    'name' => 'Negozio',
                    'description' => 'Workspace futuro per negozi, staff e gestione post-vendita.',
                ],
                [
                    'code' => 'business',
                    'name' => 'Business',
                    'description' => 'Workspace futuro per uso aziendale o professionale.',
                ],
            ];

            foreach ($items as $item) {
                TeamType::updateOrCreate(
                    ['code' => $item['code']],
                    $item + ['is_active' => true]
                );
            }

            $personalType = TeamType::query()
                ->where('code', 'personal')
                ->first();

            if ($personalType) {
                DB::table('teams')
                    ->whereNull('team_type_id')
                    ->update([
                        'team_type_id' => $personalType->id,
                    ]);
            }
        });
    }
}