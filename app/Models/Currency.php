<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Currency extends Model
{
    /**
     * Campi assegnabili in massa.
     */
    protected $fillable = [
        'code',
        'name',
        'symbol',
        'decimal_places',
        'is_active',
    ];

    /**
     * Conversione automatica dei tipi dato.
     */
    protected function casts(): array
    {
        return [
            'decimal_places' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Documenti associati a questa valuta.
     *
     * La relazione diventerà operativa quando adegueremo la tabella documents
     * sostituendo il campo currency testuale con currency_id.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * Prodotti associati a questa valuta.
     *
     * Il model Product verrà creato in uno step successivo.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}