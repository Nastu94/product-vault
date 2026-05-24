<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentProcessingAttempt extends Model
{
    /**
     * Campi assegnabili in massa.
     */
    protected $fillable = [
        'document_id',
        'step',
        'status',
        'handler',
        'attempt_number',
        'error_message',
        'exception_class',
        'metadata',
        'started_at',
        'completed_at',
    ];

    /**
     * Conversione automatica dei tipi dato.
     */
    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'metadata' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Documento collegato a questo tentativo di processing.
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}