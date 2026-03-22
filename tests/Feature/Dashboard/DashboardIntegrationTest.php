<?php

use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\UserDashboardPreference;
use App\Services\WorkspaceContext;

beforeEach(function () {
    $ctx = $this->createAuthenticatedUser('owner');
    $this->user = $ctx['user'];
    $this->workspace = $ctx['workspace'];

    app(WorkspaceContext::class)->setWorkspace($this->workspace);
});

it('renders dashboard page with all widget types without errors', function () {
    $dashboard = Dashboard::create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->user->id,
        'name' => 'Test Dashboard',
    ]);

    DashboardWidget::create([
        'dashboard_id' => $dashboard->id,
        'widget_type' => 'stat_row',
        'grid_x' => 0, 'grid_y' => 0, 'grid_w' => 12, 'grid_h' => 2,
        'config' => [
            'data_source' => 'campaign_metrics',
            'measures' => ['sent', 'opens', 'clicks'],
            'display' => ['title' => 'Key Metrics'],
        ],
    ]);

    DashboardWidget::create([
        'dashboard_id' => $dashboard->id,
        'widget_type' => 'single_metric',
        'grid_x' => 0, 'grid_y' => 2, 'grid_w' => 3, 'grid_h' => 2,
        'config' => [
            'data_source' => 'conversion_metrics',
            'measure' => 'revenue',
            'display' => ['title' => 'Revenue'],
        ],
    ]);

    DashboardWidget::create([
        'dashboard_id' => $dashboard->id,
        'widget_type' => 'line_chart',
        'grid_x' => 3, 'grid_y' => 2, 'grid_w' => 9, 'grid_h' => 4,
        'config' => [
            'data_source' => 'conversion_metrics',
            'measure' => 'revenue',
            'visualization' => ['chart_type' => 'line', 'show_legend' => false],
            'display' => ['title' => 'Revenue Trend'],
        ],
    ]);

    DashboardWidget::create([
        'dashboard_id' => $dashboard->id,
        'widget_type' => 'bar_chart',
        'grid_x' => 0, 'grid_y' => 6, 'grid_w' => 6, 'grid_h' => 4,
        'config' => [
            'data_source' => 'platform_breakdown',
            'measure' => 'sent',
            'group_by' => 'platform',
            'limit' => 10,
            'visualization' => ['chart_type' => 'bar'],
            'display' => ['title' => 'Sends by Platform'],
        ],
    ]);

    DashboardWidget::create([
        'dashboard_id' => $dashboard->id,
        'widget_type' => 'pie_chart',
        'grid_x' => 6, 'grid_y' => 6, 'grid_w' => 6, 'grid_h' => 4,
        'config' => [
            'data_source' => 'platform_breakdown',
            'measure' => 'sent',
            'group_by' => 'platform',
            'visualization' => ['chart_type' => 'pie', 'show_legend' => true],
            'display' => ['title' => 'By Platform'],
        ],
    ]);

    DashboardWidget::create([
        'dashboard_id' => $dashboard->id,
        'widget_type' => 'table',
        'grid_x' => 0, 'grid_y' => 10, 'grid_w' => 12, 'grid_h' => 5,
        'config' => [
            'data_source' => 'campaign_metrics',
            'display' => ['title' => 'Campaign Data'],
        ],
    ]);

    UserDashboardPreference::create([
        'user_id' => $this->user->id,
        'workspace_id' => $this->workspace->id,
        'active_dashboard_id' => $dashboard->id,
    ]);

    $this->get('/dashboard')
        ->assertOk()
        ->assertSee('Test Dashboard');
});

it('renders template selection when no dashboards exist', function () {
    $this->get('/dashboard')
        ->assertOk();
});
