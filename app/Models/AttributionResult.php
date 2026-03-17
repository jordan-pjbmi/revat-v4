<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AttributionResult extends Model
{
    protected $fillable = [
        'workspace_id',
        'connector_id',
        'conversion_type',
        'conversion_id',
        'effort_id',
        'campaign_type',
        'campaign_id',
        'model',
        'weight',
        'matched_at',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:4',
            'matched_at' => 'datetime',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function connector(): BelongsTo
    {
        return $this->belongsTo(AttributionConnector::class, 'connector_id');
    }

    public function effort(): BelongsTo
    {
        return $this->belongsTo(Effort::class);
    }

    /**
     * Resolve the conversion polymorphically.
     */
    public function conversion(): MorphTo
    {
        return $this->morphTo('conversion');
    }

    /**
     * Resolve the campaign polymorphically.
     */
    public function campaign(): MorphTo
    {
        return $this->morphTo('campaign');
    }

    // ── Scopes ───────────────────────────────────────────────────────

    public function scopeForModel($query, string $model): void
    {
        $query->where('model', $model);
    }

    public function scopeForConversion($query, string $type, int $id): void
    {
        $query->where('conversion_type', $type)->where('conversion_id', $id);
    }

    public function scopeForCampaign($query, string $type, int $id): void
    {
        $query->where('campaign_type', $type)->where('campaign_id', $id);
    }
}
