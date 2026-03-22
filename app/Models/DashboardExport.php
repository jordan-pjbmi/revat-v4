<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DashboardExport extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'dashboard_id',
        'created_by',
        'token',
        'layout',
        'name',
        'description',
        'widget_count',
        'expires_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'layout' => 'array',
            'widget_count' => 'integer',
            'expires_at' => 'datetime',
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

    public function scopeValid($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public static function generateToken(): string
    {
        return Str::random(64);
    }
}
