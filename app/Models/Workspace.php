<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

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
            ->using(WorkspaceUser::class)
            ->withPivot('is_pinned')
            ->withTimestamps();
    }

    public function integrations(): HasMany
    {
        return $this->hasMany(Integration::class);
    }

    public function dashboards(): HasMany
    {
        return $this->hasMany(Dashboard::class)->where('is_template', false);
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
     *
     * Explicit members are in the workspace_user pivot. Implicit members are
     * org-level owner/admin users not already in the pivot — resolved via a
     * direct join on model_has_roles to avoid N+1 queries.
     */
    public function totalMemberCount(): int
    {
        $explicitCount = $this->users()->count();

        $org = $this->organization;
        $explicitUserIds = $this->users()->pluck('users.id');

        // Count org members with owner/admin roles scoped to this org (team_id)
        // who are not already in the explicit pivot. Roles themselves have no
        // team_id — the team scope lives on model_has_roles.team_id.
        $adminRoleIds = Role::whereIn('name', ['owner', 'admin'])
            ->pluck('id');

        $implicitCount = $org->users()
            ->whereNotIn('users.id', $explicitUserIds)
            ->whereExists(function ($query) use ($adminRoleIds, $org) {
                $query->select(DB::raw(1))
                    ->from('model_has_roles')
                    ->whereColumn('model_has_roles.model_id', 'users.id')
                    ->where('model_has_roles.model_type', (new User)->getMorphClass())
                    ->where('model_has_roles.team_id', $org->id)
                    ->whereIn('model_has_roles.role_id', $adminRoleIds);
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
