<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttributionConnector extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'name',
        'type',
        'campaign_integration_id',
        'campaign_data_type',
        'conversion_integration_id',
        'conversion_data_type',
        'field_mappings',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'field_mappings' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Validate field_mappings structure on set.
     */
    public function setFieldMappingsAttribute(mixed $value): void
    {
        $mappings = is_string($value) ? json_decode($value, true) : $value;

        if (is_array($mappings)) {
            $type = $this->attributes['type'] ?? $this->type ?? 'mapped';

            if ($type === 'simple') {
                if (! isset($mappings['effort_code_field'])) {
                    throw new \InvalidArgumentException(
                        'Simple connectors require an "effort_code_field" key in field_mappings.'
                    );
                }
            } else {
                foreach ($mappings as $key => $mapping) {
                    if ($key === 'effort_code_field' || $key === 'effort_code_source') {
                        continue;
                    }
                    if (! isset($mapping['campaign'], $mapping['conversion'])) {
                        throw new \InvalidArgumentException(
                            'field_mappings must follow {"campaign": "field_name", "conversion": "field_name"} format.'
                        );
                    }
                }
            }
        }

        $this->attributes['field_mappings'] = is_string($value) ? $value : json_encode($value);
    }

    // ── Relationships ────────────────────────────────────────────────

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function attributionKeys(): HasMany
    {
        return $this->hasMany(AttributionKey::class, 'connector_id');
    }

    public function attributionRecordKeys(): HasMany
    {
        return $this->hasMany(AttributionRecordKey::class, 'connector_id');
    }

    public function attributionResults(): HasMany
    {
        return $this->hasMany(AttributionResult::class, 'connector_id');
    }

    // ── Scopes ───────────────────────────────────────────────────────

    public function scopeActive($query): void
    {
        $query->where('is_active', true);
    }

    public function scopeForWorkspace($query, int $workspaceId)
    {
        return $query->where('workspace_id', $workspaceId);
    }
}
