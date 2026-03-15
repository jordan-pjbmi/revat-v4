<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExtractionBatch extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_EXTRACTING = 'extracting';

    public const STATUS_EXTRACTED = 'extracted';

    public const STATUS_TRANSFORMING = 'transforming';

    public const STATUS_TRANSFORMED = 'transformed';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_TRANSFORM_FAILED = 'transform_failed';

    protected $fillable = [
        'integration_id',
        'workspace_id',
        'data_type',
        'status',
        'force_transform',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'records_count' => 0,
        'force_transform' => false,
    ];

    protected $casts = [
        'force_transform' => 'boolean',
        'started_at' => 'datetime',
        'extracted_at' => 'datetime',
        'transformed_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    // ── Relationships ───────────────────────────────────────────────────

    public function records(): HasMany
    {
        return $this->hasMany(ExtractionRecord::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    // ── Status Helpers ──────────────────────────────────────────────────

    public function markExtracting(): void
    {
        $this->status = self::STATUS_EXTRACTING;
        $this->started_at = now();
        $this->save();
    }

    public function markExtracted(): void
    {
        $this->status = self::STATUS_EXTRACTED;
        $this->extracted_at = now();
        $this->records_count = $this->records()->count();
        $this->save();
    }

    public function markTransforming(): void
    {
        $this->status = self::STATUS_TRANSFORMING;
        $this->save();
    }

    public function markTransformed(): void
    {
        $this->status = self::STATUS_TRANSFORMED;
        $this->transformed_at = now();
        $this->save();
    }

    public function markCompleted(): void
    {
        $this->status = self::STATUS_COMPLETED;
        $this->completed_at = now();
        $this->save();
    }

    public function markFailed(string $error): void
    {
        $this->status = self::STATUS_FAILED;
        $this->failed_at = now();
        $this->error = mb_substr($error, 0, 60000);
        $this->save();
    }

    public function markTransformFailed(string $error): void
    {
        $this->status = self::STATUS_TRANSFORM_FAILED;
        $this->failed_at = now();
        $this->error = mb_substr($error, 0, 60000);
        $this->save();
    }

    // ── Scopes ──────────────────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeFailed($query)
    {
        return $query->whereIn('status', [self::STATUS_FAILED, self::STATUS_TRANSFORM_FAILED]);
    }

    public function scopeForIntegration($query, int $integrationId)
    {
        return $query->where('integration_id', $integrationId);
    }
}
