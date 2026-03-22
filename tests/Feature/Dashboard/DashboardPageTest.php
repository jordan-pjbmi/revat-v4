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

    $this->user = User::factory()->create([
        'email_verified_at' => now(),
    ]);
    $this->user->organizations()->attach($this->org->id);
    $this->user->current_organization_id = $this->org->id;
    $this->user->save();

    $this->workspace->users()->attach($this->user->id);

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $this->user->assignRole('owner');
});

it('renders template selection when no dashboards exist', function () {
    $this->actingAs($this->user);
    app(WorkspaceContext::class)->setWorkspace($this->workspace);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Create Your Dashboard')
        ->assertSee('Executive Overview')
        ->assertSee('Campaign Manager')
        ->assertSee('Attribution Analyst')
        ->assertSee('Start from scratch');
});

it('renders active dashboard when one exists', function () {
    $this->actingAs($this->user);
    app(WorkspaceContext::class)->setWorkspace($this->workspace);

    $dashboard = Dashboard::create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->user->id,
        'name' => 'My Custom Dashboard',
    ]);

    UserDashboardPreference::create([
        'user_id' => $this->user->id,
        'workspace_id' => $this->workspace->id,
        'active_dashboard_id' => $dashboard->id,
    ]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('My Custom Dashboard');
});

it('shows first dashboard when no preference exists', function () {
    $this->actingAs($this->user);
    app(WorkspaceContext::class)->setWorkspace($this->workspace);

    Dashboard::create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->user->id,
        'name' => 'First Dashboard',
    ]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('First Dashboard');
});

it('requires authentication', function () {
    $this->get(route('dashboard'))
        ->assertRedirect(route('login'));
});
