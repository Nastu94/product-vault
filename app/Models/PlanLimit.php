<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlanLimit extends Model
{
    /**
     * Campi assegnabili in massa.
     */
    protected $fillable = [
        'plan_id',
        'limit_key',
        'limit_value',
        'reset_period',
        'description',
        'metadata',
        'is_active',
    ];

    /**
     * Conversione automatica dei tipi dato.
     */
    protected function casts(): array
    {
        return [
            'limit_value' => 'integer',
            'metadata' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Piano a cui appartiene il limite.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Contatori di utilizzo collegati a questo limite.
     */
    public function usageCounters(): HasMany
    {
        return $this->hasMany(UsageCounter::class);
    }
}