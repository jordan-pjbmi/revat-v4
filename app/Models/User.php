<?php

namespace App\Models;

use App\Concerns\HasTwoFactorAuthentication;
use App\Contracts\TwoFactorAuthenticatable;
use App\Services\WorkspaceContext;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, MustVerifyEmail, TwoFactorAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, HasTwoFactorAuthentication, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'deactivated_at',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_active_at' => 'datetime',
            'password' => 'hashed',
            'deactivated_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return Gate::allows('access-admin-tools');
    }

    // ── Relationships ──────────────────────────────────────────────────

    public function currentOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'current_organization_id');
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_user')
            ->withPivot('last_workspace_id')
            ->withTimestamps();
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_user')
            ->using(WorkspaceUser::class)
            ->withPivot('is_pinned')
            ->withTimestamps();
    }

    // ── Organization Switching ─────────────────────────────────────────

    public function switchOrganization(Organization $organization): void
    {
        if ($this->isBeingImpersonated()) {
            abort(403, 'Organization switching is not allowed during impersonation.');
        }

        $this->current_organization_id = $organization->id;
        $this->save();

        app(PermissionRegistrar::class)->setPermissionsTeamId($organization->id);
        $this->unsetRelation('roles');
        $this->unsetRelation('permissions');
    }

    // ── Deactivation ───────────────────────────────────────────────────

    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    public function scopeActive($query)
    {
        return $query->whereNull('deactivated_at');
    }

    public function scopeDeactivated($query)
    {
        return $query->whereNotNull('deactivated_at');
    }

    public function deactivate(): void
    {
        $this->deactivated_at = now();
        $this->save();
    }

    public function reactivate(): void
    {
        $this->deactivated_at = null;
        $this->save();
    }

    // ── Impersonation ───────────────────────────────────────────────────

    public function isBeingImpersonated(): bool
    {
        return $this->impersonating ?? false;
    }

    // ── Onboarding ─────────────────────────────────────────────────────

    public function isOnboarded(): bool
    {
        return $this->organizations()->exists();
    }

    // ── Workspace Resolution ───────────────────────────────────────────

    public function accessibleWorkspaceIds(?Organization $organization = null): Collection
    {
        $org = $organization ?? $this->currentOrganization;
        if (! $org) {
            return collect();
        }

        return app(WorkspaceContext::class)->accessibleWorkspaceIds($this, $org);
    }

    public function resolveWorkspace(?Organization $organization = null): ?Workspace
    {
        $org = $organization ?? $this->currentOrganization;
        if (! $org) {
            return null;
        }

        return app(WorkspaceContext::class)->resolveWorkspace($this, $org);
    }
}
