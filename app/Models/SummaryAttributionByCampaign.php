<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SummaryAttributionByCampaign extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'summary_attribution_by_campaign';

    protected $primaryKey = ['workspace_id', 'campaign_type', 'campaign_id', 'summary_date', 'model'];

    protected $fillable = [
        'workspace_id',
        'campaign_type',
        'campaign_id',
        'summary_date',
        'model',
        'attributed_conversions',
        'attributed_revenue',
        'total_weight',
        'summarized_at',
    ];

    protected function casts(): array
    {
        return [
            'summary_date' => 'date',
            'attributed_revenue' => 'decimal:2',
            'total_weight' => 'decimal:4',
            'summarized_at' => 'datetime',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function campaign(): MorphTo
    {
        return $this->morphTo('campaign');
    }

    // ── Scopes ───────────────────────────────────────────────────────

    public function scopeForDateRange($query, Carbon $start, Carbon $end)
    {
        return $query->whereBetween('summary_date', [$start->toDateString(), $end->toDateString()]);
    }

    public function scopeForWorkspace($query, int $workspaceId)
    {
        return $query->where('workspace_id', $workspaceId);
    }

    public function scopeForModel($query, string $model)
    {
        return $query->where('model', $model);
    }

    public function scopeForCampaign($query, string $type, int $id)
    {
        return $query->where('campaign_type', $type)->where('campaign_id', $id);
    }
}
