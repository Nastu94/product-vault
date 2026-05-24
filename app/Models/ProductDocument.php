<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductDocument extends Model
{
    /**
     * Campi assegnabili in massa.
     */
    protected $fillable = [
        'product_id',
        'document_id',
        'relationship_type_id',
        'linked_by_user_id',
        'notes',
    ];

    /**
     * Prodotto collegato.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Documento collegato.
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Tipo di relazione prodotto-documento.
     */
    public function relationshipType(): BelongsTo
    {
        return $this->belongsTo(DocumentRelationshipType::class, 'relationship_type_id');
    }

    /**
     * Utente che ha creato il collegamento.
     */
    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by_user_id');
    }
}