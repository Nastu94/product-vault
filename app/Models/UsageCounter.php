<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageCounter extends Model
{
    /**
     * Campi assegnabili in massa.
     */
    protected $fillable = [
        'team_id',
        'user_id',
        'plan_limit_id',
        'counter_key',
        'used_value',
        'period_starts_at',
        'period_ends_at',
        'metadata',
    ];

    /**
     * Conversione automatica dei tipi dato.
     */
    protected function casts(): array
    {
        return [
            'used_value' => 'integer',
            'period_starts_at' => 'date',
            'period_ends_at' => 'date',
            'metadata' => 'array',
        ];
    }

    /**
     * Team/workspace a cui appartiene il contatore.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Utente a cui è eventualmente attribuito l'utilizzo.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Limite del piano collegato al contatore.
     */
    public function planLimit(): BelongsTo
    {
        return $this->belongsTo(PlanLimit::class);
    }
}