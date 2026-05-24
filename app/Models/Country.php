<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends Model
{
    /**
     * Campi assegnabili in massa.
     */
    protected $fillable = [
        'code',
        'name',
        'is_active',
    ];

    /**
     * Conversione automatica dei tipi dato.
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Regole di garanzia associate a questo paese.
     *
     * Il model WarrantyRule verrà creato in uno step successivo.
     */
    public function warrantyRules(): HasMany
    {
        return $this->hasMany(WarrantyRule::class);
    }

    /**
     * Merchant associati a questo paese.
     *
     * Il model Merchant verrà creato in uno step successivo.
     */
    public function merchants(): HasMany
    {
        return $this->hasMany(Merchant::class);
    }
}