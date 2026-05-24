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
        Schema::create('categories', function (Blueprint $table) {
            $table->id();

            // Team Jetstream proprietario della categoria, se creata manualmente dall'utente.
            // Nullable perché in futuro potremo avere categorie globali condivise.
            $table->foreignId('team_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Categoria padre per struttura gerarchica.
            // Esempio: "Elettronica" -> "Smartphone".
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('categories')
                ->nullOnDelete();

            // Nome leggibile della categoria.
            $table->string('name');

            // Slug tecnico utile per ricerca, URL futuri e deduplica.
            $table->string('slug')->index();

            // Descrizione opzionale della categoria.
            $table->text('description')->nullable();

            // Durata garanzia predefinita opzionale per la categoria.
            // Non sostituisce le warranty_rules, ma può aiutare l'MVP.
            $table->unsignedSmallInteger('default_warranty_months')->nullable();

            // Ordinamento manuale nella UI.
            $table->unsignedInteger('sort_order')->default(0);

            // Permette di disattivare una categoria senza eliminarla.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['team_id', 'slug']);
            $table->index(['parent_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
