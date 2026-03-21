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
    $this->ws1 = new Workspace(['name' => 'WS 1']);
    $this->ws1->organization_id = $this->org->id;
    $this->ws1->is_default = true;
    $this->ws1->save();
    $this->ws2 = new Workspace(['name' => 'WS 2']);
    $this->ws2->organization_id = $this->org->id;
    $this->ws2->save();
    $this->owner = User::factory()->create(['email_verified_at' => now()]);
    $this->owner->organizations()->attach($this->org->id);
    $this->owner->current_organization_id = $this->org->id;
    $this->owner->save();
    $this->ws1->users()->attach($this->owner->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $this->owner->assignRole('owner');

    $this->editor = User::factory()->create(['name' => 'Editor User', 'email_verified_at' => now()]);
    $this->editor->organizations()->attach($this->org->id);
    $this->editor->current_organization_id = $this->org->id;
    $this->editor->save();
    $this->ws1->users()->attach($this->editor->id);
    $this->editor->assignRole('editor');
});

it('toggles workspace assignment for a user', function () {
    // Editor is not in ws2 — assign them
    Volt::actingAs($this->owner)
        ->test('settings.users.index')
        ->call('toggleWorkspaceAssignment', $this->editor->id, $this->ws2->id)
        ->assertHasNoErrors();

    expect($this->ws2->users()->where('users.id', $this->editor->id)->exists())->toBeTrue();

    // Toggle again — remove them
    Volt::actingAs($this->owner)
        ->test('settings.users.index')
        ->call('toggleWorkspaceAssignment', $this->editor->id, $this->ws2->id)
        ->assertHasNoErrors();

    expect($this->ws2->users()->where('users.id', $this->editor->id)->exists())->toBeFalse();
});

it('returns workspace assignments for a user', function () {
    Volt::actingAs($this->owner)
        ->test('settings.users.index')
        ->call('getWorkspaceAssignments', $this->editor->id)
        ->assertHasNoErrors();
});
