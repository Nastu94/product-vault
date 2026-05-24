<?php

use App\Models\Plan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Collega ogni team/workspace a un piano applicativo.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->foreignId('plan_id')
                ->nullable()
                ->after('personal_team')
                ->constrained()
                ->nullOnDelete();
        });

        $freePlan = Plan::query()
            ->where('code', 'free')
            ->first();

        if ($freePlan) {
            DB::table('teams')
                ->whereNull('plan_id')
                ->update([
                    'plan_id' => $freePlan->id,
                ]);
        }
    }

    /**
     * Rimuove il collegamento tra team/workspace e piano.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropForeign(['plan_id']);
            $table->dropColumn('plan_id');
        });
    }
};