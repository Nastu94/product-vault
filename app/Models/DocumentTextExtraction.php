<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentTextExtraction extends Model
{
    /**
     * Campi assegnabili in massa.
     */
    protected $fillable = [
        'document_id',
        'engine',
        'status',
        'raw_text',
        'confidence_score',
        'metadata',
        'error_message',
        'started_at',
        'completed_at',
    ];

    /**
     * Conversione automatica dei tipi dato.
     */
    protected function casts(): array
    {
        return [
            'confidence_score' => 'integer',
            'metadata' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Documento collegato al tentativo di estrazione testo.
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}