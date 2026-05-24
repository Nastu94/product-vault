<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductEvent extends Model
{
    /**
     * Campi assegnabili in massa.
     */
    protected $fillable = [
        'product_id',
        'product_event_type_id',
        'document_id',
        'created_by_user_id',
        'title',
        'description',
        'event_date',
        'source',
        'confidence_score',
        'metadata',
    ];

    /**
     * Conversione automatica dei tipi dato.
     */
    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'confidence_score' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * Prodotto collegato all'evento.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Tipo evento collegato.
     */
    public function productEventType(): BelongsTo
    {
        return $this->belongsTo(ProductEventType::class);
    }

    /**
     * Documento collegato all'evento, se presente.
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Utente che ha creato o confermato l'evento.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}