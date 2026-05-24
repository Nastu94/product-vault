<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    /**
     * Campi assegnabili in massa.
     */
    protected $fillable = [
        'code',
        'name',
        'description',
        'monthly_price_cents',
        'currency_id',
        'is_active',
        'sort_order',
    ];

    /**
     * Conversione automatica dei tipi dato.
     */
    protected function casts(): array
    {
        return [
            'monthly_price_cents' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Valuta del prezzo del piano.
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * Limiti associati al piano.
     *
     * Il model PlanLimit verrà creato nel prossimo step.
     */
    public function limits(): HasMany
    {
        return $this->hasMany(PlanLimit::class);
    }
}