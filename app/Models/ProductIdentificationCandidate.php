<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductIdentificationCandidate extends Model
{
    /**
     * Campi assegnabili in massa.
     */
    protected $fillable = [
        'document_id',
        'document_line_id',
        'product_id',
        'brand_id',
        'category_id',
        'name',
        'model',
        'serial_number',
        'ean_code',
        'price',
        'source',
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
            'price' => 'decimal:2',
            'confidence_score' => 'integer',
            'is_selected' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /**
     * Documento da cui nasce il candidato prodotto.
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Riga documento da cui è stato estratto il candidato.
     */
    public function documentLine(): BelongsTo
    {
        return $this->belongsTo(DocumentLine::class);
    }

    /**
     * Prodotto collegato dopo eventuale conferma.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Brand candidato.
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Categoria candidata.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}