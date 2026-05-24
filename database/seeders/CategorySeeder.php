<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends Seeder
{
    /**
     * Popola le categorie globali iniziali del progetto.
     *
     * Le categorie vengono create con team_id null perché sono globali.
     * In futuro l'utente potrà creare categorie personalizzate legate al proprio team.
     */
    public function run(): void
    {
        DB::transaction(function () {
            $electronics = $this->createCategory(
                name: 'Elettronica',
                slug: 'electronics',
                description: 'Dispositivi elettronici, informatica e tecnologia.',
                defaultWarrantyMonths: 24,
                sortOrder: 10
            );

            $home = $this->createCategory(
                name: 'Casa',
                slug: 'home',
                description: 'Prodotti per la casa, elettrodomestici e accessori domestici.',
                defaultWarrantyMonths: 24,
                sortOrder: 20
            );

            $mobility = $this->createCategory(
                name: 'Mobilità',
                slug: 'mobility',
                description: 'Biciclette, monopattini, accessori e prodotti per la mobilità personale.',
                defaultWarrantyMonths: 24,
                sortOrder: 30
            );

            $collectibles = $this->createCategory(
                name: 'Collezionabili',
                slug: 'collectibles',
                description: 'Carte collezionabili, giochi, oggetti da collezione e prodotti simili.',
                defaultWarrantyMonths: null,
                sortOrder: 40
            );

            $other = $this->createCategory(
                name: 'Altro',
                slug: 'other',
                description: 'Categoria generica per prodotti non ancora classificati.',
                defaultWarrantyMonths: null,
                sortOrder: 999
            );

            $this->createCategory(
                name: 'Smartphone',
                slug: 'smartphones',
                description: 'Telefoni cellulari, smartphone e accessori principali.',
                defaultWarrantyMonths: 24,
                sortOrder: 10,
                parentId: $electronics->id
            );

            $this->createCategory(
                name: 'Computer',
                slug: 'computers',
                description: 'Notebook, desktop, monitor e componenti informatici.',
                defaultWarrantyMonths: 24,
                sortOrder: 20,
                parentId: $electronics->id
            );

            $this->createCategory(
                name: 'Console e videogiochi',
                slug: 'consoles-videogames',
                description: 'Console, videogiochi fisici e accessori gaming.',
                defaultWarrantyMonths: 24,
                sortOrder: 30,
                parentId: $electronics->id
            );

            $this->createCategory(
                name: 'TV e audio',
                slug: 'tv-audio',
                description: 'Televisori, soundbar, speaker, cuffie e dispositivi audio/video.',
                defaultWarrantyMonths: 24,
                sortOrder: 40,
                parentId: $electronics->id
            );

            $this->createCategory(
                name: 'Grandi elettrodomestici',
                slug: 'large-appliances',
                description: 'Frigoriferi, lavatrici, asciugatrici, lavastoviglie e forni.',
                defaultWarrantyMonths: 24,
                sortOrder: 10,
                parentId: $home->id
            );

            $this->createCategory(
                name: 'Piccoli elettrodomestici',
                slug: 'small-appliances',
                description: 'Aspirapolvere, macchine caffè, frullatori, robot da cucina e simili.',
                defaultWarrantyMonths: 24,
                sortOrder: 20,
                parentId: $home->id
            );

            $this->createCategory(
                name: 'Climatizzazione',
                slug: 'climate-control',
                description: 'Condizionatori, ventilatori, deumidificatori e sistemi di riscaldamento.',
                defaultWarrantyMonths: 24,
                sortOrder: 30,
                parentId: $home->id
            );

            $this->createCategory(
                name: 'Biciclette',
                slug: 'bicycles',
                description: 'Biciclette muscolari, e-bike e accessori principali.',
                defaultWarrantyMonths: 24,
                sortOrder: 10,
                parentId: $mobility->id
            );

            $this->createCategory(
                name: 'Monopattini elettrici',
                slug: 'electric-scooters',
                description: 'Monopattini elettrici e accessori collegati.',
                defaultWarrantyMonths: 24,
                sortOrder: 20,
                parentId: $mobility->id
            );

            $this->createCategory(
                name: 'Carte collezionabili',
                slug: 'trading-cards',
                description: 'Carte collezionabili Pokémon, One Piece, Dragon Ball e altri TCG.',
                defaultWarrantyMonths: null,
                sortOrder: 10,
                parentId: $collectibles->id
            );

            $this->createCategory(
                name: 'Prodotto non classificato',
                slug: 'uncategorized-product',
                description: 'Categoria provvisoria per prodotti identificati solo parzialmente.',
                defaultWarrantyMonths: null,
                sortOrder: 10,
                parentId: $other->id
            );
        });
    }

    /**
     * Crea o aggiorna una categoria.
     */
    private function createCategory(
        string $name,
        string $slug,
        ?string $description = null,
        ?int $defaultWarrantyMonths = null,
        int $sortOrder = 0,
        ?int $parentId = null
    ): Category {
        return Category::updateOrCreate(
            [
                'team_id' => null,
                'slug' => $slug,
            ],
            [
                'parent_id' => $parentId,
                'name' => $name,
                'description' => $description,
                'default_warranty_months' => $defaultWarrantyMonths,
                'sort_order' => $sortOrder,
                'is_active' => true,
            ]
        );
    }
}