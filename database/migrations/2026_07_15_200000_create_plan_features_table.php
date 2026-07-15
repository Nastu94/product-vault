<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('feature_key');
            $table->boolean('is_enabled')->default(false);
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['plan_id', 'feature_key'],
                'pf_plan_feature_unique'
            );
            $table->index(
                ['feature_key', 'is_enabled'],
                'pf_feature_enabled_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_features');
    }
};
