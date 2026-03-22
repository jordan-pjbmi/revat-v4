<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Dashboard extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'created_by',
        'name',
        'description',
        'is_template',
        'template_slug',
        'is_locked',
    ];

    protected function casts(): array
    {
        return [
            'is_template' => 'boolean',
            'is_locked' => 'boolean',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function widgets(): HasMany
    {
        return $this->hasMany(DashboardWidget::class)->orderBy('sort_order');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(DashboardSnapshot::class)->orderByDesc('created_at');
    }

    public function exports(): HasMany
    {
        return $this->hasMany(DashboardExport::class);
    }

    public function scopeForWorkspace($query, int $workspaceId)
    {
        return $query->where('workspace_id', $workspaceId);
    }

    public function scopeTemplates($query)
    {
        return $query->where('is_template', true);
    }

    public function scopeNotTemplates($query)
    {
        return $query->where('is_template', false);
    }

    public function cloneToWorkspace(int $workspaceId, int $userId): self
    {
        return DB::transaction(function () use ($workspaceId, $userId) {
            $clone = $this->replicate(['is_template', 'template_slug', 'is_locked']);
            $clone->workspace_id = $workspaceId;
            $clone->created_by = $userId;
            $clone->is_template = false;
            $clone->template_slug = null;
            $clone->is_locked = false;
            $clone->save();

            foreach ($this->widgets as $widget) {
                $widgetClone = $widget->replicate();
                $widgetClone->dashboard_id = $clone->id;
                $widgetClone->save();
            }

            return $clone;
        });
    }
}
