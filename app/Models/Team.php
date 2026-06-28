<?php

namespace App\Models;

use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Jetstream\Events\TeamCreated;
use Laravel\Jetstream\Events\TeamDeleted;
use Laravel\Jetstream\Events\TeamUpdated;
use Laravel\Jetstream\Team as JetstreamTeam;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends JetstreamTeam
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'personal_team',
    ];

    /**
     * The event map for the model.
     *
     * @var array<string, class-string>
     */
    protected $dispatchesEvents = [
        'created' => TeamCreated::class,
        'updated' => TeamUpdated::class,
        'deleted' => TeamDeleted::class,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'personal_team' => 'boolean',
        ];
    }

    /**
     * Piano associato al workspace/team.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Documenti appartenenti al workspace/team.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * Prodotti appartenenti al workspace/team.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Pratiche prodotto appartenenti al workspace/team.
     */
    public function productCases(): HasMany
    {
        return $this->hasMany(ProductCase::class);
    }

    /**
     * Merchant privati o personalizzati del workspace/team.
     */
    public function merchants(): HasMany
    {
        return $this->hasMany(Merchant::class);
    }

    /**
     * Brand privati o personalizzati del workspace/team.
     */
    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class);
    }

    /**
     * Categorie private o personalizzate del workspace/team.
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /**
     * Contatori di utilizzo del workspace/team.
     */
    public function usageCounters(): HasMany
    {
        return $this->hasMany(UsageCounter::class);
    }

    /**
     * Preferenze di notifica collegate al workspace/team.
     */
    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    /**
     * Tipo del workspace/team.
     *
     * Esempi: personal, family, shop, business.
     */
    public function teamType(): BelongsTo
    {
        return $this->belongsTo(TeamType::class);
    }
}
