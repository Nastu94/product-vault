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
        Schema::create('team_types', function (Blueprint $table) {
            $table->id();

            // Codice tecnico del tipo workspace/account.
            // Esempi: personal, family, shop, business.
            $table->string('code')->unique();

            // Nome leggibile mostrabile nella UI o nel backoffice.
            $table->string('name');

            // Descrizione opzionale del tipo workspace.
            $table->text('description')->nullable();

            // Permette di disattivare un tipo senza eliminarlo.
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_types');
    }
};
