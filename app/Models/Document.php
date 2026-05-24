<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'document_type',
        'status',
        'original_filename',
        'mime_type',
        'file_size',
        'purchase_date',
        'total_amount',
        'currency',
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
     * Workspace/account proprietario del documento.
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
     * Collezioni media associate al documento.
     *
     * original_file: file originale caricato dall'utente.
     * processed_file: eventuale immagine/PDF preprocessato per OCR o preview.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('original_file')
            ->singleFile();

        $this->addMediaCollection('processed_file');
    }
}