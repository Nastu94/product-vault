<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class ProductCase extends Model implements HasMedia
{
    use InteractsWithMedia;
    use SoftDeletes;

    /**
     * Collezione di media per le foto delle evidenze della pratica.
     */
    public const MEDIA_COLLECTION_ISSUE_PHOTOS =
    'issue_photos';

    public const ISSUE_PHOTO_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /*
    |--------------------------------------------------------------------------
    | Provenance della bozza di richiesta
    |--------------------------------------------------------------------------
    */

    public const REQUEST_DRAFT_CURRENT_METADATA_KEY =
        'request_draft_current';

    public const REQUEST_DRAFT_CURRENT_METADATA_VERSION =
        'product_case_request_draft_current_v1';

    public const REQUEST_DRAFT_SOURCE_GENERATED =
        'generated';

    public const REQUEST_DRAFT_SOURCE_MANUAL =
        'manual';

    /*
    |--------------------------------------------------------------------------
    | Stati della pratica
    |--------------------------------------------------------------------------
    */

    public const STATUS_DRAFT = 'draft';

    public const STATUS_READY_TO_CONTACT = 'ready_to_contact';

    public const STATUS_CONTACTED = 'contacted';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_READY_TO_CONTACT,
        self::STATUS_CONTACTED,
        self::STATUS_RESOLVED,
        self::STATUS_CLOSED,
        self::STATUS_CANCELLED,
    ];

    /*
    |--------------------------------------------------------------------------
    | Utilizzabilità del prodotto
    |--------------------------------------------------------------------------
    */

    public const USABILITY_USABLE = 'usable';

    public const USABILITY_PARTIALLY_USABLE = 'partially_usable';

    public const USABILITY_UNUSABLE = 'unusable';

    public const USABILITY_UNKNOWN = 'unknown';

    public const USABILITY_STATUSES = [
        self::USABILITY_USABLE,
        self::USABILITY_PARTIALLY_USABLE,
        self::USABILITY_UNUSABLE,
        self::USABILITY_UNKNOWN,
    ];

    /*
    |--------------------------------------------------------------------------
    | Esiti della pratica
    |--------------------------------------------------------------------------
    */

    public const OUTCOME_REPAIRED = 'repaired';

    public const OUTCOME_REPLACED = 'replaced';

    public const OUTCOME_REFUNDED = 'refunded';

    public const OUTCOME_REJECTED = 'rejected';

    public const OUTCOME_ABANDONED = 'abandoned';

    public const OUTCOME_OTHER = 'other';

    public const OUTCOMES = [
        self::OUTCOME_REPAIRED,
        self::OUTCOME_REPLACED,
        self::OUTCOME_REFUNDED,
        self::OUTCOME_REJECTED,
        self::OUTCOME_ABANDONED,
        self::OUTCOME_OTHER,
    ];

    /**
     * Campi modificabili come contenuto ordinario della pratica.
     *
     * Proprietà, descrizione originale, stato, bozza di richiesta,
     * esito, date operative e metadata vengono gestiti esplicitamente
     * dai service di dominio.
     */
    protected $fillable = [
        'title',
        'description',
        'occurred_on',
        'usability_status',
        'accidental_damage_declared',
        'accidental_damage_notes',
    ];

    /**
     * Conversione automatica dei tipi dato.
     */
    protected function casts(): array
    {
        return [
            'occurred_on' => 'date',
            'accidental_damage_declared' => 'boolean',
            'request_draft_generated_at' => 'datetime',
            'opened_at' => 'datetime',
            'contacted_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * Registra le fotografie private associate alla pratica.
     */
    public function registerMediaCollections(): void
    {
        $this
            ->addMediaCollection(
                self::MEDIA_COLLECTION_ISSUE_PHOTOS
            )
            ->useDisk('local')
            ->acceptsMimeTypes(
                self::ISSUE_PHOTO_MIME_TYPES
            );
    }

    /**
     * Workspace/team proprietario della pratica.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Prodotto interessato dalla pratica.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Documenti selezionati come evidenze della pratica.
     */
    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(
            Document::class,
            'product_case_documents'
        )
            ->withPivot([
                'selected_by_user_id',
                'notes',
            ])
            ->withTimestamps();
    }

    /**
     * Timeline operativa append-only della pratica.
     */
    public function events(): HasMany
    {
        return $this
            ->hasMany(
                ProductCaseEvent::class
            )
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    /**
     * Utente che ha aperto la pratica.
     */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }
}