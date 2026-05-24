<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentLine extends Model
{
    /**
     * Campi assegnabili in massa.
     */
    protected $fillable = [
        'document_id',
        'document_line_type_id',
        'line_number',
        'raw_text',
        'description',
        'quantity',
        'unit_price',
        'total_price',
        'confidence_score',
        'metadata',
    ];

    /**
     * Conversione automatica dei tipi dato.
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'total_price' => 'decimal:2',
            'confidence_score' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * Documento da cui è stata estratta la riga.
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Tipo di riga estratta.
     */
    public function documentLineType(): BelongsTo
    {
        return $this->belongsTo(DocumentLineType::class);
    }

    /**
     * Candidati prodotto generati da questa riga.
     */
    public function productIdentificationCandidates(): HasMany
    {
        return $this->hasMany(ProductIdentificationCandidate::class);
    }

    /**
     * Barcode associati a questa riga documento.
     */
    public function barcodeScans(): HasMany
    {
        return $this->hasMany(BarcodeScan::class);
    }
}