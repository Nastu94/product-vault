<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

final class ProductCaseEvent extends Model
{
    /*
    |--------------------------------------------------------------------------
    | Tipi evento iniziali
    |--------------------------------------------------------------------------
    */

    public const TYPE_CASE_OPENED =
        'case_opened';

    public const TYPE_CASE_DETAILS_UPDATED =
        'case_details_updated';

    public const TYPE_STATUS_CHANGED =
        'status_changed';

    public const TYPE_DOCUMENT_SELECTED =
        'document_selected';

    public const TYPE_DOCUMENT_DESELECTED =
        'document_deselected';

    public const TYPE_PHOTO_ADDED =
        'photo_added';

    public const TYPE_PHOTO_REMOVED =
        'photo_removed';

    public const TYPE_REQUEST_DRAFT_GENERATED =
        'request_draft_generated';

    public const TYPE_REQUEST_DRAFT_REGENERATED =
        'request_draft_regenerated';

    public const TYPE_REQUEST_DRAFT_EDITED =
        'request_draft_edited';

    public const EVENT_TYPES = [
        self::TYPE_CASE_OPENED,
        self::TYPE_CASE_DETAILS_UPDATED,
        self::TYPE_STATUS_CHANGED,
        self::TYPE_DOCUMENT_SELECTED,
        self::TYPE_DOCUMENT_DESELECTED,
        self::TYPE_PHOTO_ADDED,
        self::TYPE_PHOTO_REMOVED,
        self::TYPE_REQUEST_DRAFT_GENERATED,
        self::TYPE_REQUEST_DRAFT_REGENERATED,
        self::TYPE_REQUEST_DRAFT_EDITED,
    ];

    /**
     * Gli eventi vengono creati soltanto dal recorder.
     *
     * Nessun campo è mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [
        '*',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' =>
                'datetime',

            'metadata' =>
                'array',
        ];
    }

    /**
     * Protezione append-only attraverso il model.
     */
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException(
                'Gli eventi della pratica non possono essere modificati.'
            );
        });

        static::deleting(function (): void {
            throw new RuntimeException(
                'Gli eventi della pratica non possono essere eliminati singolarmente.'
            );
        });
    }

    /**
     * Pratica a cui appartiene l'evento.
     */
    public function productCase(): BelongsTo
    {
        return $this->belongsTo(
            ProductCase::class
        );
    }

    /**
     * Utente che ha compiuto l'azione.
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'actor_user_id'
        );
    }
}