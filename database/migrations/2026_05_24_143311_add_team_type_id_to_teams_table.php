<?php

use App\Models\TeamType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Collega ogni team/workspace a un tipo: personal, family, shop, business.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->foreignId('team_type_id')
                ->nullable()
                ->after('plan_id')
                ->constrained()
                ->nullOnDelete();
        });

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
    }

    /**
     * Rimuove il collegamento tra team/workspace e tipo.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropForeign(['team_type_id']);
            $table->dropColumn('team_type_id');
        });
    }
};