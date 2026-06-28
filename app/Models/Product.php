<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    /**
     * Campi assegnabili in massa.
     */
    protected $fillable = [
        'team_id',
        'created_by_user_id',
        'category_id',
        'brand_id',
        'merchant_id',
        'identification_status_id',
        'currency_id',
        'name',
        'model',
        'serial_number',
        'ean_code',
        'purchase_date',
        'purchase_price',
        'reliability_score',
        'notes',
    ];

    /**
     * Conversione automatica dei tipi dato.
     */
    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'purchase_price' => 'decimal:2',
            'reliability_score' => 'integer',
        ];
    }

    /**
     * Workspace/team proprietario del prodotto.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Utente che ha creato la scheda prodotto.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Categoria del prodotto.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Brand del prodotto.
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Merchant presso cui il prodotto è stato acquistato.
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Stato di identificazione del prodotto.
     */
    public function identificationStatus(): BelongsTo
    {
        return $this->belongsTo(IdentificationStatus::class);
    }

    /**
     * Valuta del prezzo di acquisto.
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * Documenti collegati al prodotto.
     */
    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'product_documents')
            ->withPivot([
                'relationship_type_id',
                'linked_by_user_id',
                'notes',
            ])
            ->withTimestamps();
    }

    /**
     * Pratiche operative aperte per il prodotto.
     */
    public function cases(): HasMany
    {
        return $this->hasMany(ProductCase::class);
    }

    /**
     * Garanzie collegate al prodotto.
     *
     * Il model Warranty verrà creato in uno step successivo.
     */
    public function warranties(): HasMany
    {
        return $this->hasMany(Warranty::class);
    }

    /**
     * Scansioni barcode collegate al prodotto.
     *
     * Il model BarcodeScan verrà creato in uno step successivo.
     */
    public function barcodeScans(): HasMany
    {
        return $this->hasMany(BarcodeScan::class);
    }

    /**
     * Eventi lifecycle collegati al prodotto.
     *
     * Il model ProductEvent verrà creato in uno step successivo.
     */
    public function events(): HasMany
    {
        return $this->hasMany(ProductEvent::class);
    }
}