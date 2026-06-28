<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductCase extends Model
{
    use SoftDeletes;

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
     * Proprietà, descrizione originale, stato, esito, date operative
     * e metadata vengono gestiti esplicitamente dai service di dominio.
     */
    protected $fillable = [
        'title',
        'description',
        'occurred_on',
        'usability_status',
        'accidental_damage_declared',
        'accidental_damage_notes',
        'request_draft',
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
     * Utente che ha aperto la pratica.
     */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }
}