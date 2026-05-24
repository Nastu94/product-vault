<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();

            // Codice ISO alpha-2 del paese.
            // Esempi: IT, US, GB, FR, DE.
            $table->string('code', 2)->unique();

            // Nome leggibile del paese.
            // Esempi: Italy, United States, Germany.
            $table->string('name');

            // Permette di disattivare un paese senza eliminarlo.
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
