<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Document extends Model implements HasMedia
{
    use InteractsWithMedia;
    use SoftDeletes;

    /**
     * Campi assegnabili in massa.
     *
     * Il documento appartiene sempre a un team Jetstream, che nel progetto
     * rappresenta il workspace/account attivo.
     */
    protected $fillable = [
        'team_id',
        'uploaded_by_user_id',
        'document_type_id',
        'merchant_id',
        'status',
        'text_extraction_status',
        'original_filename',
        'mime_type',
        'file_size',
        'purchase_date',
        'total_amount',
        'currency_id',
        'document_confidence_score',
        'product_reliability_score',
        'raw_text',
    ];

    /**
     * Conversione automatica dei tipi dato.
     */
    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'total_amount' => 'decimal:2',
            'document_confidence_score' => 'integer',
            'product_reliability_score' => 'integer',
        ];
    }

    /**
     * Workspace/team proprietario del documento.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Utente che ha caricato il documento.
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /**
     * Tipo documento normalizzato.
     *
     * Esempi: receipt, invoice, manual, warranty_certificate, unknown.
     */
    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    /**
     * Merchant/venditore riconosciuto nel documento.
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Valuta normalizzata del documento.
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * Prodotti collegati al documento.
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_documents')
            ->withPivot([
                'relationship_type_id',
                'linked_by_user_id',
                'notes',
            ])
            ->withTimestamps();
    }

    /**
     * Pratiche nelle quali il documento è stato selezionato.
     */
    public function productCases(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductCase::class,
            'product_case_documents'
        )
            ->withPivot([
                'selected_by_user_id',
                'notes',
            ])
            ->withTimestamps();
    }

    /**
     * Tentativi di estrazione testo associati al documento.
     */
    public function textExtractions(): HasMany
    {
        return $this->hasMany(DocumentTextExtraction::class);
    }

    /**
     * Ultimo tentativo di estrazione testo associato al documento.
     */
    public function latestTextExtraction()
    {
        return $this->hasOne(DocumentTextExtraction::class)->latestOfMany();
    }

    /**
     * Tentativi di classificazione associati al documento.
     */
    public function classifications(): HasMany
    {
        return $this->hasMany(DocumentClassification::class);
    }

    /**
     * Classificazione selezionata per il documento.
     */
    public function selectedClassification()
    {
        return $this->hasOne(DocumentClassification::class)
            ->where('is_selected', true);
    }

    /**
     * Righe estratte dal documento.
     */
    public function lines(): HasMany
    {
        return $this->hasMany(DocumentLine::class);
    }

    /**
     * Candidati prodotto generati dal documento.
     */
    public function productIdentificationCandidates(): HasMany
    {
        return $this->hasMany(ProductIdentificationCandidate::class);
    }

    /**
     * Garanzie ricavate da questo documento.
     */
    public function sourcedWarranties(): HasMany
    {
        return $this->hasMany(Warranty::class, 'source_document_id');
    }

    /**
     * Barcode ricavati o associati a questo documento.
     */
    public function barcodeScans(): HasMany
    {
        return $this->hasMany(BarcodeScan::class);
    }

    /**
     * Eventi prodotto generati o collegati a questo documento.
     */
    public function productEvents(): HasMany
    {
        return $this->hasMany(ProductEvent::class);
    }

    /**
     * Tentativi e step di processing associati al documento.
     */
    public function processingAttempts(): HasMany
    {
        return $this->hasMany(DocumentProcessingAttempt::class);
    }

    /**
     * Registra le collection Media Library del documento.
     *
     * I documenti caricati dagli utenti sono sensibili:
     * non devono essere salvati sul disco public.
     */
    public function registerMediaCollections(): void
    {
        $this
            ->addMediaCollection('original_file')
            ->useDisk('local')
            ->singleFile();

        $this
            ->addMediaCollection('processed_file')
            ->useDisk('local');
    }
}