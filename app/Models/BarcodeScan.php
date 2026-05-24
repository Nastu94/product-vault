<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BarcodeScan extends Model
{
    /**
     * Campi assegnabili in massa.
     */
    protected $fillable = [
        'product_id',
        'document_id',
        'document_line_id',
        'scanned_by_user_id',
        'barcode_type',
        'barcode_value',
        'source',
        'confidence_score',
        'is_confirmed',
        'metadata',
    ];

    /**
     * Conversione automatica dei tipi dato.
     */
    protected function casts(): array
    {
        return [
            'confidence_score' => 'integer',
            'is_confirmed' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /**
     * Prodotto collegato al barcode.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Documento da cui è stato ricavato il barcode.
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Riga documento collegata al barcode.
     */
    public function documentLine(): BelongsTo
    {
        return $this->belongsTo(DocumentLine::class);
    }

    /**
     * Utente che ha eseguito o confermato la scansione.
     */
    public function scannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by_user_id');
    }
}