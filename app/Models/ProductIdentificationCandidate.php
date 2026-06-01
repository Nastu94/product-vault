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
        'review_status',
        'ignored_reason',
        'ignored_note',
        'reviewed_by_user_id',
        'reviewed_at',
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
            'reviewed_at' => 'datetime',
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

    /**
     * Utente che ha revisionato il candidato.
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /**
     * Il candidato è ancora da gestire.
     */
    public function isPendingReview(): bool
    {
        return $this->review_status === 'pending' && $this->product_id === null;
    }

    /**
     * Il candidato è stato confermato e trasformato in prodotto.
     */
    public function isConfirmed(): bool
    {
        return $this->review_status === 'confirmed' || $this->product_id !== null;
    }

    /**
     * Il candidato è stato escluso manualmente.
     */
    public function isIgnored(): bool
    {
        return $this->review_status === 'ignored';
    }
}