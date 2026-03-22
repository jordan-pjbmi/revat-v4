<?php

use App\Models\Dashboard;
use App\Models\DashboardExport;
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

    $this->editor = createExportUser('editor', $this);
    actAsExportUser($this->editor, $this);

    $this->dashboard = createExportDashboard($this, ['created_by' => $this->editor->id]);

    addExportWidget($this->dashboard, ['widget_type' => 'single_metric', 'grid_x' => 0, 'grid_y' => 0]);
    addExportWidget($this->dashboard, ['widget_type' => 'line_chart', 'grid_x' => 3, 'grid_y' => 0]);
    addExportWidget($this->dashboard, ['widget_type' => 'bar_chart', 'grid_x' => 6, 'grid_y' => 0]);
});

function createExportUser(string $role, $testContext): User
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

function actAsExportUser(User $user, $testContext): void
{
    $testContext->actingAs($user);
    app(WorkspaceContext::class)->setWorkspace($testContext->workspace);
}

function createExportDashboard($testContext, array $overrides = []): Dashboard
{
    return Dashboard::create(array_merge([
        'workspace_id' => $testContext->workspace->id,
        'created_by' => User::factory()->create()->id,
        'name' => 'Test Dashboard',
        'description' => 'A test dashboard description',
    ], $overrides));
}

function addExportWidget(Dashboard $dashboard, array $overrides = []): DashboardWidget
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

function makeExport(Dashboard $dashboard, User $user, array $overrides = []): DashboardExport
{
    $layout = $dashboard->fresh()->widgets->map(fn (DashboardWidget $w) => [
        'widget_type' => $w->widget_type,
        'grid_x' => $w->grid_x,
        'grid_y' => $w->grid_y,
        'grid_w' => $w->grid_w,
        'grid_h' => $w->grid_h,
        'config' => $w->config,
        'sort_order' => $w->sort_order,
    ])->toArray();

    return DashboardExport::create(array_merge([
        'dashboard_id' => $dashboard->id,
        'created_by' => $user->id,
        'token' => DashboardExport::generateToken(),
        'name' => $dashboard->name,
        'description' => $dashboard->description,
        'layout' => $layout,
        'widget_count' => count($layout),
        'expires_at' => now()->addDays(30),
        'created_at' => now(),
    ], $overrides));
}

// ── Exporting ──────────────────────────────────────────────────────────

it('exporting creates a DashboardExport record with correct data', function () {
    $this->post(route('dashboard.export', $this->dashboard))
        ->assertRedirect(route('dashboard'));

    $export = DashboardExport::where('dashboard_id', $this->dashboard->id)->first();

    expect($export)->not->toBeNull();
    expect($export->name)->toBe('Test Dashboard');
    expect($export->widget_count)->toBe(3);
    expect($export->layout)->toHaveCount(3);
    expect($export->created_by)->toBe($this->editor->id);
    expect($export->token)->not->toBeEmpty();
    expect($export->expires_at)->not->toBeNull();
});

it('export response contains share URL in session', function () {
    $response = $this->post(route('dashboard.export', $this->dashboard));

    $response->assertRedirect(route('dashboard'));
    $response->assertSessionHas('share_url');

    $shareUrl = session('share_url');
    expect($shareUrl)->toContain('/dashboard/import/');
});

it('exported layout contains all widget fields', function () {
    $this->post(route('dashboard.export', $this->dashboard));

    $export = DashboardExport::where('dashboard_id', $this->dashboard->id)->first();
    $firstWidget = $export->layout[0];

    expect($firstWidget)->toHaveKeys(['widget_type', 'grid_x', 'grid_y', 'grid_w', 'grid_h', 'config', 'sort_order']);
});

// ── Import preview (showImport) ────────────────────────────────────────

it('import preview page is accessible with a valid token', function () {
    $export = makeExport($this->dashboard, $this->editor);

    $this->get(route('dashboard.import.show', $export->token))
        ->assertOk();
});

it('import preview shows dashboard name and widget count', function () {
    $export = makeExport($this->dashboard, $this->editor);

    $this->get(route('dashboard.import.show', $export->token))
        ->assertSee('Test Dashboard')
        ->assertSee('3');
});

it('import preview shows dashboard description when present', function () {
    $export = makeExport($this->dashboard, $this->editor);

    $this->get(route('dashboard.import.show', $export->token))
        ->assertSee('A test dashboard description');
});

it('expired export token returns 404 on preview page', function () {
    $export = makeExport($this->dashboard, $this->editor, [
        'expires_at' => now()->subDay(),
    ]);

    $this->get(route('dashboard.import.show', $export->token))
        ->assertNotFound();
});

it('invalid token returns 404 on preview page', function () {
    $this->get(route('dashboard.import.show', 'completely-invalid-token'))
        ->assertNotFound();
});

// ── Importing ──────────────────────────────────────────────────────────

it('importing creates a new dashboard in the workspace', function () {
    $export = makeExport($this->dashboard, $this->editor);

    $dashboardCountBefore = Dashboard::where('workspace_id', $this->workspace->id)->count();

    $this->post(route('dashboard.import', $export->token))
        ->assertRedirect(route('dashboard'));

    $dashboardCountAfter = Dashboard::where('workspace_id', $this->workspace->id)->count();
    expect($dashboardCountAfter)->toBe($dashboardCountBefore + 1);

    $imported = Dashboard::where('workspace_id', $this->workspace->id)
        ->orderByDesc('id')
        ->first();

    expect($imported->name)->toBe('Test Dashboard');
    expect($imported->description)->toBe('A test dashboard description');
});

it('imported dashboard has all widgets from the export', function () {
    $export = makeExport($this->dashboard, $this->editor);

    $this->post(route('dashboard.import', $export->token))
        ->assertRedirect(route('dashboard'));

    $imported = Dashboard::where('workspace_id', $this->workspace->id)
        ->where('created_by', $this->editor->id)
        ->orderByDesc('id')
        ->first();

    expect($imported->widgets)->toHaveCount(3);

    $types = $imported->widgets->pluck('widget_type')->sort()->values()->toArray();
    expect($types)->toBe(['bar_chart', 'line_chart', 'single_metric']);
});

it('importing an expired token returns 404', function () {
    $export = makeExport($this->dashboard, $this->editor, [
        'expires_at' => now()->subDay(),
    ]);

    $this->post(route('dashboard.import', $export->token))
        ->assertNotFound();
});

it('unauthenticated users are redirected to login on import POST', function () {
    $export = makeExport($this->dashboard, $this->editor);

    // Log out and attempt as guest
    auth()->logout();

    $this->post(route('dashboard.import', $export->token))
        ->assertRedirect(route('login'));
});

// ── Permissions ────────────────────────────────────────────────────────

it('viewer cannot export a dashboard', function () {
    $viewer = createExportUser('viewer', $this);
    actAsExportUser($viewer, $this);

    $this->post(route('dashboard.export', $this->dashboard))
        ->assertForbidden();

    $this->assertDatabaseMissing('dashboard_exports', [
        'dashboard_id' => $this->dashboard->id,
        'created_by' => $viewer->id,
    ]);
});

it('viewer cannot import a dashboard', function () {
    $export = makeExport($this->dashboard, $this->editor);

    $viewer = createExportUser('viewer', $this);
    actAsExportUser($viewer, $this);

    $this->post(route('dashboard.import', $export->token))
        ->assertForbidden();
});

// ── Revoking ───────────────────────────────────────────────────────────

it('revoking an export deletes the record', function () {
    $export = makeExport($this->dashboard, $this->editor);

    $this->assertDatabaseHas('dashboard_exports', ['id' => $export->id]);

    $this->delete(route('dashboard.export.revoke', $export))
        ->assertRedirect(route('dashboard'));

    $this->assertDatabaseMissing('dashboard_exports', ['id' => $export->id]);
});

it('the export creator can revoke their own export', function () {
    $export = makeExport($this->dashboard, $this->editor);

    $this->delete(route('dashboard.export.revoke', $export))
        ->assertRedirect(route('dashboard'));

    expect(DashboardExport::find($export->id))->toBeNull();
});

it('another editor cannot revoke someone else export', function () {
    $export = makeExport($this->dashboard, $this->editor);

    $otherEditor = createExportUser('editor', $this);
    actAsExportUser($otherEditor, $this);

    $this->delete(route('dashboard.export.revoke', $export))
        ->assertForbidden();

    $this->assertDatabaseHas('dashboard_exports', ['id' => $export->id]);
});

it('admin can revoke any export', function () {
    $export = makeExport($this->dashboard, $this->editor);

    $admin = createExportUser('admin', $this);
    actAsExportUser($admin, $this);

    $this->delete(route('dashboard.export.revoke', $export))
        ->assertRedirect(route('dashboard'));

    $this->assertDatabaseMissing('dashboard_exports', ['id' => $export->id]);
});
