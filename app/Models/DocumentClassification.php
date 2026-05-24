<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentClassification extends Model
{
    /**
     * Campi assegnabili in massa.
     */
    protected $fillable = [
        'document_id',
        'document_type_id',
        'classifier',
        'reason',
        'confidence_score',
        'is_selected',
        'metadata',
    ];

    /**
     * Conversione automatica dei tipi dato.
     */
    protected function casts(): array
    {
        return [
            'confidence_score' => 'integer',
            'is_selected' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /**
     * Documento collegato alla classificazione.
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Tipo documento proposto.
     */
    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }
}