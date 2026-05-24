<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class DataDeletionRequest extends Model
{
    /**
     * Campi assegnabili in massa.
     */
    protected $fillable = [
        'team_id',
        'requested_by_user_id',
        'request_type',
        'status',
        'deletable_type',
        'deletable_id',
        'reason',
        'internal_notes',
        'metadata',
        'requested_at',
        'scheduled_for',
        'completed_at',
    ];

    /**
     * Conversione automatica dei tipi dato.
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'requested_at' => 'datetime',
            'scheduled_for' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Team/workspace collegato alla richiesta.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Utente che ha richiesto la cancellazione/esportazione.
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /**
     * Risorsa specifica collegata alla richiesta.
     *
     * Può essere Document, Product, Team o altri model futuri.
     */
    public function deletable(): MorphTo
    {
        return $this->morphTo();
    }
}