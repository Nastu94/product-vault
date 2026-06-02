<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductUnderstandingGlobalFact extends Model
{
    /**
     * Campi assegnabili in massa.
     */
    protected $fillable = [
        'fact_type',
        'fact_key',
        'fact_value',
        'canonical_name',
        'suggested_category',
        'suggested_line_type',
        'seen_count',
        'confirmed_count',
        'ignored_count',
        'global_registration_rate',
        'global_product_confidence_score',
        'canonical_name_counts',
        'category_counts',
        'line_type_counts',
        'metadata',
        'first_seen_at',
        'last_seen_at',
    ];

    /**
     * Cast automatici.
     */
    protected function casts(): array
    {
        return [
            'seen_count' => 'integer',
            'confirmed_count' => 'integer',
            'ignored_count' => 'integer',
            'global_registration_rate' => 'decimal:2',
            'global_product_confidence_score' => 'integer',
            'canonical_name_counts' => 'array',
            'category_counts' => 'array',
            'line_type_counts' => 'array',
            'metadata' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}