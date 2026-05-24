<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Merchant extends Model
{
    /**
     * Campi assegnabili in massa.
     */
    protected $fillable = [
        'team_id',
        'country_id',
        'name',
        'normalized_name',
        'vat_number',
        'website',
        'email',
        'phone',
        'address',
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
     * Workspace/team proprietario del merchant, se privato.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Paese associato al merchant.
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * Alias del merchant ricavati da OCR, varianti testuali o nomi alternativi.
     *
     * Il model MerchantAlias verrà creato nel prossimo step.
     */
    public function aliases(): HasMany
    {
        return $this->hasMany(MerchantAlias::class);
    }

    /**
     * Documenti collegati a questo merchant.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * Prodotti acquistati presso questo merchant.
     *
     * Il model Product verrà creato in uno step successivo.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}