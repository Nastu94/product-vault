<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductEventType extends Model
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
     * Eventi prodotto associati a questo tipo.
     *
     * Il model ProductEvent verrà creato in uno step successivo.
     */
    public function productEvents(): HasMany
    {
        return $this->hasMany(ProductEvent::class);
    }
}