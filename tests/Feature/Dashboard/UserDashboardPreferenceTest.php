<?php

use App\Models\Dashboard;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserDashboardPreference;
use App\Models\Workspace;
use App\Services\WorkspaceContext;
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

    $this->user = User::factory()->create([
        'email_verified_at' => now(),
        'current_organization_id' => $this->org->id,
    ]);
    $this->user->organizations()->attach($this->org->id);
    $this->user->save();

    $this->workspace->users()->attach($this->user->id);

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $this->user->assignRole('owner');

    $this->actingAs($this->user);
    app(WorkspaceContext::class)->setWorkspace($this->workspace);
});

// ── First-visit flow ───────────────────────────────────────────────────

it('shows template selection on first visit when no workspace dashboards exist', function () {
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Create Your Dashboard')
        ->assertSee('Executive Overview')
        ->assertSee('Campaign Manager')
        ->assertSee('Attribution Analyst')
        ->assertSee('Start from scratch');
});

it('shows existing dashboard when workspace already has dashboards', function () {
    Dashboard::create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->user->id,
        'name' => 'Existing Dashboard',
    ]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Existing Dashboard')
        ->assertDontSee('Create Your Dashboard');
});

// ── Template selection ─────────────────────────────────────────────────

it('cloning a template creates a dashboard and sets it as active', function () {
    Dashboard::create([
        'workspace_id' => null,
        'created_by' => $this->user->id,
        'name' => 'Executive Overview',
        'is_template' => true,
        'template_slug' => 'executive',
    ]);

    $this->post(route('dashboard.store'), [
        'name' => 'Executive Overview',
        'template_slug' => 'executive',
    ])->assertRedirect(route('dashboard'));

    $dashboard = Dashboard::forWorkspace($this->workspace->id)->notTemplates()->first();
    expect($dashboard)->not->toBeNull();
    expect($dashboard->name)->toBe('Executive Overview');
    expect($dashboard->is_template)->toBeFalse();

    $this->assertDatabaseHas('user_dashboard_preferences', [
        'user_id' => $this->user->id,
        'workspace_id' => $this->workspace->id,
        'active_dashboard_id' => $dashboard->id,
    ]);
});

it('"start from scratch" creates an empty dashboard', function () {
    $this->post(route('dashboard.store'), [
        'name' => 'My Dashboard',
    ])->assertRedirect(route('dashboard'));

    $dashboard = Dashboard::forWorkspace($this->workspace->id)->notTemplates()->first();
    expect($dashboard)->not->toBeNull();
    expect($dashboard->name)->toBe('My Dashboard');
    expect($dashboard->widgets()->count())->toBe(0);

    $this->assertDatabaseHas('user_dashboard_preferences', [
        'user_id' => $this->user->id,
        'workspace_id' => $this->workspace->id,
        'active_dashboard_id' => $dashboard->id,
    ]);
});

// ── Dashboard switching ────────────────────────────────────────────────

it('switching dashboards updates the user preference', function () {
    $first = Dashboard::create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->user->id,
        'name' => 'First Dashboard',
    ]);

    $second = Dashboard::create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->user->id,
        'name' => 'Second Dashboard',
    ]);

    UserDashboardPreference::create([
        'user_id' => $this->user->id,
        'workspace_id' => $this->workspace->id,
        'active_dashboard_id' => $first->id,
    ]);

    Volt::test('dashboard')
        ->call('switchDashboard', $second->id);

    $this->assertDatabaseHas('user_dashboard_preferences', [
        'user_id' => $this->user->id,
        'workspace_id' => $this->workspace->id,
        'active_dashboard_id' => $second->id,
    ]);
});

// ── Preference persistence ─────────────────────────────────────────────

it('active dashboard persists across page reloads', function () {
    $dashboard = Dashboard::create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->user->id,
        'name' => 'Persisted Dashboard',
    ]);

    UserDashboardPreference::create([
        'user_id' => $this->user->id,
        'workspace_id' => $this->workspace->id,
        'active_dashboard_id' => $dashboard->id,
    ]);

    // First load
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Persisted Dashboard');

    // Second load — preference still in place
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Persisted Dashboard');
});

it('when no preference exists and dashboards are present, shows first dashboard', function () {
    Dashboard::create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->user->id,
        'name' => 'Auto Selected Dashboard',
    ]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Auto Selected Dashboard')
        ->assertDontSee('Create Your Dashboard');
});
