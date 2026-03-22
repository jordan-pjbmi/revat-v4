<?php

use App\Models\Dashboard;
use App\Models\DashboardSnapshot;
use App\Models\DashboardWidget;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserDashboardPreference;
use App\Models\Workspace;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->organization = Organization::create(['name' => 'Test Org']);
    $workspace = new Workspace(['name' => 'Test Workspace']);
    $workspace->organization_id = $this->organization->id;
    $workspace->save();
    $this->workspace = $workspace;
    $this->user = User::factory()->create(['current_organization_id' => $this->organization->id]);
    setPermissionsTeamId($this->organization->id);
    $this->user->assignRole('owner');
});

it('creates a dashboard with widgets', function () {
    $dashboard = Dashboard::create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->user->id,
        'name' => 'Test Dashboard',
    ]);

    $widget = DashboardWidget::create([
        'dashboard_id' => $dashboard->id,
        'widget_type' => 'single_metric',
        'grid_x' => 0,
        'grid_y' => 0,
        'grid_w' => 3,
        'grid_h' => 2,
        'config' => ['data_source' => 'conversion_metrics', 'measure' => 'revenue'],
    ]);

    expect($dashboard->widgets)->toHaveCount(1);
    expect($widget->config)->toBeArray();
    expect($widget->config['data_source'])->toBe('conversion_metrics');
});

it('cascades delete to widgets and snapshots', function () {
    $dashboard = Dashboard::create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->user->id,
        'name' => 'Test Dashboard',
    ]);

    DashboardWidget::create([
        'dashboard_id' => $dashboard->id,
        'widget_type' => 'single_metric',
        'grid_x' => 0, 'grid_y' => 0, 'grid_w' => 3, 'grid_h' => 2,
        'config' => [],
    ]);

    DashboardSnapshot::create([
        'dashboard_id' => $dashboard->id,
        'created_by' => $this->user->id,
        'layout' => [],
        'widget_count' => 1,
        'created_at' => now(),
    ]);

    $dashboard->delete();

    expect(DashboardWidget::where('dashboard_id', $dashboard->id)->count())->toBe(0);
    expect(DashboardSnapshot::where('dashboard_id', $dashboard->id)->count())->toBe(0);
});

it('clones a dashboard to a workspace', function () {
    $template = Dashboard::create([
        'workspace_id' => null,
        'created_by' => $this->user->id,
        'name' => 'Executive Overview',
        'is_template' => true,
        'template_slug' => 'executive',
    ]);

    DashboardWidget::create([
        'dashboard_id' => $template->id,
        'widget_type' => 'stat_row',
        'grid_x' => 0, 'grid_y' => 0, 'grid_w' => 12, 'grid_h' => 2,
        'config' => ['data_source' => 'campaign_metrics'],
    ]);

    DashboardWidget::create([
        'dashboard_id' => $template->id,
        'widget_type' => 'line_chart',
        'grid_x' => 0, 'grid_y' => 2, 'grid_w' => 8, 'grid_h' => 4,
        'config' => ['data_source' => 'conversion_metrics', 'measure' => 'revenue'],
    ]);

    $clone = $template->cloneToWorkspace($this->workspace->id, $this->user->id);

    expect($clone->is_template)->toBeFalse();
    expect($clone->workspace_id)->toBe($this->workspace->id);
    expect($clone->widgets)->toHaveCount(2);
    expect($clone->id)->not->toBe($template->id);
});

it('stores user dashboard preferences with unique constraint', function () {
    $dashboard = Dashboard::create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->user->id,
        'name' => 'My Dashboard',
    ]);

    $pref = UserDashboardPreference::create([
        'user_id' => $this->user->id,
        'workspace_id' => $this->workspace->id,
        'active_dashboard_id' => $dashboard->id,
    ]);

    expect($pref->activeDashboard->id)->toBe($dashboard->id);

    // Duplicate should fail
    expect(fn () => UserDashboardPreference::create([
        'user_id' => $this->user->id,
        'workspace_id' => $this->workspace->id,
        'active_dashboard_id' => $dashboard->id,
    ]))->toThrow(QueryException::class);
});

it('scopes dashboards to workspace excluding templates', function () {
    Dashboard::create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->user->id,
        'name' => 'Workspace Dashboard',
    ]);

    Dashboard::create([
        'workspace_id' => null,
        'created_by' => $this->user->id,
        'name' => 'Template',
        'is_template' => true,
    ]);

    expect($this->workspace->dashboards)->toHaveCount(1);
    expect(Dashboard::templates()->count())->toBe(1);
});
