<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Workspace extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'last_summarized_at'];

    protected $casts = [
        'is_default' => 'boolean',
        'last_summarized_at' => 'datetime',
    ];

    protected $hidden = ['deleted_at'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')
            ->withTimestamps();
    }

    public function integrations(): HasMany
    {
        return $this->hasMany(Integration::class);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function scopeForOrganization($query, Organization|int $org)
    {
        return $query->where('organization_id', $org instanceof Organization ? $org->id : $org);
    }

    /**
     * Count all users with access to this workspace (explicit + implicit).
     */
    public function totalMemberCount(): int
    {
        $explicitCount = $this->users()->count();

        $org = $this->organization;
        $explicitUserIds = $this->users()->pluck('users.id');

        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($org->id);
        $implicitCount = $org->users()
            ->whereNotIn('users.id', $explicitUserIds)
            ->get()
            ->filter(function ($user) {
                $user->unsetRelation('roles');
                return $user->hasRole(['owner', 'admin']);
            })
            ->count();

        return $explicitCount + $implicitCount;
    }

    public function setAsDefault(): void
    {
        DB::transaction(function () {
            // Clear is_default on sibling workspaces
            static::where('organization_id', $this->organization_id)
                ->where('id', '!=', $this->id)
                ->update(['is_default' => false]);

            $this->is_default = true;
            $this->save();
        });
    }
}
