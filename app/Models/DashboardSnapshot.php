<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DashboardSnapshot extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'dashboard_id',
        'created_by',
        'layout',
        'widget_count',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'layout' => 'array',
            'widget_count' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
