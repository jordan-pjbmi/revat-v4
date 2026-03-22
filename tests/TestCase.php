<?php

namespace Tests;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\Models\Role;

abstract class TestCase extends BaseTestCase
{
    /**
     * Create an authenticated user with organization and workspace context.
     *
     * @return array{user: User, organization: Organization, workspace: Workspace}
     */
    protected function createAuthenticatedUser(string $role = 'owner'): array
    {
        $this->seedRolesIfNeeded();

        $org = Organization::create(['name' => 'Test Organization']);

        $workspace = new Workspace(['name' => 'Default']);
        $workspace->organization_id = $org->id;
        $workspace->is_default = true;
        $workspace->save();

        $user = User::factory()->create([
            'current_organization_id' => $org->id,
        ]);

        $org->users()->attach($user);
        $workspace->users()->attach($user);

        setPermissionsTeamId($org->id);
        $user->assignRole(Role::findByName($role, 'web'));

        $this->actingAs($user);

        $organization = $org;

        return compact('user', 'organization', 'workspace');
    }

    /**
     * Set the Spatie team context for the given organization.
     */
    protected function setTeamContext(Organization $organization): void
    {
        setPermissionsTeamId($organization->id);
    }

    /**
     * Seed roles and permissions if they have not been seeded yet.
     */
    protected function seedRolesIfNeeded(): void
    {
        if (Role::where('name', 'owner')->exists()) {
            return;
        }

        $this->seed(RolesAndPermissionsSeeder::class);
    }
}
