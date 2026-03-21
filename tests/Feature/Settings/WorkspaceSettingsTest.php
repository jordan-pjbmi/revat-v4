<?php

use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Volt\Volt;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->workspace = new Workspace(['name' => 'Default']);
    $this->workspace->organization_id = $this->org->id;
    $this->workspace->is_default = true;
    $this->workspace->save();

    $this->owner = User::factory()->create(['email_verified_at' => now()]);
    $this->owner->organizations()->attach($this->org->id);
    $this->owner->current_organization_id = $this->org->id;
    $this->owner->save();
    $this->workspace->users()->attach($this->owner->id);

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $this->owner->assignRole('owner');
});

it('lists workspaces in the index', function () {
    $this->actingAs($this->owner)
        ->get(route('settings.workspaces'))
        ->assertOk()
        ->assertSee('Workspaces')
        ->assertSee('Default');
});

it('creates a new workspace', function () {
    // Give org a plan with 5 workspaces
    $plan = Plan::create([
        'name' => 'Starter', 'slug' => 'starter',
        'max_users' => 5, 'max_workspaces' => 5,
        'max_integrations_per_workspace' => 5,
        'is_visible' => true, 'sort_order' => 1,
    ]);
    $this->org->plan_id = $plan->id;
    $this->org->save();

    Volt::actingAs($this->owner)
        ->test('settings.workspaces.index')
        ->set('showCreateForm', true)
        ->set('newWorkspaceName', 'New WS')
        ->call('createWorkspace')
        ->assertHasNoErrors();

    expect($this->org->workspaces()->where('name', 'New WS')->exists())->toBeTrue();
});

it('renames a workspace', function () {
    Volt::actingAs($this->owner)
        ->test('settings.workspaces.index')
        ->call('startEditing', $this->workspace->id)
        ->set('editWorkspaceName', 'Renamed WS')
        ->call('saveEdit')
        ->assertHasNoErrors();

    $this->workspace->refresh();
    expect($this->workspace->name)->toBe('Renamed WS');
});

it('cannot delete default workspace', function () {
    Volt::actingAs($this->owner)
        ->test('settings.workspaces.index')
        ->call('confirmDelete', $this->workspace->id)
        ->call('deleteWorkspace')
        ->assertHasErrors('delete');

    expect(Workspace::find($this->workspace->id))->not->toBeNull();
});

it('deletes a non-default workspace', function () {
    $ws = new Workspace(['name' => 'Deletable']);
    $ws->organization_id = $this->org->id;
    $ws->is_default = false;
    $ws->save();

    Volt::actingAs($this->owner)
        ->test('settings.workspaces.index')
        ->call('confirmDelete', $ws->id)
        ->call('deleteWorkspace')
        ->assertHasNoErrors();

    expect(Workspace::withTrashed()->find($ws->id)->trashed())->toBeTrue();
});

it('enforces plan workspace limit', function () {
    // Free plan allows 1 workspace (default)
    Volt::actingAs($this->owner)
        ->test('settings.workspaces.index')
        ->set('showCreateForm', true)
        ->set('newWorkspaceName', 'Over Limit')
        ->call('createWorkspace')
        ->assertHasErrors('newWorkspaceName');
});

it('adds user to workspace', function () {
    $member = User::factory()->create(['email_verified_at' => now()]);
    $member->organizations()->attach($this->org->id);
    $member->current_organization_id = $this->org->id;
    $member->save();
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $member->assignRole('viewer');

    Volt::actingAs($this->owner)
        ->test('settings.workspaces.users', ['workspace' => $this->workspace])
        ->set('addUserIds', [$member->id])
        ->call('addUsers')
        ->assertHasNoErrors();

    expect($this->workspace->users()->where('users.id', $member->id)->exists())->toBeTrue();
});

it('removes user from workspace', function () {
    $member = User::factory()->create(['email_verified_at' => now()]);
    $member->organizations()->attach($this->org->id);
    $member->current_organization_id = $this->org->id;
    $member->save();
    $this->workspace->users()->attach($member->id);

    Volt::actingAs($this->owner)
        ->test('settings.workspaces.users', ['workspace' => $this->workspace])
        ->call('removeUser', $member->id);

    expect($this->workspace->users()->where('users.id', $member->id)->exists())->toBeFalse();
});

it('prevents adding user from different org to workspace', function () {
    $outsider = User::factory()->create(['email_verified_at' => now()]);
    $otherOrg = Organization::create(['name' => 'Other Org']);
    $outsider->organizations()->attach($otherOrg->id);
    $outsider->current_organization_id = $otherOrg->id;
    $outsider->save();

    Volt::actingAs($this->owner)
        ->test('settings.workspaces.users', ['workspace' => $this->workspace])
        ->set('addUserIds', [$outsider->id])
        ->call('addUsers')
        ->assertHasNoErrors();

    // Outsider is silently rejected since they don't belong to this org
    expect($this->workspace->users()->where('users.id', $outsider->id)->exists())->toBeFalse();
});

it('renders workspace name as link to users page', function () {
    $this->actingAs($this->owner)
        ->get(route('settings.workspaces'))
        ->assertOk()
        ->assertSee(route('settings.workspaces.users', $this->workspace), false);
});

it('denies non-admin users access to workspace settings', function () {
    $viewer = User::factory()->create(['email_verified_at' => now()]);
    $viewer->organizations()->attach($this->org->id);
    $viewer->current_organization_id = $this->org->id;
    $viewer->save();
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $viewer->assignRole('viewer');

    $this->actingAs($viewer)
        ->get(route('settings.workspaces'))
        ->assertForbidden();
});
