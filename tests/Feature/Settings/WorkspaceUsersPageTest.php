<?php

use App\Models\Organization;
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

    $this->owner = User::factory()->create(['name' => 'Owner User', 'email_verified_at' => now()]);
    $this->owner->organizations()->attach($this->org->id);
    $this->owner->current_organization_id = $this->org->id;
    $this->owner->save();
    $this->workspace->users()->attach($this->owner->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $this->owner->assignRole('owner');

    $this->admin = User::factory()->create(['name' => 'Admin User', 'email_verified_at' => now()]);
    $this->admin->organizations()->attach($this->org->id);
    $this->admin->current_organization_id = $this->org->id;
    $this->admin->save();
    $this->admin->assignRole('admin');

    $this->editor = User::factory()->create(['name' => 'Editor User', 'email_verified_at' => now()]);
    $this->editor->organizations()->attach($this->org->id);
    $this->editor->current_organization_id = $this->org->id;
    $this->editor->save();
    $this->workspace->users()->attach($this->editor->id);
    $this->editor->assignRole('editor');
});

it('shows admins section with implicit-access users', function () {
    $this->actingAs($this->owner)
        ->get(route('settings.workspaces.users', $this->workspace))
        ->assertOk()
        ->assertSee('Admins')
        ->assertSee('Access via role')
        ->assertSee('Owner User')
        ->assertSee('Admin User');
});

it('shows members section with explicitly assigned users', function () {
    $this->actingAs($this->owner)
        ->get(route('settings.workspaces.users', $this->workspace))
        ->assertOk()
        ->assertSee('Editor User');
});

it('bulk adds multiple users', function () {
    $viewer = User::factory()->create(['name' => 'Viewer User', 'email_verified_at' => now()]);
    $viewer->organizations()->attach($this->org->id);
    $viewer->current_organization_id = $this->org->id;
    $viewer->save();
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $viewer->assignRole('viewer');

    Volt::actingAs($this->owner)
        ->test('settings.workspaces.users', ['workspace' => $this->workspace])
        ->set('addUserIds', [$viewer->id])
        ->call('addUsers')
        ->assertHasNoErrors();

    expect($this->workspace->users()->where('users.id', $viewer->id)->exists())->toBeTrue();
});

it('bulk removes multiple users', function () {
    Volt::actingAs($this->owner)
        ->test('settings.workspaces.users', ['workspace' => $this->workspace])
        ->set('selectedUserIds', [$this->editor->id])
        ->call('removeUsers')
        ->assertHasNoErrors();

    expect($this->workspace->users()->where('users.id', $this->editor->id)->exists())->toBeFalse();
});

it('copies members from another workspace filtered by role', function () {
    $ws2 = new Workspace(['name' => 'Source WS']);
    $ws2->organization_id = $this->org->id;
    $ws2->save();

    $viewer = User::factory()->create(['name' => 'Viewer User', 'email_verified_at' => now()]);
    $viewer->organizations()->attach($this->org->id);
    $viewer->current_organization_id = $this->org->id;
    $viewer->save();
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $viewer->assignRole('viewer');
    $ws2->users()->attach($viewer->id);

    Volt::actingAs($this->owner)
        ->test('settings.workspaces.users', ['workspace' => $this->workspace])
        ->set('copySourceId', $ws2->id)
        ->set('copyRoles', ['viewer'])
        ->call('copyFromWorkspace')
        ->assertHasNoErrors();

    expect($this->workspace->users()->where('users.id', $viewer->id)->exists())->toBeTrue();
});
