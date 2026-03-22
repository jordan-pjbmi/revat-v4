<?php

use App\Models\Dashboard;
use App\Models\DashboardSnapshot;
use App\Models\DashboardWidget;
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

function createSnapshotUser(string $role, $testContext): User
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

function actAsSnapshotUser(User $user, $testContext): void
{
    $testContext->actingAs($user);
    app(WorkspaceContext::class)->setWorkspace($testContext->workspace);
}

function createSnapshotDashboard($testContext, array $overrides = []): Dashboard
{
    return Dashboard::create(array_merge([
        'workspace_id' => $testContext->workspace->id,
        'created_by' => User::factory()->create()->id,
        'name' => 'Test Dashboard',
    ], $overrides));
}

function addSnapshotWidget(Dashboard $dashboard, array $overrides = []): DashboardWidget
{
    return DashboardWidget::create(array_merge([
        'dashboard_id' => $dashboard->id,
        'widget_type' => 'single_metric',
        'grid_x' => 0,
        'grid_y' => 0,
        'grid_w' => 3,
        'grid_h' => 2,
        'config' => ['data_source' => 'conversion_metrics', 'measure' => 'revenue'],
        'sort_order' => 0,
    ], $overrides));
}

// ── Snapshot creation ──────────────────────────────────────────────────

it('creates a snapshot with correct layout data and widget count', function () {
    $user = createSnapshotUser('editor', $this);
    $dashboard = createSnapshotDashboard($this, ['created_by' => $user->id]);

    addSnapshotWidget($dashboard, ['widget_type' => 'single_metric', 'grid_x' => 0, 'grid_y' => 0]);
    addSnapshotWidget($dashboard, ['widget_type' => 'line_chart', 'grid_x' => 3, 'grid_y' => 0]);

    $fresh = $dashboard->fresh();
    $snapshot = DashboardSnapshot::create([
        'dashboard_id' => $dashboard->id,
        'created_by' => $user->id,
        'layout' => $fresh->widgets->map(fn ($w) => [
            'widget_type' => $w->widget_type,
            'grid_x' => $w->grid_x,
            'grid_y' => $w->grid_y,
            'grid_w' => $w->grid_w,
            'grid_h' => $w->grid_h,
            'config' => $w->config,
            'sort_order' => $w->sort_order,
        ])->toArray(),
        'widget_count' => 2,
        'created_at' => now(),
    ]);

    expect($snapshot->widget_count)->toBe(2);
    expect($snapshot->layout)->toHaveCount(2);
    expect($snapshot->layout[0]['widget_type'])->toBe('single_metric');
    expect($snapshot->layout[1]['widget_type'])->toBe('line_chart');
    expect($snapshot->dashboard_id)->toBe($dashboard->id);
    expect($snapshot->created_by)->toBe($user->id);
});

// ── Snapshot restore ───────────────────────────────────────────────────

it('restoring a snapshot replaces all current widgets with snapshot data', function () {
    $user = createSnapshotUser('editor', $this);
    actAsSnapshotUser($user, $this);

    $dashboard = createSnapshotDashboard($this, ['created_by' => $user->id]);

    $w1 = addSnapshotWidget($dashboard, ['widget_type' => 'single_metric', 'grid_x' => 0, 'grid_y' => 0]);
    addSnapshotWidget($dashboard, ['widget_type' => 'line_chart', 'grid_x' => 3, 'grid_y' => 0]);

    $fresh = $dashboard->fresh();
    $snapshot = DashboardSnapshot::create([
        'dashboard_id' => $dashboard->id,
        'created_by' => $user->id,
        'layout' => $fresh->widgets->map(fn ($w) => [
            'widget_type' => $w->widget_type,
            'grid_x' => $w->grid_x,
            'grid_y' => $w->grid_y,
            'grid_w' => $w->grid_w,
            'grid_h' => $w->grid_h,
            'config' => $w->config,
            'sort_order' => $w->sort_order,
        ])->toArray(),
        'widget_count' => 2,
        'created_at' => now(),
    ]);

    // Modify dashboard: remove one widget, add a different one
    $w1->delete();
    addSnapshotWidget($dashboard, ['widget_type' => 'stat_row', 'grid_x' => 0, 'grid_y' => 2]);

    expect($dashboard->fresh()->widgets)->toHaveCount(2);

    // Restore snapshot
    $this->post(route('dashboard.restore', [$dashboard, $snapshot]))
        ->assertRedirect(route('dashboard'));

    $restoredWidgets = $dashboard->fresh()->widgets;
    expect($restoredWidgets)->toHaveCount(2);
    $types = $restoredWidgets->pluck('widget_type')->sort()->values()->toArray();
    expect($types)->toBe(['line_chart', 'single_metric']);
});

// ── Version history ordering ───────────────────────────────────────────

it('version history returns snapshots ordered by created_at descending', function () {
    $user = createSnapshotUser('editor', $this);
    $dashboard = createSnapshotDashboard($this, ['created_by' => $user->id]);

    $older = DashboardSnapshot::create([
        'dashboard_id' => $dashboard->id,
        'created_by' => $user->id,
        'layout' => [],
        'widget_count' => 0,
        'created_at' => now()->subHours(2),
    ]);

    $newer = DashboardSnapshot::create([
        'dashboard_id' => $dashboard->id,
        'created_by' => $user->id,
        'layout' => [],
        'widget_count' => 0,
        'created_at' => now()->subHour(),
    ]);

    $newest = DashboardSnapshot::create([
        'dashboard_id' => $dashboard->id,
        'created_by' => $user->id,
        'layout' => [],
        'widget_count' => 0,
        'created_at' => now(),
    ]);

    $ordered = $dashboard->snapshots()->orderByDesc('created_at')->pluck('id')->toArray();

    expect($ordered[0])->toBe($newest->id);
    expect($ordered[1])->toBe($newer->id);
    expect($ordered[2])->toBe($older->id);
});

// ── Restore permissions ────────────────────────────────────────────────

it('editor can restore when dashboard is unlocked', function () {
    $user = createSnapshotUser('editor', $this);
    actAsSnapshotUser($user, $this);

    $dashboard = createSnapshotDashboard($this, ['created_by' => $user->id]);
    addSnapshotWidget($dashboard, ['widget_type' => 'single_metric']);

    $snapshot = DashboardSnapshot::create([
        'dashboard_id' => $dashboard->id,
        'created_by' => $user->id,
        'layout' => [
            [
                'widget_type' => 'single_metric',
                'grid_x' => 0, 'grid_y' => 0, 'grid_w' => 3, 'grid_h' => 2,
                'config' => [], 'sort_order' => 0,
            ],
        ],
        'widget_count' => 1,
        'created_at' => now(),
    ]);

    $this->post(route('dashboard.restore', [$dashboard, $snapshot]))
        ->assertRedirect(route('dashboard'));
});

it('viewer cannot restore a snapshot', function () {
    $editor = createSnapshotUser('editor', $this);
    $viewer = createSnapshotUser('viewer', $this);
    actAsSnapshotUser($viewer, $this);

    $dashboard = createSnapshotDashboard($this, ['created_by' => $editor->id]);

    $snapshot = DashboardSnapshot::create([
        'dashboard_id' => $dashboard->id,
        'created_by' => $editor->id,
        'layout' => [],
        'widget_count' => 0,
        'created_at' => now(),
    ]);

    $this->post(route('dashboard.restore', [$dashboard, $snapshot]))
        ->assertForbidden();
});
