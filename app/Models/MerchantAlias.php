<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantAlias extends Model
{
    /**
     * Campi assegnabili in massa.
     */
    protected $fillable = [
        'merchant_id',
        'alias',
        'normalized_alias',
        'source',
        'confidence_score',
        'is_active',
    ];

    /**
     * Conversione automatica dei tipi dato.
     */
    protected function casts(): array
    {
        return [
            'confidence_score' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Merchant normalizzato collegato a questo alias.
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}