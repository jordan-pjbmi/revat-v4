<?php

use App\Models\Dashboard;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceContext;
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

function createUserWithRole(string $role, $testContext): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->organizations()->attach($testContext->org->id);
    $user->current_organization_id = $testContext->org->id;
    $user->save();
    $testContext->workspace->users()->attach($user->id);

    app(PermissionRegistrar::class)->setPermissionsTeamId($testContext->org->id);
    $user->assignRole($role);

    return $user;
}

function actAs(User $user, $testContext): void
{
    $testContext->actingAs($user);
    app(WorkspaceContext::class)->setWorkspace($testContext->workspace);
}

function createDashboard($testContext, array $overrides = []): Dashboard
{
    $defaultCreator = $overrides['created_by'] ?? User::factory()->create()->id;

    return Dashboard::create(array_merge([
        'workspace_id' => $testContext->workspace->id,
        'created_by' => $defaultCreator,
        'name' => 'Test Dashboard',
    ], $overrides));
}

// ── All roles can view the dashboard page ─────────────────────────────

it('owner can view dashboard page', function () {
    $user = createUserWithRole('owner', $this);
    actAs($user, $this);

    $this->get(route('dashboard'))->assertOk();
});

it('admin can view dashboard page', function () {
    $user = createUserWithRole('admin', $this);
    actAs($user, $this);

    $this->get(route('dashboard'))->assertOk();
});

it('editor can view dashboard page', function () {
    $user = createUserWithRole('editor', $this);
    actAs($user, $this);

    $this->get(route('dashboard'))->assertOk();
});

it('viewer can view dashboard page', function () {
    $user = createUserWithRole('viewer', $this);
    actAs($user, $this);

    $this->get(route('dashboard'))->assertOk();
});

// ── Create (store) — requires integrate ────────────────────────────────

it('owner can create a dashboard', function () {
    $user = createUserWithRole('owner', $this);
    actAs($user, $this);

    $this->post(route('dashboard.store'), ['name' => 'New Dashboard'])
        ->assertRedirect(route('dashboard'));

    $this->assertDatabaseHas('dashboards', ['name' => 'New Dashboard', 'workspace_id' => $this->workspace->id]);
});

it('admin can create a dashboard', function () {
    $user = createUserWithRole('admin', $this);
    actAs($user, $this);

    $this->post(route('dashboard.store'), ['name' => 'Admin Dashboard'])
        ->assertRedirect(route('dashboard'));

    $this->assertDatabaseHas('dashboards', ['name' => 'Admin Dashboard']);
});

it('editor can create a dashboard', function () {
    $user = createUserWithRole('editor', $this);
    actAs($user, $this);

    $this->post(route('dashboard.store'), ['name' => 'Editor Dashboard'])
        ->assertRedirect(route('dashboard'));

    $this->assertDatabaseHas('dashboards', ['name' => 'Editor Dashboard']);
});

it('viewer cannot create a dashboard', function () {
    $user = createUserWithRole('viewer', $this);
    actAs($user, $this);

    $this->post(route('dashboard.store'), ['name' => 'Viewer Dashboard'])
        ->assertForbidden();

    $this->assertDatabaseMissing('dashboards', ['name' => 'Viewer Dashboard']);
});

// ── Update — requires integrate ────────────────────────────────────────

it('owner can update a dashboard', function () {
    $user = createUserWithRole('owner', $this);
    actAs($user, $this);

    $dashboard = createDashboard($this, ['created_by' => $user->id]);

    $this->put(route('dashboard.update', $dashboard), ['name' => 'Updated Name'])
        ->assertRedirect(route('dashboard'));

    expect($dashboard->fresh()->name)->toBe('Updated Name');
});

it('editor can update an unlocked dashboard', function () {
    $user = createUserWithRole('editor', $this);
    actAs($user, $this);

    $dashboard = createDashboard($this, ['created_by' => $user->id]);

    $this->put(route('dashboard.update', $dashboard), ['name' => 'Editor Update'])
        ->assertRedirect(route('dashboard'));

    expect($dashboard->fresh()->name)->toBe('Editor Update');
});

it('viewer cannot update a dashboard', function () {
    $user = createUserWithRole('viewer', $this);
    actAs($user, $this);

    $dashboard = createDashboard($this);

    $this->put(route('dashboard.update', $dashboard), ['name' => 'Viewer Update'])
        ->assertForbidden();

    expect($dashboard->fresh()->name)->toBe('Test Dashboard');
});

// ── Delete — requires manage ──────────────────────────────────────────

it('owner can delete a dashboard', function () {
    $user = createUserWithRole('owner', $this);
    actAs($user, $this);

    $dashboard = createDashboard($this, ['created_by' => $user->id]);

    $this->delete(route('dashboard.destroy', $dashboard))
        ->assertRedirect(route('dashboard'));

    $this->assertDatabaseMissing('dashboards', ['id' => $dashboard->id]);
});

it('admin can delete a dashboard', function () {
    $user = createUserWithRole('admin', $this);
    actAs($user, $this);

    $dashboard = createDashboard($this, ['created_by' => $user->id]);

    $this->delete(route('dashboard.destroy', $dashboard))
        ->assertRedirect(route('dashboard'));

    $this->assertDatabaseMissing('dashboards', ['id' => $dashboard->id]);
});

it('editor cannot delete a dashboard', function () {
    $user = createUserWithRole('editor', $this);
    actAs($user, $this);

    $dashboard = createDashboard($this);

    $this->delete(route('dashboard.destroy', $dashboard))
        ->assertForbidden();

    $this->assertDatabaseHas('dashboards', ['id' => $dashboard->id]);
});

it('viewer cannot delete a dashboard', function () {
    $user = createUserWithRole('viewer', $this);
    actAs($user, $this);

    $dashboard = createDashboard($this);

    $this->delete(route('dashboard.destroy', $dashboard))
        ->assertForbidden();

    $this->assertDatabaseHas('dashboards', ['id' => $dashboard->id]);
});

// ── Lock/Unlock — requires manage ──────────────────────────────────────

it('owner can lock and unlock a dashboard', function () {
    $user = createUserWithRole('owner', $this);
    actAs($user, $this);

    $dashboard = createDashboard($this, ['created_by' => $user->id]);

    $this->post(route('dashboard.toggle-lock', $dashboard))
        ->assertRedirect(route('dashboard'));
    expect($dashboard->fresh()->is_locked)->toBeTrue();

    $this->post(route('dashboard.toggle-lock', $dashboard))
        ->assertRedirect(route('dashboard'));
    expect($dashboard->fresh()->is_locked)->toBeFalse();
});

it('admin can lock and unlock a dashboard', function () {
    $user = createUserWithRole('admin', $this);
    actAs($user, $this);

    $dashboard = createDashboard($this, ['created_by' => $user->id]);

    $this->post(route('dashboard.toggle-lock', $dashboard))
        ->assertRedirect(route('dashboard'));
    expect($dashboard->fresh()->is_locked)->toBeTrue();
});

it('editor cannot lock a dashboard', function () {
    $user = createUserWithRole('editor', $this);
    actAs($user, $this);

    $dashboard = createDashboard($this);

    $this->post(route('dashboard.toggle-lock', $dashboard))
        ->assertForbidden();

    expect($dashboard->fresh()->is_locked)->toBeFalse();
});

it('viewer cannot lock a dashboard', function () {
    $user = createUserWithRole('viewer', $this);
    actAs($user, $this);

    $dashboard = createDashboard($this);

    $this->post(route('dashboard.toggle-lock', $dashboard))
        ->assertForbidden();
});

// ── Locked dashboard rejects editor edits ──────────────────────────────

it('editor cannot update a locked dashboard', function () {
    $user = createUserWithRole('editor', $this);
    actAs($user, $this);

    $dashboard = createDashboard($this, ['is_locked' => true]);

    $this->put(route('dashboard.update', $dashboard), ['name' => 'Should Fail'])
        ->assertForbidden();

    expect($dashboard->fresh()->name)->toBe('Test Dashboard');
});

it('admin can update a locked dashboard', function () {
    $user = createUserWithRole('admin', $this);
    actAs($user, $this);

    $dashboard = createDashboard($this, ['created_by' => $user->id, 'is_locked' => true]);

    $this->put(route('dashboard.update', $dashboard), ['name' => 'Admin Override'])
        ->assertRedirect(route('dashboard'));

    expect($dashboard->fresh()->name)->toBe('Admin Override');
});

it('locked dashboard cannot be deleted', function () {
    $user = createUserWithRole('owner', $this);
    actAs($user, $this);

    $dashboard = createDashboard($this, ['created_by' => $user->id, 'is_locked' => true]);

    $this->delete(route('dashboard.destroy', $dashboard))
        ->assertForbidden();

    $this->assertDatabaseHas('dashboards', ['id' => $dashboard->id]);
});

// ── Workspace scoping ──────────────────────────────────────────────────

it('cannot update a dashboard from another workspace', function () {
    $user = createUserWithRole('owner', $this);
    actAs($user, $this);

    $otherWorkspace = new Workspace(['name' => 'Other']);
    $otherWorkspace->organization_id = $this->org->id;
    $otherWorkspace->save();

    $dashboard = Dashboard::create([
        'workspace_id' => $otherWorkspace->id,
        'created_by' => $user->id,
        'name' => 'Other WS Dashboard',
    ]);

    $this->put(route('dashboard.update', $dashboard), ['name' => 'Hacked'])
        ->assertForbidden();

    expect($dashboard->fresh()->name)->toBe('Other WS Dashboard');
});
