<?php

use App\Models\Dashboard;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserDashboardPreference;
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

function makeUserWithRole(string $role, $ctx): User
{
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'current_organization_id' => $ctx->org->id,
    ]);
    $user->organizations()->attach($ctx->org->id);
    $user->save();
    $ctx->workspace->users()->attach($user->id);

    app(PermissionRegistrar::class)->setPermissionsTeamId($ctx->org->id);
    $user->assignRole($role);

    return $user;
}

function actAsUser(User $user, $ctx): void
{
    $ctx->actingAs($user);
    app(WorkspaceContext::class)->setWorkspace($ctx->workspace);
}

function makeDashboard($ctx, array $overrides = []): Dashboard
{
    return Dashboard::create(array_merge([
        'workspace_id' => $ctx->workspace->id,
        'created_by' => User::factory()->create()->id,
        'name' => 'Test Dashboard',
    ], $overrides));
}

// ── Lock / Unlock ──────────────────────────────────────────────────────

it('admin can lock a dashboard', function () {
    $admin = makeUserWithRole('admin', $this);
    actAsUser($admin, $this);

    $dashboard = makeDashboard($this, ['created_by' => $admin->id]);

    $this->post(route('dashboard.toggle-lock', $dashboard))
        ->assertRedirect(route('dashboard'));

    expect($dashboard->fresh()->is_locked)->toBeTrue();
});

it('admin can unlock a dashboard', function () {
    $admin = makeUserWithRole('admin', $this);
    actAsUser($admin, $this);

    $dashboard = makeDashboard($this, ['created_by' => $admin->id, 'is_locked' => true]);

    $this->post(route('dashboard.toggle-lock', $dashboard))
        ->assertRedirect(route('dashboard'));

    expect($dashboard->fresh()->is_locked)->toBeFalse();
});

it('owner can lock a dashboard', function () {
    $owner = makeUserWithRole('owner', $this);
    actAsUser($owner, $this);

    $dashboard = makeDashboard($this, ['created_by' => $owner->id]);

    $this->post(route('dashboard.toggle-lock', $dashboard))
        ->assertRedirect(route('dashboard'));

    expect($dashboard->fresh()->is_locked)->toBeTrue();
});

it('editor cannot lock a dashboard', function () {
    $editor = makeUserWithRole('editor', $this);
    actAsUser($editor, $this);

    $dashboard = makeDashboard($this);

    $this->post(route('dashboard.toggle-lock', $dashboard))
        ->assertForbidden();

    expect($dashboard->fresh()->is_locked)->toBeFalse();
});

it('editor cannot unlock a dashboard', function () {
    $editor = makeUserWithRole('editor', $this);
    actAsUser($editor, $this);

    $dashboard = makeDashboard($this, ['is_locked' => true]);

    $this->post(route('dashboard.toggle-lock', $dashboard))
        ->assertForbidden();

    expect($dashboard->fresh()->is_locked)->toBeTrue();
});

// ── Customize button visibility ────────────────────────────────────────

it('customize button is hidden for editor when dashboard is locked', function () {
    $editor = makeUserWithRole('editor', $this);
    actAsUser($editor, $this);

    $dashboard = makeDashboard($this, ['is_locked' => true]);

    UserDashboardPreference::create([
        'user_id' => $editor->id,
        'workspace_id' => $this->workspace->id,
        'active_dashboard_id' => $dashboard->id,
    ]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Customize');
});

it('customize button is visible for admin when dashboard is locked', function () {
    $admin = makeUserWithRole('admin', $this);
    actAsUser($admin, $this);

    $dashboard = makeDashboard($this, ['created_by' => $admin->id, 'is_locked' => true]);

    UserDashboardPreference::create([
        'user_id' => $admin->id,
        'workspace_id' => $this->workspace->id,
        'active_dashboard_id' => $dashboard->id,
    ]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Customize');
});

it('customize button is visible for editor when dashboard is unlocked', function () {
    $editor = makeUserWithRole('editor', $this);
    actAsUser($editor, $this);

    $dashboard = makeDashboard($this, ['is_locked' => false]);

    UserDashboardPreference::create([
        'user_id' => $editor->id,
        'workspace_id' => $this->workspace->id,
        'active_dashboard_id' => $dashboard->id,
    ]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Customize');
});

// ── Lock indicator ─────────────────────────────────────────────────────

it('shows lock indicator when dashboard is locked', function () {
    $owner = makeUserWithRole('owner', $this);
    actAsUser($owner, $this);

    $dashboard = makeDashboard($this, ['created_by' => $owner->id, 'is_locked' => true]);

    UserDashboardPreference::create([
        'user_id' => $owner->id,
        'workspace_id' => $this->workspace->id,
        'active_dashboard_id' => $dashboard->id,
    ]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Dashboard is locked');
});

it('does not show lock indicator when dashboard is unlocked', function () {
    $owner = makeUserWithRole('owner', $this);
    actAsUser($owner, $this);

    $dashboard = makeDashboard($this, ['created_by' => $owner->id, 'is_locked' => false]);

    UserDashboardPreference::create([
        'user_id' => $owner->id,
        'workspace_id' => $this->workspace->id,
        'active_dashboard_id' => $dashboard->id,
    ]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Dashboard is locked');
});

// ── Locked dashboard cannot be deleted ────────────────────────────────

it('locked dashboard cannot be deleted even by owner', function () {
    $owner = makeUserWithRole('owner', $this);
    actAsUser($owner, $this);

    $dashboard = makeDashboard($this, ['created_by' => $owner->id, 'is_locked' => true]);

    $this->delete(route('dashboard.destroy', $dashboard))
        ->assertForbidden();

    $this->assertDatabaseHas('dashboards', ['id' => $dashboard->id]);
});

// ── Lock state visible to all users ───────────────────────────────────

it('lock state is visible to viewer', function () {
    $viewer = makeUserWithRole('viewer', $this);
    actAsUser($viewer, $this);

    $dashboard = makeDashboard($this, ['is_locked' => true]);

    UserDashboardPreference::create([
        'user_id' => $viewer->id,
        'workspace_id' => $this->workspace->id,
        'active_dashboard_id' => $dashboard->id,
    ]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Dashboard is locked');
});
