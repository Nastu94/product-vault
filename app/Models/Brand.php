<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Brand extends Model
{
    /**
     * Campi assegnabili in massa.
     */
    protected $fillable = [
        'team_id',
        'name',
        'normalized_name',
        'website',
        'is_verified',
        'is_active',
    ];

    /**
     * Conversione automatica dei tipi dato.
     */
    protected function casts(): array
    {
        return [
            'is_verified' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Workspace/team proprietario del brand, se privato.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Prodotti associati a questo brand.
     *
     * Il model Product verrà creato in uno step successivo.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}