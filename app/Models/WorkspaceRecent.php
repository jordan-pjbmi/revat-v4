<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceRecent extends Model
{
    public $timestamps = false;

    protected $table = 'workspace_recent';

    protected $fillable = ['user_id', 'organization_id', 'workspace_id', 'switched_at'];

    protected function casts(): array
    {
        return ['switched_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
