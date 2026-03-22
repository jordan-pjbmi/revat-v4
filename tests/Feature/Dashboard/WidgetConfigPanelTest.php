<?php

use App\Livewire\Dashboard\WidgetConfigPanel;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->workspace = new Workspace(['name' => 'Default']);
    $this->workspace->organization_id = $this->org->id;
    $this->workspace->is_default = true;
    $this->workspace->save();

    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->user->organizations()->attach($this->org->id);
    $this->user->current_organization_id = $this->org->id;
    $this->user->save();
    $this->workspace->users()->attach($this->user->id);

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $this->user->assignRole('owner');

    $this->actingAs($this->user);

    $this->dashboard = Dashboard::create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->user->id,
        'name' => 'Test Dashboard',
    ]);
});

it('renders the widget config panel', function () {
    Livewire::test(WidgetConfigPanel::class)
        ->assertSet('open', false)
        ->assertSet('mode', 'catalog');
});

it('opens in catalog mode when open-widget-catalog event is dispatched', function () {
    Livewire::test(WidgetConfigPanel::class)
        ->dispatch('open-widget-catalog')
        ->assertSet('open', true)
        ->assertSet('mode', 'catalog');
});

it('switches to config mode when a widget type is selected', function () {
    Livewire::test(WidgetConfigPanel::class)
        ->dispatch('open-widget-catalog')
        ->call('selectWidgetType', 'line_chart')
        ->assertSet('mode', 'config')
        ->assertSet('widgetType', 'line_chart')
        ->assertSet('chartType', 'line')
        ->assertSet('dataSource', 'conversion_metrics')
        ->assertSet('measure', 'revenue');
});

it('pre-fills bar chart defaults when selecting bar_chart', function () {
    Livewire::test(WidgetConfigPanel::class)
        ->call('selectWidgetType', 'bar_chart')
        ->assertSet('chartType', 'bar')
        ->assertSet('dataSource', 'campaign_metrics')
        ->assertSet('measure', 'sent');
});

it('pre-fills pie chart defaults with group by', function () {
    Livewire::test(WidgetConfigPanel::class)
        ->call('selectWidgetType', 'pie_chart')
        ->assertSet('chartType', 'pie')
        ->assertSet('dataSource', 'platform_breakdown')
        ->assertSet('groupBy', 'platform');
});

it('opens in config mode for an existing widget', function () {
    $widget = DashboardWidget::create([
        'dashboard_id' => $this->dashboard->id,
        'widget_type' => 'bar_chart',
        'grid_x' => 0,
        'grid_y' => 0,
        'grid_w' => 6,
        'grid_h' => 4,
        'config' => [
            'display' => ['title' => 'My Chart', 'subtitle' => 'Sales data'],
            'data_source' => 'campaign_metrics',
            'measure' => 'sent',
            'group_by' => null,
            'limit' => 20,
            'visualization' => ['chart_type' => 'bar', 'show_labels' => true, 'show_legend' => false],
            'filters' => [],
            'date_range_override' => null,
        ],
        'sort_order' => 0,
    ]);

    Livewire::test(WidgetConfigPanel::class)
        ->dispatch('open-widget-config', widgetId: $widget->id)
        ->assertSet('open', true)
        ->assertSet('mode', 'config')
        ->assertSet('editingWidgetId', $widget->id)
        ->assertSet('title', 'My Chart')
        ->assertSet('subtitle', 'Sales data')
        ->assertSet('dataSource', 'campaign_metrics')
        ->assertSet('limit', 20);
});

it('adds and removes filters', function () {
    Livewire::test(WidgetConfigPanel::class)
        ->call('addFilter')
        ->assertCount('filters', 1)
        ->call('addFilter')
        ->assertCount('filters', 2)
        ->call('removeFilter', 0)
        ->assertCount('filters', 1);
});

it('closes and resets state', function () {
    Livewire::test(WidgetConfigPanel::class)
        ->dispatch('open-widget-catalog')
        ->call('selectWidgetType', 'bar_chart')
        ->call('close')
        ->assertSet('open', false)
        ->assertSet('mode', 'catalog')
        ->assertSet('widgetType', '')
        ->assertSet('dataSource', '');
});

it('dispatches add-widget-to-grid event when saving a new widget', function () {
    Livewire::test(WidgetConfigPanel::class)
        ->call('selectWidgetType', 'single_metric')
        ->set('title', 'Revenue')
        ->set('dataSource', 'conversion_metrics')
        ->set('measure', 'revenue')
        ->call('save')
        ->assertDispatched('add-widget-to-grid');
});

it('updates widget in database when saving an existing widget', function () {
    $widget = DashboardWidget::create([
        'dashboard_id' => $this->dashboard->id,
        'widget_type' => 'bar_chart',
        'grid_x' => 0,
        'grid_y' => 0,
        'grid_w' => 6,
        'grid_h' => 4,
        'config' => ['display' => ['title' => 'Old Title', 'subtitle' => null]],
        'sort_order' => 0,
    ]);

    Livewire::test(WidgetConfigPanel::class)
        ->dispatch('open-widget-config', widgetId: $widget->id)
        ->set('title', 'New Title')
        ->set('dataSource', 'campaign_metrics')
        ->set('measure', 'sent')
        ->call('save')
        ->assertDispatched('widget-config-updated.'.$widget->id);

    $widget->refresh();
    expect($widget->config['display']['title'])->toBe('New Title');
});

it('returns available widget types by category', function () {
    $component = Livewire::test(WidgetConfigPanel::class);
    $types = $component->instance()->availableWidgetTypes();

    expect($types)->toHaveKey('Key Metrics')
        ->and($types)->toHaveKey('Charts')
        ->and($types)->toHaveKey('Data');
});

it('filters widget types by search query', function () {
    $component = Livewire::test(WidgetConfigPanel::class)
        ->set('searchQuery', 'chart');

    $types = $component->instance()->availableWidgetTypes();

    expect($types)->toHaveKey('Charts');

    foreach ($types as $category => $widgets) {
        foreach ($widgets as $type => $widget) {
            expect(
                str_contains(mb_strtolower($widget['name']), 'chart') ||
                str_contains(mb_strtolower($widget['description']), 'chart') ||
                str_contains(mb_strtolower($category), 'chart')
            )->toBeTrue("Widget '{$widget['name']}' should not appear in 'chart' search");
        }
    }
});
