<?php

namespace App\Services;

use App\Models\AlphaInvite;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class OrganizationSetupService
{
    /**
     * Set up a new organization for a user during onboarding.
     *
     * Creates the organization, a default workspace, attaches the user
     * to both via pivot tables, assigns the owner role (scoped to the org),
     * and sets current_organization_id on the user.
     *
     * @param  array{name: string, timezone: string, plan_slug?: string, workspace_name?: string}  $data
     */
    public function setup(User $user, array $data): Organization
    {
        return DB::transaction(function () use ($user, $data) {
            // Alpha users always get the alpha plan
            if (AlphaInvite::where('email', $user->email)->whereNotNull('registered_at')->exists()) {
                $data['plan_slug'] = 'alpha';
            }

            $orgData = [
                'name' => $data['name'],
                'timezone' => $data['timezone'] ?? 'UTC',
            ];

            if (! empty($data['plan_slug'])) {
                $plan = Plan::where('slug', $data['plan_slug'])->first();
                if ($plan) {
                    $orgData['plan_id'] = $plan->id;
                }
            }

            $organization = Organization::create($orgData);

            $workspaceName = $data['workspace_name'] ?? $organization->name.' Workspace';
            $workspace = new Workspace(['name' => $workspaceName]);
            $workspace->organization_id = $organization->id;
            $workspace->is_default = true;
            $workspace->save();

            // Attach user to organization
            $organization->users()->attach($user->id, [
                'last_workspace_id' => $workspace->id,
            ]);

            // Set Spatie team scope before role assignment
            app(PermissionRegistrar::class)->setPermissionsTeamId($organization->id);

            // Assign owner role (scoped to this organization via Spatie teams)
            $user->assignRole('owner');

            // Attach user to default workspace
            $workspace->users()->attach($user->id);

            // Set current organization on user
            $user->current_organization_id = $organization->id;
            $user->save();

            return $organization;
        });
    }
}
