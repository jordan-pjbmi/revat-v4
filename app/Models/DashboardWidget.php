<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DashboardWidget extends Model
{
    use HasFactory;

    protected $fillable = [
        'dashboard_id',
        'widget_type',
        'grid_x',
        'grid_y',
        'grid_w',
        'grid_h',
        'config',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'grid_x' => 'integer',
            'grid_y' => 'integer',
            'grid_w' => 'integer',
            'grid_h' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }
}
