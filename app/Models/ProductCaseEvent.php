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

    public const TYPE_STATUS_CHANGED =
        'status_changed';

    public const EVENT_TYPES = [
        self::TYPE_CASE_OPENED,
        self::TYPE_STATUS_CHANGED,
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