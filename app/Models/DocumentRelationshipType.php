<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentRelationshipType extends Model
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
     * Relazioni prodotto-documento associate a questo tipo.
     *
     * Il model ProductDocument verrà creato in uno step successivo.
     */
    public function productDocuments(): HasMany
    {
        return $this->hasMany(ProductDocument::class, 'relationship_type_id');
    }
}