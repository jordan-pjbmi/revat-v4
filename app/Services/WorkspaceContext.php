<?php

namespace App\Services;

use App\Events\WorkspaceSwitched;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Session;

class WorkspaceContext
{
    /**
     * Cache of accessible workspace IDs per user+org pair, keyed by "{user_id}:{org_id}".
     */
    protected array $accessibleCache = [];

    /**
     * Cache of resolved workspaces per user+org pair.
     */
    protected array $workspaceCache = [];

    /**
     * Resolve the best workspace for a user within an organization.
     *
     * Resolution chain:
     * 1. last_workspace_id from organization_user pivot (if valid)
     * 2. Organization's default workspace (if user has access)
     * 3. null
     */
    public function resolveWorkspace(User $user, Organization $organization): ?Workspace
    {
        $accessibleIds = $this->accessibleWorkspaceIds($user, $organization);

        if ($accessibleIds->isEmpty()) {
            return null;
        }

        // Try pivot's last_workspace_id
        $pivot = $user->organizations()
            ->where('organizations.id', $organization->id)
            ->first()
            ?->pivot;

        if ($pivot?->last_workspace_id && $accessibleIds->contains($pivot->last_workspace_id)) {
            $workspace = Workspace::find($pivot->last_workspace_id);
            if ($workspace) {
                return $workspace;
            }
        }

        // Fall back to default workspace if user has access
        $defaultWorkspace = $organization->defaultWorkspace;
        if ($defaultWorkspace && $accessibleIds->contains($defaultWorkspace->id)) {
            return $defaultWorkspace;
        }

        // No valid workspace found
        return null;
    }

    /**
     * Set the active workspace for the current session.
     */
    public function setWorkspace(Workspace $workspace): void
    {
        $user = auth()->user();
        if (! $user) {
            return;
        }

        $organization = $user->currentOrganization;
        if (! $organization) {
            return;
        }

        $accessibleIds = $this->accessibleWorkspaceIds($user, $organization);
        if (! $accessibleIds->contains($workspace->id)) {
            return;
        }

        $sessionKey = $this->sessionKey($user->id, $organization->id);
        $previousWorkspaceId = Session::get($sessionKey);

        // Store in session
        Session::put($sessionKey, $workspace->id);

        // Update pivot
        $user->organizations()->updateExistingPivot($organization->id, [
            'last_workspace_id' => $workspace->id,
        ]);

        // Cache the workspace
        $this->workspaceCache[$this->cacheKey($user->id, $organization->id)] = $workspace;

        // Dispatch event only if changed
        if ($previousWorkspaceId !== $workspace->id) {
            event(new WorkspaceSwitched(
                user_id: $user->id,
                from_workspace_id: $previousWorkspaceId,
                to_workspace_id: $workspace->id,
                ip_address: request()?->ip(),
                occurred_at: now(),
            ));
        }
    }

    /**
     * Get the active workspace from session cache.
     */
    public function getWorkspace(): ?Workspace
    {
        $user = auth()->user();
        if (! $user) {
            return null;
        }

        $organization = $user->currentOrganization;
        if (! $organization) {
            return null;
        }

        $cacheKey = $this->cacheKey($user->id, $organization->id);

        if (isset($this->workspaceCache[$cacheKey])) {
            return $this->workspaceCache[$cacheKey];
        }

        $sessionKey = $this->sessionKey($user->id, $organization->id);
        $workspaceId = Session::get($sessionKey);

        if (! $workspaceId) {
            return null;
        }

        $workspace = Workspace::find($workspaceId);
        if ($workspace) {
            $this->workspaceCache[$cacheKey] = $workspace;
        }

        return $workspace;
    }

    /**
     * Clear workspace from session only (preserves DB pivot).
     */
    public function clearWorkspace(): void
    {
        $user = auth()->user();
        if (! $user) {
            return;
        }

        $organization = $user->currentOrganization;
        if (! $organization) {
            return;
        }

        $sessionKey = $this->sessionKey($user->id, $organization->id);
        Session::forget($sessionKey);

        $cacheKey = $this->cacheKey($user->id, $organization->id);
        unset($this->workspaceCache[$cacheKey]);
    }

    /**
     * Reset internal caches (for Octane/queue worker compatibility).
     */
    public function reset(): void
    {
        $this->accessibleCache = [];
        $this->workspaceCache = [];
    }

    /**
     * Get workspace IDs accessible to a user within an organization.
     * Results are cached for request duration.
     */
    public function accessibleWorkspaceIds(User $user, Organization $organization): Collection
    {
        $cacheKey = $this->cacheKey($user->id, $organization->id);

        if (isset($this->accessibleCache[$cacheKey])) {
            return $this->accessibleCache[$cacheKey];
        }

        // Owner/admin get implicit access to all org workspaces
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($organization->id);
        $user->unsetRelation('roles');

        if ($user->hasRole(['owner', 'admin'])) {
            $ids = $organization->workspaces()->pluck('id');
            $this->accessibleCache[$cacheKey] = $ids;

            return $ids;
        }

        $ids = $user->workspaces()
            ->where('workspaces.organization_id', $organization->id)
            ->pluck('workspaces.id');

        $this->accessibleCache[$cacheKey] = $ids;

        return $ids;
    }

    protected function sessionKey(int $userId, int $orgId): string
    {
        return "workspace:{$userId}:{$orgId}";
    }

    protected function cacheKey(int $userId, int $orgId): string
    {
        return "{$userId}:{$orgId}";
    }
}
