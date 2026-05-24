<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarrantyRule extends Model
{
    /**
     * Campi assegnabili in massa.
     */
    protected $fillable = [
        'team_id',
        'country_id',
        'category_id',
        'warranty_type_id',
        'duration_months',
        'rule_type',
        'source_note',
        'priority',
        'is_active',
    ];

    /**
     * Conversione automatica dei tipi dato.
     */
    protected function casts(): array
    {
        return [
            'duration_months' => 'integer',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Workspace/team proprietario della regola, se personalizzata.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Paese a cui si applica la regola.
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * Categoria a cui si applica la regola.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Tipo di garanzia a cui si applica la regola.
     */
    public function warrantyType(): BelongsTo
    {
        return $this->belongsTo(WarrantyType::class);
    }
}