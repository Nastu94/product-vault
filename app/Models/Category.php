<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    /**
     * Campi assegnabili in massa.
     */
    protected $fillable = [
        'team_id',
        'parent_id',
        'name',
        'slug',
        'description',
        'default_warranty_months',
        'sort_order',
        'is_active',
    ];

    /**
     * Conversione automatica dei tipi dato.
     */
    protected function casts(): array
    {
        return [
            'default_warranty_months' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Workspace/team proprietario della categoria, se privata.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Categoria padre.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * Sottocategorie.
     */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    /**
     * Prodotti associati a questa categoria.
     *
     * Il model Product verrà creato in uno step successivo.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Regole garanzia associate a questa categoria.
     *
     * Il model WarrantyRule verrà creato in uno step successivo.
     */
    public function warrantyRules(): HasMany
    {
        return $this->hasMany(WarrantyRule::class);
    }
}