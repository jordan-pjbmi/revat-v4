<?php

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceRecent;
use App\Services\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
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
});

it('toggles pin on for a workspace with existing pivot', function () {
    $context = app(WorkspaceContext::class);
    $result = $context->togglePin($this->owner, $this->ws1);
    expect($result)->toBeTrue();
    expect($this->owner->workspaces()->where('workspaces.id', $this->ws1->id)->first()->pivot->is_pinned)->toBeTrue();
});

it('toggles pin off for already pinned workspace', function () {
    $context = app(WorkspaceContext::class);
    $context->togglePin($this->owner, $this->ws1);
    $result = $context->togglePin($this->owner, $this->ws1);
    expect($result)->toBeFalse();
});

it('creates pivot entry when implicit-access user pins', function () {
    $context = app(WorkspaceContext::class);
    $result = $context->togglePin($this->owner, $this->ws2);
    expect($result)->toBeTrue();
    expect($this->owner->workspaces()->where('workspaces.id', $this->ws2->id)->exists())->toBeTrue();
});

it('returns pinned workspace ids', function () {
    $context = app(WorkspaceContext::class);
    $context->togglePin($this->owner, $this->ws1);
    $pinned = $context->pinnedWorkspaceIds($this->owner, $this->org);
    expect($pinned)->toContain($this->ws1->id);
});

it('returns recent workspaces excluding current', function () {
    WorkspaceRecent::create([
        'user_id' => $this->owner->id,
        'organization_id' => $this->org->id,
        'workspace_id' => $this->ws2->id,
        'switched_at' => now(),
    ]);
    $context = app(WorkspaceContext::class);
    $recents = $context->recentWorkspaces($this->owner, $this->org, $this->ws1->id);
    expect($recents)->toHaveCount(1)->and($recents->first()->id)->toBe($this->ws2->id);
});

it('pin toggle route works', function () {
    $this->actingAs($this->owner)
        ->post(route('toggle-workspace-pin', $this->ws1))
        ->assertOk()
        ->assertJson(['is_pinned' => true]);
});
