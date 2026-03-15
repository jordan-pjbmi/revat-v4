<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

class Integration extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'platform',
        'data_types',
        'is_active',
        'sync_interval_minutes',
        'settings',
    ];

    protected $casts = [
        'data_types' => 'array',
        'credentials' => 'encrypted:array',
        'is_active' => 'boolean',
        'sync_statuses' => 'array',
        'sync_in_progress' => 'boolean',
        'last_synced_at' => 'datetime',
        'settings' => 'array',
    ];

    protected $hidden = [
        'credentials',
        'deleted_at',
    ];

    // ── Boot ────────────────────────────────────────────────────────────

    protected static function booted(): void
    {
        static::creating(function (Integration $integration) {
            if (! $integration->organization_id && $integration->workspace_id) {
                $workspace = Workspace::find($integration->workspace_id);
                if ($workspace) {
                    $integration->organization_id = $workspace->organization_id;
                }
            }
        });
    }

    // ── Relationships ───────────────────────────────────────────────────

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function extractionBatches(): HasMany
    {
        return $this->hasMany(ExtractionBatch::class);
    }

    public function campaignEmailRawData(): HasMany
    {
        return $this->hasMany(CampaignEmailRawData::class);
    }

    public function campaignEmailClickRawData(): HasMany
    {
        return $this->hasMany(CampaignEmailClickRawData::class);
    }

    public function conversionSaleRawData(): HasMany
    {
        return $this->hasMany(ConversionSaleRawData::class);
    }

    // ── Scopes ──────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForPlatform($query, string $platform)
    {
        return $query->where('platform', $platform);
    }

    public function scopeForWorkspace($query, int $workspaceId)
    {
        return $query->where('workspace_id', $workspaceId);
    }

    public function scopeDueForSync($query)
    {
        return $query->where('is_active', true)
            ->where('sync_in_progress', false)
            ->where(function ($q) {
                $q->whereNull('last_synced_at')
                    ->orWhereRaw('last_synced_at <= NOW() - INTERVAL sync_interval_minutes MINUTE');
            });
    }

    // ── Credentials ─────────────────────────────────────────────────────

    /**
     * Set credentials with validation against platform's defined fields.
     */
    public function setCredentials(array $credentials): void
    {
        $platformConfig = config("integrations.platforms.{$this->platform}");

        if ($platformConfig) {
            $requiredFields = $platformConfig['credential_fields'] ?? [];
            $providedFields = array_keys($credentials);
            $missing = array_diff($requiredFields, $providedFields);

            if (! empty($missing)) {
                throw new InvalidArgumentException(
                    "Missing required credential fields for {$this->platform}: ".implode(', ', $missing)
                );
            }
        }

        $this->credentials = $credentials;
        $this->save();
    }

    // ── Sync Status Helpers ─────────────────────────────────────────────

    public function isDueForSync(): bool
    {
        if (! $this->is_active || $this->sync_in_progress) {
            return false;
        }

        if ($this->last_synced_at === null) {
            return true;
        }

        return $this->last_synced_at->addMinutes($this->sync_interval_minutes)->isPast();
    }

    public function isSyncStale(): bool
    {
        if (! $this->sync_in_progress) {
            return false;
        }

        // Phase-aware stale thresholds
        $threshold = match ($this->last_sync_status) {
            'transforming' => 20,
            'attributing' => 25,
            default => 15,
        };

        return $this->updated_at->diffInMinutes(now()) > $threshold;
    }

    public function markSyncStarted(): void
    {
        $this->sync_in_progress = true;
        $this->save();
    }

    public function markSyncPhase(string $phase): void
    {
        $this->last_sync_status = $phase;
        $this->save();
    }

    public function markDataTypeStatus(string $dataType, string $status): void
    {
        $statuses = $this->sync_statuses ?? [];
        $statuses[$dataType] = $status;
        $this->sync_statuses = $statuses;
        $this->save();
    }

    public function markDataTypeCompleted(string $dataType): void
    {
        $this->markDataTypeStatus($dataType, 'completed');
    }

    public function markDataTypeFailed(string $dataType, string $error): void
    {
        $statuses = $this->sync_statuses ?? [];
        $statuses[$dataType] = 'failed';
        $this->sync_statuses = $statuses;
        $this->last_sync_error = $error;
        $this->save();
    }

    public function markSyncCompleted(): void
    {
        $this->sync_in_progress = false;
        $this->last_synced_at = now();
        $this->last_sync_status = 'completed';
        $this->last_sync_error = null;
        $this->save();
    }

    public function markSyncFailed(string $error): void
    {
        $this->sync_in_progress = false;
        $this->last_sync_status = 'failed';
        $this->last_sync_error = $error;
        $this->save();
    }
}
