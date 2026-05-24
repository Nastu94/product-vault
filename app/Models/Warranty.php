<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Warranty extends Model
{
    /**
     * Campi assegnabili in massa.
     */
    protected $fillable = [
        'product_id',
        'warranty_type_id',
        'source_document_id',
        'starts_at',
        'ends_at',
        'duration_months',
        'source',
        'confidence_score',
        'notes',
        'metadata',
    ];

    /**
     * Conversione automatica dei tipi dato.
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'ends_at' => 'date',
            'duration_months' => 'integer',
            'confidence_score' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * Prodotto a cui appartiene la garanzia.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Tipo di garanzia.
     */
    public function warrantyType(): BelongsTo
    {
        return $this->belongsTo(WarrantyType::class);
    }

    /**
     * Documento da cui è stata ricavata la garanzia.
     */
    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'source_document_id');
    }
}