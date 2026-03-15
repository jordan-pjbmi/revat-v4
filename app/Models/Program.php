<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class Program extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'workspace_id',
        'name',
        'code',
        'description',
        'status',
        'is_default',
        'start_date',
        'end_date',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function initiatives(): HasMany
    {
        return $this->hasMany(Initiative::class);
    }

    public function efforts(): HasManyThrough
    {
        return $this->hasManyThrough(Effort::class, Initiative::class);
    }

    // ── Scopes ───────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeForWorkspace(Builder $query, int|Workspace $workspace): Builder
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;

        return $query->where('workspace_id', $workspaceId);
    }

    // ── Boot ─────────────────────────────────────────────────────────

    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    // ── Boot ─────────────────────────────────────────────────────────

    protected static function booted(): void
    {
        static::deleting(function (Program $program) {
            if ($program->is_default) {
                return false;
            }

            if ($program->isForceDeleting()) {
                return;
            }

            $program->initiatives()->each(function (Initiative $initiative) {
                $initiative->delete();
            });
        });

        static::restoring(function (Program $program) {
            Initiative::withTrashed()
                ->where('program_id', $program->id)
                ->where('deleted_at', '>=', $program->deleted_at)
                ->each(function (Initiative $initiative) {
                    $initiative->restore();
                });
        });
    }
}
