<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductUnderstandingFeedback extends Model
{
    /**
     * Nome tabella esplicito perché la tabella non usa il plurale standard.
     */
    protected $table = 'product_understanding_feedback';

    /**
     * Campi assegnabili in massa.
     */
    protected $fillable = [
        'team_id',
        'document_id',
        'document_line_id',
        'candidate_id',
        'product_id',
        'reviewed_by_user_id',
        'review_status',
        'ignored_reason',
        'ignored_note',
        'candidate_name',
        'candidate_model',
        'candidate_serial_number',
        'candidate_ean_code',
        'candidate_price',
        'final_product_name',
        'line_description',
        'normalized_line_description',
        'raw_text_hash',
        'analyzer_version',
        'analyzer_line_type',
        'analyzer_suggested_category',
        'registerable_score',
        'non_product_score',
        'signals',
        'negative_signals',
        'warnings',
        'score_breakdown',
        'metadata',
        'reviewed_at',
    ];

    /**
     * Cast automatici.
     */
    protected function casts(): array
    {
        return [
            'candidate_price' => 'decimal:2',
            'registerable_score' => 'integer',
            'non_product_score' => 'integer',
            'signals' => 'array',
            'negative_signals' => 'array',
            'warnings' => 'array',
            'score_breakdown' => 'array',
            'metadata' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function documentLine(): BelongsTo
    {
        return $this->belongsTo(DocumentLine::class);
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(ProductIdentificationCandidate::class, 'candidate_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}