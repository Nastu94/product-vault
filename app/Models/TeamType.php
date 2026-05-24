<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeamType extends Model
{
    /**
     * Campi assegnabili in massa.
     */
    protected $fillable = [
        'code',
        'name',
        'description',
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
     * Workspace/team associati a questo tipo.
     */
    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }
}