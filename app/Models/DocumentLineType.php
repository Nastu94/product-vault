<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentLineType extends Model
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
     * Righe documento associate a questo tipo.
     *
     * Il model DocumentLine verrà creato in uno step successivo.
     */
    public function documentLines(): HasMany
    {
        return $this->hasMany(DocumentLine::class);
    }
}