<?php

// tests/Feature/Navigation/SidebarWorkspaceLinkTest.php

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->org = Organization::create(['name' => 'Test Org']);
    $this->workspace = new Workspace(['name' => 'Default']);
    $this->workspace->organization_id = $this->org->id;
    $this->workspace->is_default = true;
    $this->workspace->save();
});

it('shows workspaces sidebar link for owner', function () {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $owner->organizations()->attach($this->org->id);
    $owner->current_organization_id = $this->org->id;
    $owner->save();
    $this->workspace->users()->attach($owner->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $owner->assignRole('owner');

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-testid="nav-workspaces"', false);
});

it('hides workspaces sidebar link for editor', function () {
    $editor = User::factory()->create(['email_verified_at' => now()]);
    $editor->organizations()->attach($this->org->id);
    $editor->current_organization_id = $this->org->id;
    $editor->save();
    $this->workspace->users()->attach($editor->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $editor->assignRole('editor');

    $this->actingAs($editor)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('data-testid="nav-workspaces"', false);
});
