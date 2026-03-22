# Customizable Dashboard Widget System — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the hardcoded dashboard with a customizable widget-based system supporting drag-and-drop layout, multiple named dashboards per workspace, role-based permissions, starter templates, snapshots, and export/import sharing.

**Architecture:** Livewire Component Per Widget. Each widget is a self-contained Livewire component mounted inside a GridStack.js grid via Alpine.js bridge. Data flows through a new WidgetDataService that queries existing summary tables via the DataSourceRegistry. The dashboard page is a Volt component managing dashboard CRUD, edit mode, and user preferences.

**Tech Stack:** Laravel 13, Livewire 4, Volt, Flux UI v2, Alpine.js, GridStack.js, Chart.js v4, Tailwind CSS v4, Pest 4, Spatie Permission (teams mode)

**Spec:** `docs/superpowers/specs/2026-03-22-customizable-dashboard-design.md`

**Important — Role Mapping:** The spec references "member" role. In this codebase, the equivalent role is `editor` with the `integrate` permission. The permission mapping is:
- View/switch dashboards: `view` permission (all roles)
- Create/edit dashboards: `integrate` permission (editor+)
- Delete/lock dashboards: `manage` permission (admin+)

---

## File Structure

### New Files

```
app/
├── Console/Commands/
│   └── PruneDashboardSnapshots.php          — Artisan command for snapshot retention
├── Dashboard/
│   ├── WidgetRegistry.php                   — Widget type definitions + constraints
│   └── DataSourceRegistry.php               — Data source definitions + measure metadata
├── Livewire/Dashboard/
│   ├── DashboardGrid.php                    — GridStack bridge, layout save, snapshot management
│   ├── WidgetConfigPanel.php                — Slide-over for widget add/edit
│   └── Widgets/
│       ├── BaseWidget.php                   — Abstract base with date filter + config listeners
│       ├── SingleMetric.php                 — Big number + trend
│       ├── LineChart.php                    — Time series via Chart.js
│       ├── BarChart.php                     — Bar/horizontal/stacked via Chart.js
│       ├── PieChart.php                     — Pie/donut via Chart.js
│       ├── DataTable.php                    — Sortable table
│       └── StatRow.php                      — Row of 3-5 metrics
├── Models/
│   ├── Dashboard.php                        — Dashboard model with relationships + scopes
│   ├── DashboardWidget.php                  — Widget model with JSON config cast
│   ├── DashboardSnapshot.php                — Snapshot model
│   ├── DashboardExport.php                  — Export link model
│   └── UserDashboardPreference.php          — Per-user active dashboard selection
└── Services/Dashboard/
    └── WidgetDataService.php                — Generic data fetcher for widgets

resources/views/
├── livewire/dashboard/
│   ├── dashboard-grid.blade.php             — GridStack grid + Alpine bridge
│   ├── widget-config-panel.blade.php        — Config panel UI
│   └── widgets/
│       ├── single-metric.blade.php
│       ├── line-chart.blade.php
│       ├── bar-chart.blade.php
│       ├── pie-chart.blade.php
│       ├── data-table.blade.php
│       └── stat-row.blade.php
└── pages/
    └── dashboard/
        └── import.blade.php                 — Export/import preview page

resources/js/
└── dashboard-grid.js                        — Alpine component for GridStack bridge

database/
├── migrations/
│   ├── xxxx_create_dashboards_table.php
│   ├── xxxx_create_dashboard_widgets_table.php
│   ├── xxxx_create_dashboard_snapshots_table.php
│   ├── xxxx_create_dashboard_exports_table.php
│   └── xxxx_create_user_dashboard_preferences_table.php
└── seeders/
    └── DashboardTemplateSeeder.php

tests/
├── Feature/Dashboard/
│   ├── DashboardCrudTest.php
│   ├── DashboardWidgetCrudTest.php
│   ├── DashboardPermissionsTest.php
│   ├── DashboardLockingTest.php
│   ├── DashboardSnapshotTest.php
│   ├── DashboardExportImportTest.php
│   ├── DashboardTemplateTest.php
│   ├── UserDashboardPreferenceTest.php
│   └── WidgetDataServiceTest.php
├── Unit/Dashboard/
│   ├── WidgetRegistryTest.php
│   └── DataSourceRegistryTest.php
└── Browser/Dashboard/
    └── DashboardGridTest.php
```

### Modified Files

```
resources/views/pages/dashboard.blade.php    — Rewrite as customizable dashboard Volt page
routes/web.php                               — Add dashboard CRUD + export/import routes
routes/console.php                           — Register snapshot pruning schedule
package.json                                 — Add gridstack dependency
resources/js/app.js                          — Import gridstack + dashboard-grid Alpine component
vite.config.js                               — Possibly add gridstack CSS
```

---

## Task 1: Database Migrations & Models

**Files:**
- Create: `database/migrations/xxxx_create_dashboards_table.php`
- Create: `database/migrations/xxxx_create_dashboard_widgets_table.php`
- Create: `database/migrations/xxxx_create_dashboard_snapshots_table.php`
- Create: `database/migrations/xxxx_create_dashboard_exports_table.php`
- Create: `database/migrations/xxxx_create_user_dashboard_preferences_table.php`
- Create: `app/Models/Dashboard.php`
- Create: `app/Models/DashboardWidget.php`
- Create: `app/Models/DashboardSnapshot.php`
- Create: `app/Models/DashboardExport.php`
- Create: `app/Models/UserDashboardPreference.php`
- Modify: `app/Models/Workspace.php` (add `dashboards()` relationship)
- Test: `tests/Feature/Dashboard/DashboardCrudTest.php`

**Reference:** Check existing migration pattern in `database/migrations/` and model pattern in `app/Models/Workspace.php`.

- [ ] **Step 1: Create dashboards migration**

```bash
php artisan make:migration create_dashboards_table --no-interaction
```

Edit the migration:

```php
public function up(): void
{
    Schema::create('dashboards', function (Blueprint $table) {
        $table->id();
        $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();
        $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
        $table->string('name', 100);
        $table->string('description', 255)->nullable();
        $table->boolean('is_template')->default(false);
        $table->string('template_slug')->nullable();
        $table->boolean('is_locked')->default(false);
        $table->timestamps();

        $table->index(['workspace_id', 'is_template']);
    });
}
```

- [ ] **Step 2: Create dashboard_widgets migration**

```bash
php artisan make:migration create_dashboard_widgets_table --no-interaction
```

```php
public function up(): void
{
    Schema::create('dashboard_widgets', function (Blueprint $table) {
        $table->id();
        $table->foreignId('dashboard_id')->constrained()->cascadeOnDelete();
        $table->string('widget_type', 50);
        $table->tinyInteger('grid_x')->unsigned();
        $table->smallInteger('grid_y')->unsigned();
        $table->tinyInteger('grid_w')->unsigned();
        $table->tinyInteger('grid_h')->unsigned();
        $table->json('config');
        $table->smallInteger('sort_order')->unsigned()->default(0);
        $table->timestamps();

        $table->index('dashboard_id');
    });
}
```

- [ ] **Step 3: Create dashboard_snapshots migration**

```bash
php artisan make:migration create_dashboard_snapshots_table --no-interaction
```

```php
public function up(): void
{
    Schema::create('dashboard_snapshots', function (Blueprint $table) {
        $table->id();
        $table->foreignId('dashboard_id')->constrained()->cascadeOnDelete();
        $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
        $table->json('layout');
        $table->tinyInteger('widget_count')->unsigned();
        $table->timestamp('created_at');

        $table->index(['dashboard_id', 'created_at']);
    });
}
```

- [ ] **Step 4: Create dashboard_exports migration**

```bash
php artisan make:migration create_dashboard_exports_table --no-interaction
```

```php
public function up(): void
{
    Schema::create('dashboard_exports', function (Blueprint $table) {
        $table->id();
        $table->foreignId('dashboard_id')->constrained()->cascadeOnDelete();
        $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
        $table->string('token', 64)->unique();
        $table->json('layout');
        $table->string('name', 100);
        $table->string('description', 255)->nullable();
        $table->tinyInteger('widget_count')->unsigned();
        $table->timestamp('expires_at')->nullable();
        $table->timestamp('created_at');
    });
}
```

- [ ] **Step 5: Create user_dashboard_preferences migration**

```bash
php artisan make:migration create_user_dashboard_preferences_table --no-interaction
```

```php
public function up(): void
{
    Schema::create('user_dashboard_preferences', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
        $table->foreignId('active_dashboard_id')->nullable()->constrained('dashboards')->nullOnDelete();
        $table->timestamps();

        $table->unique(['user_id', 'workspace_id']);
    });
}
```

- [ ] **Step 6: Run migrations**

```bash
php artisan migrate --no-interaction
```

Expected: All 5 tables created successfully.

- [ ] **Step 7: Create Dashboard model**

```bash
php artisan make:model Dashboard --no-interaction
```

Edit `app/Models/Dashboard.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dashboard extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'created_by',
        'name',
        'description',
        'is_template',
        'template_slug',
        'is_locked',
    ];

    protected $casts = [
        'is_template' => 'boolean',
        'is_locked' => 'boolean',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function widgets(): HasMany
    {
        return $this->hasMany(DashboardWidget::class)->orderBy('sort_order');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(DashboardSnapshot::class)->orderByDesc('created_at');
    }

    public function exports(): HasMany
    {
        return $this->hasMany(DashboardExport::class);
    }

    public function scopeForWorkspace($query, int $workspaceId)
    {
        return $query->where('workspace_id', $workspaceId);
    }

    public function scopeTemplates($query)
    {
        return $query->where('is_template', true);
    }

    public function scopeNotTemplates($query)
    {
        return $query->where('is_template', false);
    }

    public function cloneToWorkspace(int $workspaceId, int $userId): self
    {
        $clone = $this->replicate(['is_template', 'template_slug', 'is_locked']);
        $clone->workspace_id = $workspaceId;
        $clone->created_by = $userId;
        $clone->is_template = false;
        $clone->template_slug = null;
        $clone->is_locked = false;
        $clone->save();

        foreach ($this->widgets as $widget) {
            $widgetClone = $widget->replicate();
            $widgetClone->dashboard_id = $clone->id;
            $widgetClone->save();
        }

        return $clone;
    }
}
```

- [ ] **Step 8: Create DashboardWidget model**

```bash
php artisan make:model DashboardWidget --no-interaction
```

Edit `app/Models/DashboardWidget.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DashboardWidget extends Model
{
    protected $fillable = [
        'dashboard_id',
        'widget_type',
        'grid_x',
        'grid_y',
        'grid_w',
        'grid_h',
        'config',
        'sort_order',
    ];

    protected $casts = [
        'config' => 'array',
        'grid_x' => 'integer',
        'grid_y' => 'integer',
        'grid_w' => 'integer',
        'grid_h' => 'integer',
        'sort_order' => 'integer',
    ];

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }
}
```

- [ ] **Step 9: Create DashboardSnapshot model**

```bash
php artisan make:model DashboardSnapshot --no-interaction
```

Edit `app/Models/DashboardSnapshot.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DashboardSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'dashboard_id',
        'created_by',
        'layout',
        'widget_count',
        'created_at',
    ];

    protected $casts = [
        'layout' => 'array',
        'widget_count' => 'integer',
        'created_at' => 'datetime',
    ];

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
```

- [ ] **Step 10: Create DashboardExport model**

```bash
php artisan make:model DashboardExport --no-interaction
```

Edit `app/Models/DashboardExport.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DashboardExport extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'dashboard_id',
        'created_by',
        'token',
        'layout',
        'name',
        'description',
        'widget_count',
        'expires_at',
        'created_at',
    ];

    protected $casts = [
        'layout' => 'array',
        'widget_count' => 'integer',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeValid($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
              ->orWhere('expires_at', '>', now());
        });
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public static function generateToken(): string
    {
        return Str::random(64);
    }
}
```

- [ ] **Step 11: Create UserDashboardPreference model**

```bash
php artisan make:model UserDashboardPreference --no-interaction
```

Edit `app/Models/UserDashboardPreference.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDashboardPreference extends Model
{
    protected $fillable = [
        'user_id',
        'workspace_id',
        'active_dashboard_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function activeDashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class, 'active_dashboard_id');
    }
}
```

- [ ] **Step 12: Add dashboards relationship to Workspace model**

In `app/Models/Workspace.php`, add:

```php
use App\Models\Dashboard;

public function dashboards(): HasMany
{
    return $this->hasMany(Dashboard::class)->notTemplates();
}
```

- [ ] **Step 13: Write model relationship tests**

Create `tests/Feature/Dashboard/DashboardCrudTest.php`:

```bash
php artisan make:test Dashboard/DashboardCrudTest --pest --no-interaction
```

```php
<?php

use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\DashboardSnapshot;
use App\Models\DashboardExport;
use App\Models\UserDashboardPreference;
use App\Models\User;
use App\Models\Workspace;
use App\Models\Organization;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->organization = Organization::factory()->create();
    $this->workspace = Workspace::factory()->create(['organization_id' => $this->organization->id]);
    $this->user = User::factory()->create(['current_organization_id' => $this->organization->id]);
    $this->user->assignRole('owner');
    setPermissionsTeamId($this->organization->id);
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
    ]))->toThrow(\Illuminate\Database\QueryException::class);
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
```

- [ ] **Step 14: Run tests to verify models work**

```bash
php artisan test --compact --filter=DashboardCrudTest
```

Expected: All tests pass.

- [ ] **Step 15: Run Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: add dashboard migrations and models (dashboards, widgets, snapshots, exports, preferences)"
```

---

## Task 2: Widget Registry & Data Source Registry

**Files:**
- Create: `app/Dashboard/WidgetRegistry.php`
- Create: `app/Dashboard/DataSourceRegistry.php`
- Test: `tests/Unit/Dashboard/WidgetRegistryTest.php`
- Test: `tests/Unit/Dashboard/DataSourceRegistryTest.php`

- [ ] **Step 1: Create WidgetRegistry**

Create `app/Dashboard/WidgetRegistry.php`:

```php
<?php

namespace App\Dashboard;

class WidgetRegistry
{
    protected static array $widgets = [
        'single_metric' => [
            'name' => 'Single Metric',
            'description' => 'Big number with trend indicator',
            'icon' => 'hashtag',
            'category' => 'Key Metrics',
            'default_w' => 3, 'default_h' => 2,
            'min_w' => 2, 'min_h' => 2, 'max_w' => 6, 'max_h' => 3,
            'component' => 'dashboard.widgets.single-metric',
            'supported_visualizations' => ['single_metric'],
        ],
        'stat_row' => [
            'name' => 'Stat Row',
            'description' => 'Row of 3-5 key metrics with trends',
            'icon' => 'squares-2x2',
            'category' => 'Key Metrics',
            'default_w' => 12, 'default_h' => 2,
            'min_w' => 6, 'min_h' => 2, 'max_w' => 12, 'max_h' => 3,
            'component' => 'dashboard.widgets.stat-row',
            'supported_visualizations' => ['stat_row'],
        ],
        'line_chart' => [
            'name' => 'Line Chart',
            'description' => 'Trend over time',
            'icon' => 'trending-up',
            'category' => 'Charts',
            'default_w' => 6, 'default_h' => 4,
            'min_w' => 4, 'min_h' => 3, 'max_w' => 12, 'max_h' => 8,
            'component' => 'dashboard.widgets.line-chart',
            'supported_visualizations' => ['line_chart', 'area_chart'],
        ],
        'bar_chart' => [
            'name' => 'Bar Chart',
            'description' => 'Compare values across categories',
            'icon' => 'chart-bar',
            'category' => 'Charts',
            'default_w' => 6, 'default_h' => 4,
            'min_w' => 3, 'min_h' => 3, 'max_w' => 12, 'max_h' => 8,
            'component' => 'dashboard.widgets.bar-chart',
            'supported_visualizations' => ['bar_chart', 'horizontal_bar', 'stacked_bar'],
        ],
        'pie_chart' => [
            'name' => 'Pie / Donut Chart',
            'description' => 'Show proportions',
            'icon' => 'chart-pie',
            'category' => 'Charts',
            'default_w' => 4, 'default_h' => 4,
            'min_w' => 3, 'min_h' => 3, 'max_w' => 6, 'max_h' => 6,
            'component' => 'dashboard.widgets.pie-chart',
            'supported_visualizations' => ['pie_chart', 'donut_chart'],
        ],
        'table' => [
            'name' => 'Data Table',
            'description' => 'Sortable tabular breakdown',
            'icon' => 'table',
            'category' => 'Data',
            'default_w' => 6, 'default_h' => 5,
            'min_w' => 4, 'min_h' => 3, 'max_w' => 12, 'max_h' => 10,
            'component' => 'dashboard.widgets.data-table',
            'supported_visualizations' => ['table'],
        ],
    ];

    public static function all(): array
    {
        return static::$widgets;
    }

    public static function get(string $type): ?array
    {
        return static::$widgets[$type] ?? null;
    }

    public static function exists(string $type): bool
    {
        return isset(static::$widgets[$type]);
    }

    public static function types(): array
    {
        return array_keys(static::$widgets);
    }

    public static function byCategory(): array
    {
        $grouped = [];
        foreach (static::$widgets as $type => $widget) {
            $grouped[$widget['category']][$type] = $widget;
        }

        return $grouped;
    }

    public static function defaultsFor(string $type): array
    {
        $widget = static::get($type);
        if (! $widget) {
            return [];
        }

        return [
            'w' => $widget['default_w'],
            'h' => $widget['default_h'],
            'min_w' => $widget['min_w'],
            'min_h' => $widget['min_h'],
            'max_w' => $widget['max_w'],
            'max_h' => $widget['max_h'],
        ];
    }
}
```

- [ ] **Step 2: Create DataSourceRegistry**

Create `app/Dashboard/DataSourceRegistry.php`:

```php
<?php

namespace App\Dashboard;

use App\Models\SummaryAttributionByEffort;
use App\Models\SummaryCampaignByPlatform;
use App\Models\SummaryCampaignDaily;
use App\Models\SummaryConversionDaily;

class DataSourceRegistry
{
    protected static array $sources = [
        'campaign_metrics' => [
            'name' => 'Campaign Metrics',
            'description' => 'Email send, open, and click performance',
            'summary_model' => SummaryCampaignDaily::class,
            'measures' => [
                'sent' => ['label' => 'Total Sent', 'format' => 'number'],
                'opens' => ['label' => 'Opens', 'format' => 'number'],
                'clicks' => ['label' => 'Clicks', 'format' => 'number'],
                'open_rate' => ['label' => 'Open Rate', 'format' => 'percent', 'computed' => true],
                'click_rate' => ['label' => 'Click Rate', 'format' => 'percent', 'computed' => true],
            ],
            'dimensions' => ['platform', 'campaign'],
            'supports_trend' => true,
        ],
        'conversion_metrics' => [
            'name' => 'Conversion Metrics',
            'description' => 'Conversions, revenue, and cost',
            'summary_model' => SummaryConversionDaily::class,
            'measures' => [
                'conversions' => ['label' => 'Conversions', 'format' => 'number'],
                'revenue' => ['label' => 'Revenue', 'format' => 'currency'],
                'cost' => ['label' => 'Cost', 'format' => 'currency'],
                'roas' => ['label' => 'ROAS', 'format' => 'decimal', 'computed' => true],
            ],
            'dimensions' => ['platform', 'campaign'],
            'supports_trend' => true,
        ],
        'attribution' => [
            'name' => 'Attribution',
            'description' => 'Attribution model results by effort',
            'summary_model' => SummaryAttributionByEffort::class,
            'measures' => [
                'attributed_conversions' => ['label' => 'Attributed Conversions', 'format' => 'number'],
                'attributed_revenue' => ['label' => 'Attributed Revenue', 'format' => 'currency'],
                'weight' => ['label' => 'Weight', 'format' => 'decimal'],
            ],
            'dimensions' => ['effort', 'model'],
            'supports_trend' => true,
            'extra_config' => [
                'attribution_model' => [
                    'type' => 'select',
                    'options' => ['first_touch', 'last_touch', 'linear'],
                    'default' => 'first_touch',
                ],
            ],
        ],
        'platform_breakdown' => [
            'name' => 'Platform Breakdown',
            'description' => 'Metrics split by platform',
            'summary_model' => SummaryCampaignByPlatform::class,
            'measures' => [
                'sent' => ['label' => 'Total Sent', 'format' => 'number'],
                'opens' => ['label' => 'Opens', 'format' => 'number'],
                'clicks' => ['label' => 'Clicks', 'format' => 'number'],
                'revenue' => ['label' => 'Revenue', 'format' => 'currency'],
            ],
            'dimensions' => ['platform'],
            'supports_trend' => true,
        ],
    ];

    public static function all(): array
    {
        return static::$sources;
    }

    public static function get(string $key): ?array
    {
        return static::$sources[$key] ?? null;
    }

    public static function exists(string $key): bool
    {
        return isset(static::$sources[$key]);
    }

    public static function keys(): array
    {
        return array_keys(static::$sources);
    }

    public static function measuresFor(string $key): array
    {
        $source = static::get($key);

        return $source ? $source['measures'] : [];
    }

    public static function dimensionsFor(string $key): array
    {
        $source = static::get($key);

        return $source ? $source['dimensions'] : [];
    }
}
```

- [ ] **Step 3: Write unit tests**

Create `tests/Unit/Dashboard/WidgetRegistryTest.php`:

```bash
php artisan make:test Dashboard/WidgetRegistryTest --unit --pest --no-interaction
```

```php
<?php

use App\Dashboard\WidgetRegistry;

it('returns all widget types', function () {
    $widgets = WidgetRegistry::all();
    expect($widgets)->toHaveKeys(['single_metric', 'stat_row', 'line_chart', 'bar_chart', 'pie_chart', 'table']);
});

it('returns null for unknown widget type', function () {
    expect(WidgetRegistry::get('nonexistent'))->toBeNull();
    expect(WidgetRegistry::exists('nonexistent'))->toBeFalse();
});

it('groups widgets by category', function () {
    $grouped = WidgetRegistry::byCategory();
    expect($grouped)->toHaveKeys(['Key Metrics', 'Charts', 'Data']);
    expect($grouped['Key Metrics'])->toHaveKeys(['single_metric', 'stat_row']);
    expect($grouped['Charts'])->toHaveKeys(['line_chart', 'bar_chart', 'pie_chart']);
    expect($grouped['Data'])->toHaveKeys(['table']);
});

it('returns default grid dimensions for a widget type', function () {
    $defaults = WidgetRegistry::defaultsFor('single_metric');
    expect($defaults['w'])->toBe(3);
    expect($defaults['h'])->toBe(2);
    expect($defaults['min_w'])->toBe(2);
});

it('all widget types have required keys', function () {
    $requiredKeys = ['name', 'description', 'icon', 'category', 'default_w', 'default_h', 'min_w', 'min_h', 'max_w', 'max_h', 'component', 'supported_visualizations'];
    foreach (WidgetRegistry::all() as $type => $widget) {
        foreach ($requiredKeys as $key) {
            expect($widget)->toHaveKey($key, "Widget '{$type}' missing key '{$key}'");
        }
    }
});
```

Create `tests/Unit/Dashboard/DataSourceRegistryTest.php`:

```bash
php artisan make:test Dashboard/DataSourceRegistryTest --unit --pest --no-interaction
```

```php
<?php

use App\Dashboard\DataSourceRegistry;

it('returns all data sources', function () {
    $sources = DataSourceRegistry::all();
    expect($sources)->toHaveKeys(['campaign_metrics', 'conversion_metrics', 'attribution', 'platform_breakdown']);
});

it('returns measures for a data source', function () {
    $measures = DataSourceRegistry::measuresFor('conversion_metrics');
    expect($measures)->toHaveKeys(['conversions', 'revenue', 'cost', 'roas']);
    expect($measures['revenue']['format'])->toBe('currency');
});

it('returns dimensions for a data source', function () {
    $dimensions = DataSourceRegistry::dimensionsFor('campaign_metrics');
    expect($dimensions)->toBe(['platform', 'campaign']);
});

it('all data sources reference valid model classes', function () {
    foreach (DataSourceRegistry::all() as $key => $source) {
        expect(class_exists($source['summary_model']))
            ->toBeTrue("Data source '{$key}' references non-existent model '{$source['summary_model']}'");
    }
});

it('all measures have label and format', function () {
    foreach (DataSourceRegistry::all() as $key => $source) {
        foreach ($source['measures'] as $measure => $meta) {
            expect($meta)->toHaveKeys(['label', 'format'], "Measure '{$measure}' in source '{$key}' missing required keys");
        }
    }
});
```

- [ ] **Step 4: Run tests**

```bash
php artisan test --compact --filter=WidgetRegistryTest && php artisan test --compact --filter=DataSourceRegistryTest
```

Expected: All tests pass.

- [ ] **Step 5: Run Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: add WidgetRegistry and DataSourceRegistry with unit tests"
```

---

## Task 3: WidgetDataService

**Files:**
- Create: `app/Services/Dashboard/WidgetDataService.php`
- Test: `tests/Feature/Dashboard/WidgetDataServiceTest.php`

**Reference:** Read `app/Services/Dashboard/MetricsService.php` for the workspace-scoping pattern and summary table query conventions.

- [ ] **Step 1: Write failing tests**

Create `tests/Feature/Dashboard/WidgetDataServiceTest.php`:

```bash
php artisan make:test Dashboard/WidgetDataServiceTest --pest --no-interaction
```

```php
<?php

use App\Models\Organization;
use App\Models\Workspace;
use App\Models\SummaryCampaignDaily;
use App\Models\SummaryConversionDaily;
use App\Services\Dashboard\WidgetDataService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->organization = Organization::factory()->create();
    $this->workspace = Workspace::factory()->create(['organization_id' => $this->organization->id]);

    // Seed summary data for testing
    $today = Carbon::today();
    for ($i = 0; $i < 7; $i++) {
        $date = $today->copy()->subDays($i);

        SummaryCampaignDaily::create([
            'workspace_id' => $this->workspace->id,
            'summary_date' => $date->toDateString(),
            'sent' => 1000 + ($i * 100),
            'opens' => 300 + ($i * 30),
            'clicks' => 50 + ($i * 5),
        ]);

        SummaryConversionDaily::create([
            'workspace_id' => $this->workspace->id,
            'summary_date' => $date->toDateString(),
            'conversions' => 10 + $i,
            'revenue' => 1000 + ($i * 100),
            'cost' => 200 + ($i * 20),
        ]);
    }

    $this->service = WidgetDataService::forWorkspace($this->workspace->id);
});

it('fetches single metric data with trend', function () {
    $config = [
        'data_source' => 'conversion_metrics',
        'measure' => 'revenue',
    ];

    $result = $this->service->fetchMetric($config, Carbon::today()->subDays(6), Carbon::today());

    expect($result)->toHaveKeys(['value', 'format']);
    expect($result['value'])->toBeGreaterThan(0);
    expect($result['format'])->toBe('currency');
});

it('fetches chart data with labels and datasets', function () {
    $config = [
        'data_source' => 'campaign_metrics',
        'measure' => 'sent',
    ];

    $result = $this->service->fetchTrend($config, Carbon::today()->subDays(6), Carbon::today());

    expect($result)->toHaveKeys(['labels', 'datasets']);
    expect($result['labels'])->toHaveCount(7);
    expect($result['datasets'])->toHaveCount(1);
    expect($result['datasets'][0])->toHaveKeys(['label', 'data']);
});

it('fetches grouped data for bar charts', function () {
    $config = [
        'data_source' => 'campaign_metrics',
        'measure' => 'sent',
        'group_by' => null, // No grouping, daily aggregation
        'limit' => 5,
    ];

    $result = $this->service->fetchGrouped($config, Carbon::today()->subDays(6), Carbon::today());

    expect($result)->toHaveKeys(['labels', 'datasets']);
    expect(count($result['labels']))->toBeLessThanOrEqual(5);
});

it('fetches table data with columns and rows', function () {
    $config = [
        'data_source' => 'campaign_metrics',
        'limit' => 10,
    ];

    $result = $this->service->fetchTable($config, Carbon::today()->subDays(6), Carbon::today());

    expect($result)->toHaveKeys(['columns', 'rows', 'totals']);
    expect($result['columns'])->toBeArray();
    expect($result['rows'])->toBeArray();
});

it('computes derived measures correctly', function () {
    $config = [
        'data_source' => 'campaign_metrics',
        'measure' => 'open_rate',
    ];

    $result = $this->service->fetchMetric($config, Carbon::today()->subDays(6), Carbon::today());

    expect($result['format'])->toBe('percent');
    expect($result['value'])->toBeGreaterThan(0);
    expect($result['value'])->toBeLessThanOrEqual(100);
});

it('scopes data to workspace only', function () {
    $otherWorkspace = Workspace::factory()->create(['organization_id' => $this->organization->id]);

    SummaryConversionDaily::create([
        'workspace_id' => $otherWorkspace->id,
        'summary_date' => Carbon::today()->toDateString(),
        'conversions' => 9999,
        'revenue' => 999999,
        'cost' => 0,
    ]);

    $config = [
        'data_source' => 'conversion_metrics',
        'measure' => 'revenue',
    ];

    $result = $this->service->fetchMetric($config, Carbon::today()->subDays(6), Carbon::today());

    // Should NOT include the other workspace's $999,999
    expect($result['value'])->toBeLessThan(999999);
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact --filter=WidgetDataServiceTest
```

Expected: FAIL — `WidgetDataService` class not found.

- [ ] **Step 3: Implement WidgetDataService**

Create `app/Services/Dashboard/WidgetDataService.php`:

```php
<?php

namespace App\Services\Dashboard;

use App\Dashboard\DataSourceRegistry;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class WidgetDataService
{
    protected int $workspaceId;

    public function __construct(int $workspaceId)
    {
        $this->workspaceId = $workspaceId;
    }

    public static function forWorkspace(int $workspaceId): self
    {
        return new self($workspaceId);
    }

    public function fetchMetric(array $config, Carbon $start, Carbon $end): array
    {
        $source = DataSourceRegistry::get($config['data_source']);
        if (! $source) {
            return ['value' => 0, 'format' => 'number'];
        }

        $measure = $config['measure'];
        $measureMeta = $source['measures'][$measure] ?? null;
        if (! $measureMeta) {
            return ['value' => 0, 'format' => 'number'];
        }

        $model = $source['summary_model'];
        $query = $model::query()
            ->where('workspace_id', $this->workspaceId)
            ->whereBetween('summary_date', [$start->toDateString(), $end->toDateString()]);

        if (! empty($measureMeta['computed'])) {
            $value = $this->computeDerivedMeasure($measure, $query);
        } else {
            $value = (float) $query->sum($measure);
        }

        $result = [
            'value' => $value,
            'format' => $measureMeta['format'],
        ];

        // Calculate previous period for trend
        $periodDays = $start->diffInDays($end) + 1;
        $prevEnd = $start->copy()->subDay();
        $prevStart = $prevEnd->copy()->subDays($periodDays - 1);

        $prevQuery = $model::query()
            ->where('workspace_id', $this->workspaceId)
            ->whereBetween('summary_date', [$prevStart->toDateString(), $prevEnd->toDateString()]);

        if (! empty($measureMeta['computed'])) {
            $previous = $this->computeDerivedMeasure($measure, $prevQuery);
        } else {
            $previous = (float) $prevQuery->sum($measure);
        }

        $result['previous'] = $previous;
        $result['change'] = $previous > 0 ? round((($value - $previous) / $previous) * 100, 2) : 0;

        return $result;
    }

    public function fetchTrend(array $config, Carbon $start, Carbon $end): array
    {
        $source = DataSourceRegistry::get($config['data_source']);
        if (! $source) {
            return ['labels' => [], 'datasets' => []];
        }

        $measure = $config['measure'];
        $measureMeta = $source['measures'][$measure] ?? null;
        if (! $measureMeta) {
            return ['labels' => [], 'datasets' => []];
        }

        $model = $source['summary_model'];

        if (! empty($measureMeta['computed'])) {
            $rows = $this->fetchTrendForComputed($measure, $model, $start, $end);
        } else {
            $rows = $model::query()
                ->where('workspace_id', $this->workspaceId)
                ->whereBetween('summary_date', [$start->toDateString(), $end->toDateString()])
                ->selectRaw('summary_date, SUM(' . $measure . ') as value')
                ->groupBy('summary_date')
                ->orderBy('summary_date')
                ->get()
                ->keyBy('summary_date');
        }

        $labels = [];
        $data = [];
        $period = CarbonPeriod::create($start, $end);
        foreach ($period as $date) {
            $dateStr = $date->toDateString();
            $labels[] = $date->format('M j');
            $data[] = isset($rows[$dateStr]) ? (float) $rows[$dateStr]->value : 0;
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => $measureMeta['label'],
                    'data' => $data,
                ],
            ],
        ];
    }

    public function fetchGrouped(array $config, Carbon $start, Carbon $end): array
    {
        $source = DataSourceRegistry::get($config['data_source']);
        if (! $source) {
            return ['labels' => [], 'datasets' => []];
        }

        $measure = $config['measure'] ?? array_key_first($source['measures']);
        $measureMeta = $source['measures'][$measure] ?? null;
        $groupBy = $config['group_by'] ?? null;
        $limit = $config['limit'] ?? 10;

        $model = $source['summary_model'];
        $query = $model::query()
            ->where('workspace_id', $this->workspaceId)
            ->whereBetween('summary_date', [$start->toDateString(), $end->toDateString()]);

        $this->applyFilters($query, $config['filters'] ?? []);

        if ($groupBy && in_array($groupBy, $source['dimensions'])) {
            $query->selectRaw($groupBy . ', SUM(' . $measure . ') as value')
                ->groupBy($groupBy)
                ->orderByDesc('value')
                ->limit($limit);

            $rows = $query->get();
            $labels = $rows->pluck($groupBy)->toArray();
            $data = $rows->pluck('value')->map(fn ($v) => (float) $v)->toArray();
        } else {
            // Group by date
            $query->selectRaw('summary_date, SUM(' . $measure . ') as value')
                ->groupBy('summary_date')
                ->orderBy('summary_date')
                ->limit($limit);

            $rows = $query->get();
            $labels = $rows->pluck('summary_date')->map(fn ($d) => Carbon::parse($d)->format('M j'))->toArray();
            $data = $rows->pluck('value')->map(fn ($v) => (float) $v)->toArray();
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => $measureMeta['label'] ?? $measure,
                    'data' => $data,
                ],
            ],
        ];
    }

    public function fetchTable(array $config, Carbon $start, Carbon $end): array
    {
        $source = DataSourceRegistry::get($config['data_source']);
        if (! $source) {
            return ['columns' => [], 'rows' => [], 'totals' => []];
        }

        $limit = $config['limit'] ?? 25;
        $model = $source['summary_model'];

        $query = $model::query()
            ->where('workspace_id', $this->workspaceId)
            ->whereBetween('summary_date', [$start->toDateString(), $end->toDateString()]);

        $this->applyFilters($query, $config['filters'] ?? []);

        // Build select for non-computed measures
        $selectParts = ['summary_date'];
        $directMeasures = [];
        foreach ($source['measures'] as $key => $meta) {
            if (empty($meta['computed'])) {
                $selectParts[] = $key;
                $directMeasures[$key] = $meta;
            }
        }

        $rows = $query->select($selectParts)
            ->orderByDesc('summary_date')
            ->limit($limit)
            ->get()
            ->toArray();

        $columns = [['key' => 'summary_date', 'label' => 'Date', 'format' => 'date']];
        foreach ($directMeasures as $key => $meta) {
            $columns[] = ['key' => $key, 'label' => $meta['label'], 'format' => $meta['format']];
        }

        // Calculate totals
        $totals = ['summary_date' => 'Total'];
        foreach ($directMeasures as $key => $meta) {
            $totals[$key] = array_sum(array_column($rows, $key));
        }

        return [
            'columns' => $columns,
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    protected function applyFilters($query, array $filters): void
    {
        foreach ($filters as $filter) {
            $dimension = $filter['dimension'] ?? null;
            $operator = $filter['operator'] ?? 'in';
            $values = $filter['values'] ?? [];

            if (! $dimension || empty($values)) {
                continue;
            }

            match ($operator) {
                'in' => $query->whereIn($dimension, $values),
                'not_in' => $query->whereNotIn($dimension, $values),
                'equals' => $query->where($dimension, $values[0] ?? null),
                default => null,
            };
        }
    }

    protected function computeDerivedMeasure(string $measure, $query): float
    {
        return match ($measure) {
            'open_rate' => $this->computeRate($query, 'opens', 'sent'),
            'click_rate' => $this->computeRate($query, 'clicks', 'sent'),
            'roas' => $this->computeRatio($query, 'revenue', 'cost'),
            default => 0,
        };
    }

    protected function computeRate($query, string $numerator, string $denominator): float
    {
        $result = $query->selectRaw("SUM({$numerator}) as num, SUM({$denominator}) as denom")->first();
        if (! $result || $result->denom == 0) {
            return 0;
        }

        return round(($result->num / $result->denom) * 100, 2);
    }

    protected function computeRatio($query, string $numerator, string $denominator): float
    {
        $result = $query->selectRaw("SUM({$numerator}) as num, SUM({$denominator}) as denom")->first();
        if (! $result || $result->denom == 0) {
            return 0;
        }

        return round($result->num / $result->denom, 2);
    }

    protected function fetchTrendForComputed(string $measure, string $model, Carbon $start, Carbon $end)
    {
        [$numerator, $denominator, $multiplier] = match ($measure) {
            'open_rate' => ['opens', 'sent', 100],
            'click_rate' => ['clicks', 'sent', 100],
            'roas' => ['revenue', 'cost', 1],
            default => [null, null, 1],
        };

        if (! $numerator) {
            return collect();
        }

        return $model::query()
            ->where('workspace_id', $this->workspaceId)
            ->whereBetween('summary_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw("summary_date, CASE WHEN SUM({$denominator}) = 0 THEN 0 ELSE ROUND(SUM({$numerator}) / SUM({$denominator}) * {$multiplier}, 2) END as value")
            ->groupBy('summary_date')
            ->orderBy('summary_date')
            ->get()
            ->keyBy('summary_date');
    }
}
```

- [ ] **Step 4: Run tests**

```bash
php artisan test --compact --filter=WidgetDataServiceTest
```

Expected: All tests pass.

- [ ] **Step 5: Run Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: add WidgetDataService for generic widget data fetching"
```

---

## Task 4: Base Widget & Widget Components

**Files:**
- Create: `app/Livewire/Dashboard/Widgets/BaseWidget.php`
- Create: `app/Livewire/Dashboard/Widgets/SingleMetric.php`
- Create: `app/Livewire/Dashboard/Widgets/StatRow.php`
- Create: `app/Livewire/Dashboard/Widgets/LineChart.php`
- Create: `app/Livewire/Dashboard/Widgets/BarChart.php`
- Create: `app/Livewire/Dashboard/Widgets/PieChart.php`
- Create: `app/Livewire/Dashboard/Widgets/DataTable.php`
- Create: All corresponding Blade views in `resources/views/livewire/dashboard/widgets/`

**Reference:** Read `app/Livewire/Dashboard/StatCards.php` and `app/Livewire/Dashboard/RevenueChart.php` for the existing `#[Lazy]`, `#[On]`, and `#[Locked]` patterns. Read their Blade views for styling conventions (dark mode, Flux UI).

- [ ] **Step 1: Create BaseWidget abstract class**

Create `app/Livewire/Dashboard/Widgets/BaseWidget.php`:

```php
<?php

namespace App\Livewire\Dashboard\Widgets;

use App\Services\Dashboard\WidgetDataService;
use Carbon\Carbon;
use Illuminate\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

#[Lazy]
abstract class BaseWidget extends Component
{
    #[Locked]
    public int $widgetId;

    #[Locked]
    public array $config;

    protected Carbon $startDate;

    protected Carbon $endDate;

    abstract protected function fetchData(): array;

    abstract public function render(): View;

    public function mount(int $widgetId, array $config): void
    {
        $this->widgetId = $widgetId;
        $this->config = $config;
        $this->initDateRange();
    }

    #[On('date-range-changed')]
    public function onDateRangeChanged(string $start, string $end): void
    {
        if (! empty($this->config['date_range_override'])) {
            return;
        }

        $this->startDate = Carbon::parse($start);
        $this->endDate = Carbon::parse($end);
    }

    #[On('widget-config-updated.{widgetId}')]
    public function onConfigUpdated(array $config): void
    {
        $this->config = $config;
    }

    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-5 animate-pulse">
            <div class="h-4 bg-slate-200 dark:bg-slate-700 rounded w-1/3 mb-4"></div>
            <div class="h-24 bg-slate-200 dark:bg-slate-700 rounded"></div>
        </div>
        HTML;
    }

    protected function getDataService(): WidgetDataService
    {
        $workspaceId = session('workspace_id');

        return WidgetDataService::forWorkspace($workspaceId);
    }

    protected function initDateRange(): void
    {
        if (! empty($this->config['date_range_override'])) {
            $override = $this->config['date_range_override'];
            $this->startDate = Carbon::parse($override['start']);
            $this->endDate = Carbon::parse($override['end']);

            return;
        }

        $sessionRange = session('dashboard_date_range', []);
        $this->startDate = Carbon::parse($sessionRange['start'] ?? now()->subDays(29)->toDateString());
        $this->endDate = Carbon::parse($sessionRange['end'] ?? now()->toDateString());
    }

    protected function getTitle(): string
    {
        return $this->config['display']['title'] ?? '';
    }

    protected function getSubtitle(): ?string
    {
        return $this->config['display']['subtitle'] ?? null;
    }
}
```

- [ ] **Step 2: Create SingleMetric widget**

Create `app/Livewire/Dashboard/Widgets/SingleMetric.php`:

```php
<?php

namespace App\Livewire\Dashboard\Widgets;

use Illuminate\View\View;

class SingleMetric extends BaseWidget
{
    protected function fetchData(): array
    {
        return $this->getDataService()->fetchMetric($this->config, $this->startDate, $this->endDate);
    }

    public function render(): View
    {
        return view('livewire.dashboard.widgets.single-metric', [
            'data' => $this->fetchData(),
            'title' => $this->getTitle(),
            'subtitle' => $this->getSubtitle(),
        ]);
    }
}
```

Create `resources/views/livewire/dashboard/widgets/single-metric.blade.php`:

```blade
<div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-5 h-full flex flex-col justify-center">
    @if($title)
        <p class="text-sm text-slate-500 dark:text-slate-400 mb-1">{{ $title }}</p>
    @endif
    <div class="text-3xl font-bold text-slate-900 dark:text-white">
        @if($data['format'] === 'currency')
            ${{ number_format($data['value'], 2) }}
        @elseif($data['format'] === 'percent')
            {{ number_format($data['value'], 1) }}%
        @else
            {{ number_format($data['value']) }}
        @endif
    </div>
    @if(isset($data['change']))
        <div class="flex items-center gap-1 mt-1">
            @if($data['change'] > 0)
                <span class="text-emerald-500 text-sm font-medium">↑ {{ number_format(abs($data['change']), 1) }}%</span>
            @elseif($data['change'] < 0)
                <span class="text-red-500 text-sm font-medium">↓ {{ number_format(abs($data['change']), 1) }}%</span>
            @else
                <span class="text-slate-400 text-sm">—</span>
            @endif
        </div>
    @endif
    @if($subtitle)
        <p class="text-xs text-slate-400 mt-1">{{ $subtitle }}</p>
    @endif
</div>
```

- [ ] **Step 3: Create StatRow widget**

Create `app/Livewire/Dashboard/Widgets/StatRow.php`. This widget displays multiple metrics in a single row. Its config should contain an array of measures under `config.measures`:

```php
<?php

namespace App\Livewire\Dashboard\Widgets;

use Illuminate\View\View;

class StatRow extends BaseWidget
{
    protected function fetchData(): array
    {
        $service = $this->getDataService();
        $measures = $this->config['measures'] ?? ['sent', 'opens', 'clicks', 'conversions', 'revenue'];
        $dataSource = $this->config['data_source'] ?? 'campaign_metrics';
        $results = [];

        foreach ($measures as $measure) {
            $results[] = $service->fetchMetric(
                ['data_source' => $dataSource, 'measure' => $measure],
                $this->startDate,
                $this->endDate
            );
        }

        return $results;
    }

    public function render(): View
    {
        return view('livewire.dashboard.widgets.stat-row', [
            'metrics' => $this->fetchData(),
            'title' => $this->getTitle(),
        ]);
    }
}
```

Create `resources/views/livewire/dashboard/widgets/stat-row.blade.php`:

```blade
<div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-5 h-full">
    @if($title)
        <p class="text-sm font-medium text-slate-500 dark:text-slate-400 mb-3">{{ $title }}</p>
    @endif
    <div class="grid grid-cols-{{ count($metrics) }} gap-4">
        @foreach($metrics as $metric)
            <div>
                <p class="text-xs text-slate-500 dark:text-slate-400 uppercase tracking-wide">{{ $metric['label'] ?? '' }}</p>
                <p class="text-xl font-bold text-slate-900 dark:text-white mt-1">
                    @if($metric['format'] === 'currency')
                        ${{ number_format($metric['value'], 2) }}
                    @elseif($metric['format'] === 'percent')
                        {{ number_format($metric['value'], 1) }}%
                    @else
                        {{ number_format($metric['value']) }}
                    @endif
                </p>
                @if(isset($metric['change']))
                    <span class="{{ $metric['change'] > 0 ? 'text-emerald-500' : ($metric['change'] < 0 ? 'text-red-500' : 'text-slate-400') }} text-xs font-medium">
                        {{ $metric['change'] > 0 ? '↑' : ($metric['change'] < 0 ? '↓' : '—') }}
                        {{ number_format(abs($metric['change']), 1) }}%
                    </span>
                @endif
            </div>
        @endforeach
    </div>
</div>
```

- [ ] **Step 4: Create LineChart widget**

Create `app/Livewire/Dashboard/Widgets/LineChart.php`:

```php
<?php

namespace App\Livewire\Dashboard\Widgets;

use Illuminate\View\View;

class LineChart extends BaseWidget
{
    protected function fetchData(): array
    {
        return $this->getDataService()->fetchTrend($this->config, $this->startDate, $this->endDate);
    }

    public function render(): View
    {
        return view('livewire.dashboard.widgets.line-chart', [
            'chartData' => $this->fetchData(),
            'title' => $this->getTitle(),
            'subtitle' => $this->getSubtitle(),
            'chartType' => $this->config['visualization']['chart_type'] ?? 'line',
            'showLegend' => $this->config['visualization']['show_legend'] ?? false,
        ]);
    }
}
```

Create `resources/views/livewire/dashboard/widgets/line-chart.blade.php`:

```blade
<div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-5 h-full flex flex-col">
    @if($title)
        <div class="mb-3">
            <h3 class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $title }}</h3>
            @if($subtitle)
                <p class="text-xs text-slate-400">{{ $subtitle }}</p>
            @endif
        </div>
    @endif
    <div class="flex-1 min-h-0" x-data="chartWidget()" x-init="initChart($el.querySelector('canvas'), @js($chartData), '{{ $chartType }}', {{ $showLegend ? 'true' : 'false' }})" @widget-resized.window="handleResize($event, {{ $widgetId }})">
        <canvas></canvas>
    </div>
</div>
```

- [ ] **Step 5: Create BarChart widget**

Create `app/Livewire/Dashboard/Widgets/BarChart.php`:

```php
<?php

namespace App\Livewire\Dashboard\Widgets;

use Illuminate\View\View;

class BarChart extends BaseWidget
{
    protected function fetchData(): array
    {
        return $this->getDataService()->fetchGrouped($this->config, $this->startDate, $this->endDate);
    }

    public function render(): View
    {
        $vizConfig = $this->config['visualization'] ?? [];
        $chartType = $vizConfig['chart_type'] ?? 'bar';

        return view('livewire.dashboard.widgets.bar-chart', [
            'chartData' => $this->fetchData(),
            'title' => $this->getTitle(),
            'subtitle' => $this->getSubtitle(),
            'chartType' => $chartType,
            'showLabels' => $vizConfig['show_labels'] ?? true,
            'showLegend' => $vizConfig['show_legend'] ?? false,
            'horizontal' => $chartType === 'horizontal_bar',
        ]);
    }
}
```

Create `resources/views/livewire/dashboard/widgets/bar-chart.blade.php`:

```blade
<div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-5 h-full flex flex-col">
    @if($title)
        <div class="mb-3">
            <h3 class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $title }}</h3>
            @if($subtitle)
                <p class="text-xs text-slate-400">{{ $subtitle }}</p>
            @endif
        </div>
    @endif
    <div class="flex-1 min-h-0" x-data="chartWidget()" x-init="initChart($el.querySelector('canvas'), @js($chartData), 'bar', {{ $showLegend ? 'true' : 'false' }}, { horizontal: {{ $horizontal ? 'true' : 'false' }} })" @widget-resized.window="handleResize($event, {{ $widgetId }})">
        <canvas></canvas>
    </div>
</div>
```

- [ ] **Step 6: Create PieChart widget**

Create `app/Livewire/Dashboard/Widgets/PieChart.php`:

```php
<?php

namespace App\Livewire\Dashboard\Widgets;

use Illuminate\View\View;

class PieChart extends BaseWidget
{
    protected function fetchData(): array
    {
        return $this->getDataService()->fetchGrouped($this->config, $this->startDate, $this->endDate);
    }

    public function render(): View
    {
        $vizConfig = $this->config['visualization'] ?? [];

        return view('livewire.dashboard.widgets.pie-chart', [
            'chartData' => $this->fetchData(),
            'title' => $this->getTitle(),
            'subtitle' => $this->getSubtitle(),
            'chartType' => $vizConfig['chart_type'] ?? 'pie',
            'showLegend' => $vizConfig['show_legend'] ?? true,
        ]);
    }
}
```

Create `resources/views/livewire/dashboard/widgets/pie-chart.blade.php`:

```blade
<div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-5 h-full flex flex-col">
    @if($title)
        <div class="mb-3">
            <h3 class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $title }}</h3>
            @if($subtitle)
                <p class="text-xs text-slate-400">{{ $subtitle }}</p>
            @endif
        </div>
    @endif
    <div class="flex-1 min-h-0" x-data="chartWidget()" x-init="initChart($el.querySelector('canvas'), @js($chartData), '{{ $chartType }}', {{ $showLegend ? 'true' : 'false' }})" @widget-resized.window="handleResize($event, {{ $widgetId }})">
        <canvas></canvas>
    </div>
</div>
```

- [ ] **Step 7: Create DataTable widget**

Create `app/Livewire/Dashboard/Widgets/DataTable.php`:

```php
<?php

namespace App\Livewire\Dashboard\Widgets;

use Illuminate\View\View;

class DataTable extends BaseWidget
{
    public string $sortBy = 'summary_date';

    public string $sortDir = 'desc';

    protected function fetchData(): array
    {
        return $this->getDataService()->fetchTable($this->config, $this->startDate, $this->endDate);
    }

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'desc';
        }
    }

    public function render(): View
    {
        $data = $this->fetchData();

        // Sort rows
        $rows = collect($data['rows'])->sortBy(
            fn ($row) => $row[$this->sortBy] ?? 0,
            SORT_REGULAR,
            $this->sortDir === 'desc'
        )->values()->toArray();

        return view('livewire.dashboard.widgets.data-table', [
            'columns' => $data['columns'],
            'rows' => $rows,
            'totals' => $data['totals'],
            'title' => $this->getTitle(),
            'subtitle' => $this->getSubtitle(),
            'sortBy' => $this->sortBy,
            'sortDir' => $this->sortDir,
        ]);
    }
}
```

Create `resources/views/livewire/dashboard/widgets/data-table.blade.php`:

```blade
<div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-5 h-full flex flex-col">
    @if($title)
        <div class="mb-3">
            <h3 class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $title }}</h3>
            @if($subtitle)
                <p class="text-xs text-slate-400">{{ $subtitle }}</p>
            @endif
        </div>
    @endif
    <div class="flex-1 overflow-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-200 dark:border-slate-700">
                    @foreach($columns as $col)
                        <th class="text-left py-2 px-2 text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wide cursor-pointer hover:text-slate-700 dark:hover:text-slate-200" wire:click="sort('{{ $col['key'] }}')">
                            {{ $col['label'] }}
                            @if($sortBy === $col['key'])
                                <span>{{ $sortDir === 'asc' ? '↑' : '↓' }}</span>
                            @endif
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                    <tr class="border-b border-slate-100 dark:border-slate-700/50">
                        @foreach($columns as $col)
                            <td class="py-2 px-2 text-slate-700 dark:text-slate-300">
                                @if($col['format'] === 'currency')
                                    ${{ number_format($row[$col['key']] ?? 0, 2) }}
                                @elseif($col['format'] === 'percent')
                                    {{ number_format($row[$col['key']] ?? 0, 1) }}%
                                @elseif($col['format'] === 'date')
                                    {{ $row[$col['key']] ?? '' }}
                                @else
                                    {{ number_format($row[$col['key']] ?? 0) }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
            @if(!empty($totals))
                <tfoot>
                    <tr class="border-t-2 border-slate-300 dark:border-slate-600 font-medium">
                        @foreach($columns as $col)
                            <td class="py-2 px-2 text-slate-900 dark:text-white">
                                @if($col['format'] === 'currency')
                                    ${{ number_format($totals[$col['key']] ?? 0, 2) }}
                                @elseif($col['format'] === 'date')
                                    {{ $totals[$col['key']] ?? '' }}
                                @else
                                    {{ number_format($totals[$col['key']] ?? 0) }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>
```

- [ ] **Step 8: Run Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: add BaseWidget and all widget components (SingleMetric, StatRow, LineChart, BarChart, PieChart, DataTable)"
```

---

## Task 5: Alpine Chart.js Wrapper & GridStack Installation

**Files:**
- Modify: `package.json` (add gridstack)
- Create: `resources/js/dashboard-grid.js` (Alpine components)
- Modify: `resources/js/app.js` (import new modules)

**Reference:** Check `resources/js/app.js` for existing Alpine setup and import patterns. Check `vite.config.js` for build configuration.

- [ ] **Step 1: Install GridStack**

```bash
cd /home/jordan/projects/revat/revat-v4/revat.io && npm install gridstack
```

- [ ] **Step 2: Create Alpine chart widget component**

Create `resources/js/dashboard-grid.js`:

```javascript
import { GridStack } from 'gridstack';
import 'gridstack/dist/gridstack.min.css';

// Chart.js Alpine component — used by all chart widgets
document.addEventListener('alpine:init', () => {
    Alpine.data('chartWidget', () => ({
        chart: null,

        initChart(canvas, data, type, showLegend, options = {}) {
            if (!canvas || !data) return;

            const config = {
                type: type === 'horizontal_bar' ? 'bar' : type,
                data: {
                    labels: data.labels || [],
                    datasets: (data.datasets || []).map((ds, i) => ({
                        ...ds,
                        borderColor: this.getColor(i),
                        backgroundColor: type === 'line' ? 'transparent' : this.getColor(i, 0.6),
                        borderWidth: type === 'line' ? 2 : 1,
                        tension: 0.3,
                        fill: type === 'area_chart',
                    })),
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: options.horizontal ? 'y' : 'x',
                    plugins: {
                        legend: { display: showLegend },
                    },
                    scales: (type === 'pie' || type === 'doughnut' || type === 'donut_chart' || type === 'pie_chart') ? {} : {
                        x: { grid: { display: false } },
                        y: { beginAtZero: true },
                    },
                },
            };

            // Map pie_chart/donut_chart to Chart.js types
            if (type === 'pie_chart' || type === 'pie') {
                config.type = 'pie';
            } else if (type === 'donut_chart') {
                config.type = 'doughnut';
            }

            this.chart = new Chart(canvas, config);
        },

        handleResize(event, widgetId) {
            if (event.detail?.widgetId === widgetId && this.chart) {
                this.chart.resize();
            }
        },

        getColor(index, alpha = 1) {
            const colors = [
                `rgba(99, 102, 241, ${alpha})`,   // indigo
                `rgba(16, 185, 129, ${alpha})`,    // emerald
                `rgba(245, 158, 11, ${alpha})`,    // amber
                `rgba(239, 68, 68, ${alpha})`,     // red
                `rgba(139, 92, 246, ${alpha})`,    // violet
                `rgba(6, 182, 212, ${alpha})`,     // cyan
                `rgba(236, 72, 153, ${alpha})`,    // pink
                `rgba(34, 197, 94, ${alpha})`,     // green
            ];
            return colors[index % colors.length];
        },

        destroy() {
            if (this.chart) {
                this.chart.destroy();
                this.chart = null;
            }
        },
    }));

    // GridStack Alpine component — manages the dashboard grid
    Alpine.data('dashboardGrid', (initialWidgets = [], editMode = false) => ({
        grid: null,
        editing: editMode,
        snapshotLayout: null,

        init() {
            this.grid = GridStack.init({
                column: 12,
                cellHeight: 80,
                margin: 12,
                animate: true,
                float: false,
                disableResize: !this.editing,
                disableDrag: !this.editing,
                columnOpts: {
                    breakpoints: [
                        { w: 768, c: 1 },
                        { w: 1200, c: 6 },
                    ],
                },
            }, this.$el);

            // Listen for layout changes
            this.grid.on('change', (event, items) => {
                this.debouncedSave(items);
            });

            // Listen for resize stop to notify chart widgets
            this.grid.on('resizestop', (event, el) => {
                const widgetId = el.getAttribute('gs-id');
                if (widgetId) {
                    window.dispatchEvent(new CustomEvent('widget-resized', {
                        detail: { widgetId: parseInt(widgetId) },
                    }));
                }
            });
        },

        debouncedSave: Alpine.debounce(function (items) {
            if (!this.editing) return;
            const layout = items.map(item => ({
                id: parseInt(item.id),
                x: item.x,
                y: item.y,
                w: item.w,
                h: item.h,
            }));
            this.$wire.saveLayout(layout);
        }, 500),

        enterEditMode() {
            this.snapshotLayout = this.grid.save(true);
            this.$wire.createSnapshot();
            this.editing = true;
            this.grid.enableMove(true);
            this.grid.enableResize(true);
        },

        exitEditMode() {
            this.editing = false;
            this.grid.enableMove(false);
            this.grid.enableResize(false);
            this.snapshotLayout = null;
        },

        cancelEdit() {
            if (this.snapshotLayout) {
                this.grid.load(this.snapshotLayout);
                this.$wire.cancelEdit();
            }
            this.exitEditMode();
        },

        removeWidget(el) {
            const widgetId = el.getAttribute('gs-id');
            this.grid.removeWidget(el);
            this.$wire.removeWidget(parseInt(widgetId));
        },
    }));
});
```

- [ ] **Step 3: Import in app.js**

Add to `resources/js/app.js`:

```javascript
import './dashboard-grid';
```

- [ ] **Step 4: Build frontend assets**

```bash
npm run build
```

Expected: Build completes without errors.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: add Alpine Chart.js wrapper, GridStack integration, and frontend build"
```

---

## Task 6: DashboardGrid Livewire Component

**Files:**
- Create: `app/Livewire/Dashboard/DashboardGrid.php`
- Create: `resources/views/livewire/dashboard/dashboard-grid.blade.php`

**Reference:** Read `app/Livewire/Dashboard/StatCards.php` for component patterns and `app/Dashboard/WidgetRegistry.php` for widget type resolution.

- [ ] **Step 1: Create DashboardGrid component**

Create `app/Livewire/Dashboard/DashboardGrid.php`:

```php
<?php

namespace App\Livewire\Dashboard;

use App\Dashboard\WidgetRegistry;
use App\Models\Dashboard;
use App\Models\DashboardSnapshot;
use App\Models\DashboardWidget;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

class DashboardGrid extends Component
{
    #[Locked]
    public int $dashboardId;

    public bool $editing = false;

    public function mount(int $dashboardId): void
    {
        $this->dashboardId = $dashboardId;
    }

    public function saveLayout(array $items): void
    {
        DB::transaction(function () use ($items) {
            foreach ($items as $item) {
                DashboardWidget::where('id', $item['id'])
                    ->where('dashboard_id', $this->dashboardId)
                    ->update([
                        'grid_x' => $item['x'],
                        'grid_y' => $item['y'],
                        'grid_w' => $item['w'],
                        'grid_h' => $item['h'],
                    ]);
            }
        });
    }

    public function createSnapshot(): void
    {
        $dashboard = Dashboard::with('widgets')->find($this->dashboardId);
        if (! $dashboard) {
            return;
        }

        DashboardSnapshot::create([
            'dashboard_id' => $this->dashboardId,
            'created_by' => auth()->id(),
            'layout' => $dashboard->widgets->map(fn ($w) => [
                'widget_type' => $w->widget_type,
                'grid_x' => $w->grid_x,
                'grid_y' => $w->grid_y,
                'grid_w' => $w->grid_w,
                'grid_h' => $w->grid_h,
                'config' => $w->config,
                'sort_order' => $w->sort_order,
            ])->toArray(),
            'widget_count' => $dashboard->widgets->count(),
            'created_at' => now(),
        ]);
    }

    public function cancelEdit(): void
    {
        // Alpine handles restoring the visual layout from its in-memory snapshot.
        // Server-side, we reload widget positions from DB (they haven't been saved
        // because Alpine only called saveLayout for committed changes).
        $this->editing = false;
    }

    public function removeWidget(int $widgetId): void
    {
        DashboardWidget::where('id', $widgetId)
            ->where('dashboard_id', $this->dashboardId)
            ->delete();
    }

    public function addWidget(string $widgetType, array $config = []): void
    {
        $defaults = WidgetRegistry::defaultsFor($widgetType);
        if (empty($defaults)) {
            return;
        }

        $maxY = DashboardWidget::where('dashboard_id', $this->dashboardId)
            ->max(DB::raw('grid_y + grid_h')) ?? 0;

        DashboardWidget::create([
            'dashboard_id' => $this->dashboardId,
            'widget_type' => $widgetType,
            'grid_x' => 0,
            'grid_y' => $maxY,
            'grid_w' => $defaults['w'],
            'grid_h' => $defaults['h'],
            'config' => $config,
            'sort_order' => 0,
        ]);
    }

    public function render()
    {
        $widgets = DashboardWidget::where('dashboard_id', $this->dashboardId)
            ->orderBy('grid_y')
            ->orderBy('grid_x')
            ->get();

        return view('livewire.dashboard.dashboard-grid', [
            'widgets' => $widgets,
            'widgetRegistry' => WidgetRegistry::all(),
        ]);
    }
}
```

- [ ] **Step 2: Create dashboard-grid Blade view**

Create `resources/views/livewire/dashboard/dashboard-grid.blade.php`:

```blade
<div>
    <div wire:ignore x-data="dashboardGrid(@js($widgets->map(fn ($w) => [
        'id' => $w->id,
        'x' => $w->grid_x,
        'y' => $w->grid_y,
        'w' => $w->grid_w,
        'h' => $w->grid_h,
        'minW' => $widgetRegistry[$w->widget_type]['min_w'] ?? 2,
        'minH' => $widgetRegistry[$w->widget_type]['min_h'] ?? 2,
        'maxW' => $widgetRegistry[$w->widget_type]['max_w'] ?? 12,
        'maxH' => $widgetRegistry[$w->widget_type]['max_h'] ?? 10,
    ])->toArray()), @js($editing))" class="grid-stack">
        @foreach($widgets as $widget)
            @php
                $reg = $widgetRegistry[$widget->widget_type] ?? null;
                $componentName = $reg['component'] ?? null;
            @endphp
            @if($componentName)
                <div class="grid-stack-item"
                     gs-id="{{ $widget->id }}"
                     gs-x="{{ $widget->grid_x }}"
                     gs-y="{{ $widget->grid_y }}"
                     gs-w="{{ $widget->grid_w }}"
                     gs-h="{{ $widget->grid_h }}"
                     gs-min-w="{{ $reg['min_w'] ?? 2 }}"
                     gs-min-h="{{ $reg['min_h'] ?? 2 }}"
                     gs-max-w="{{ $reg['max_w'] ?? 12 }}"
                     gs-max-h="{{ $reg['max_h'] ?? 10 }}">
                    <div class="grid-stack-item-content">
                        <div class="relative h-full group">
                            {{-- Edit mode controls --}}
                            <template x-if="editing">
                                <div class="absolute top-2 right-2 z-10 flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                    <button class="p-1 bg-white dark:bg-slate-700 rounded shadow text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200"
                                            @click="$dispatch('open-widget-config', { widgetId: {{ $widget->id }} })">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                                    </button>
                                    <button class="p-1 bg-white dark:bg-slate-700 rounded shadow text-red-400 hover:text-red-600"
                                            @click="removeWidget($el.closest('.grid-stack-item'))">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                    </button>
                                </div>
                            </template>

                            <livewire:dynamic-component
                                :is="$componentName"
                                :widget-id="$widget->id"
                                :config="$widget->config"
                                :key="'widget-'.$widget->id"
                            />
                        </div>
                    </div>
                </div>
            @endif
        @endforeach
    </div>
</div>
```

- [ ] **Step 3: Run Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: add DashboardGrid Livewire component with GridStack integration"
```

---

## Task 7: Dashboard Page (Volt) & Routes

**Files:**
- Modify: `resources/views/pages/dashboard.blade.php` (rewrite as customizable dashboard)
- Modify: `routes/web.php` (add dashboard CRUD routes)
- Test: `tests/Feature/Dashboard/DashboardPermissionsTest.php`

**Reference:** Read the current `resources/views/pages/dashboard.blade.php` for the Volt page pattern. Read `routes/web.php` for route grouping and middleware conventions.

- [ ] **Step 1: Add dashboard routes**

In `routes/web.php`, inside the authenticated middleware group, add:

```php
// Dashboard CRUD
Route::post('/dashboard', [App\Livewire\Dashboard\DashboardController::class, 'store'])
    ->middleware('can:integrate')
    ->name('dashboard.store');
Route::put('/dashboard/{dashboard}', [App\Livewire\Dashboard\DashboardController::class, 'update'])
    ->middleware('can:integrate')
    ->name('dashboard.update');
Route::delete('/dashboard/{dashboard}', [App\Livewire\Dashboard\DashboardController::class, 'destroy'])
    ->middleware('can:manage')
    ->name('dashboard.destroy');
Route::post('/dashboard/{dashboard}/lock', [App\Livewire\Dashboard\DashboardController::class, 'toggleLock'])
    ->middleware('can:manage')
    ->name('dashboard.toggle-lock');
Route::post('/dashboard/{dashboard}/export', [App\Livewire\Dashboard\DashboardController::class, 'export'])
    ->middleware('can:integrate')
    ->name('dashboard.export');
Route::get('/dashboard/import/{token}', [App\Livewire\Dashboard\DashboardController::class, 'showImport'])
    ->name('dashboard.import.show');
Route::post('/dashboard/import/{token}', [App\Livewire\Dashboard\DashboardController::class, 'import'])
    ->middleware('can:integrate')
    ->name('dashboard.import');
Route::post('/dashboard/{dashboard}/restore/{snapshot}', [App\Livewire\Dashboard\DashboardController::class, 'restore'])
    ->middleware('can:integrate')
    ->name('dashboard.restore');
Route::delete('/dashboard/export/{export}', [App\Livewire\Dashboard\DashboardController::class, 'revokeExport'])
    ->middleware('can:integrate')
    ->name('dashboard.export.revoke');
```

- [ ] **Step 2: Create DashboardController**

```bash
php artisan make:controller Livewire/Dashboard/DashboardController --no-interaction
```

Implement with all CRUD methods, permission checks, dashboard locking enforcement, export/import logic, and snapshot restore. Each method should verify the dashboard belongs to the current workspace via `session('workspace_id')`.

**Key methods:**
- `store` — create new dashboard (with optional template cloning)
- `update` — rename/update description
- `destroy` — delete (check unlocked, check `can:manage`)
- `toggleLock` — toggle `is_locked` (check `can:manage`)
- `export` — create DashboardExport with snapshotted layout
- `showImport` — show preview page for import token
- `import` — clone exported layout into workspace
- `restore` — replace dashboard widgets from snapshot
- `revokeExport` — delete export link

- [ ] **Step 3: Rewrite dashboard.blade.php**

Rewrite `resources/views/pages/dashboard.blade.php` as a Volt page that:
- Loads the user's active dashboard (or shows first-visit template selection)
- Renders the DashboardGrid component
- Includes the DateFilter component (reused)
- Has a dashboard selector dropdown
- Has edit mode toggle, dashboard CRUD buttons
- Includes the WidgetConfigPanel slide-over

**Key Volt state:**
- `$activeDashboard` — loaded from UserDashboardPreference or null (first visit)
- `$dashboards` — all workspace dashboards for the selector
- `$showTemplateSelector` — true on first visit
- `$editing` — edit mode state

- [ ] **Step 4: Write permission tests**

Create `tests/Feature/Dashboard/DashboardPermissionsTest.php`:

```bash
php artisan make:test Dashboard/DashboardPermissionsTest --pest --no-interaction
```

Test each action against each role:
- Owner/admin can delete, lock/unlock
- Editor can create and edit (when unlocked), cannot delete or lock
- Viewer cannot create, edit, or delete
- Locked dashboards reject editor edits
- All roles can view and switch active dashboard

- [ ] **Step 5: Run tests**

```bash
php artisan test --compact --filter=DashboardPermissionsTest
```

- [ ] **Step 6: Run Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: add dashboard Volt page, controller, routes, and permission tests"
```

---

## Task 8: Widget Configuration Panel

**Files:**
- Create: `app/Livewire/Dashboard/WidgetConfigPanel.php`
- Create: `resources/views/livewire/dashboard/widget-config-panel.blade.php`

**Reference:** Check sibling Livewire components for slide-over/modal patterns. Use Flux UI components (`flux:modal`, `flux:button`, `flux:input`, `flux:select`) — activate `fluxui-development` skill.

- [ ] **Step 1: Create WidgetConfigPanel component**

The panel has two modes:
- **Catalog mode** — browsing widget types by category
- **Config mode** — editing a specific widget's configuration

State: `$mode` (catalog/config), `$widgetId` (null for new), `$widgetType`, `$config` array with all fields.

Listens to `open-widget-config` event from the grid's gear icon. When saving, dispatches to DashboardGrid to add/update the widget.

- [ ] **Step 2: Create Blade view with Flux UI**

Two-panel layout:
- Catalog panel: categorized list with search (uses `WidgetRegistry::byCategory()`)
- Config panel: form with Display, Data Source, Visualization, Filters, Date Range sections
- Advanced toggle to show/hide the full config form
- "Add to Dashboard" / "Update Widget" button

- [ ] **Step 3: Run Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: add WidgetConfigPanel slide-over with curated catalog and advanced configuration"
```

---

## Task 9: Dashboard Snapshots & Version History

**Files:**
- Test: `tests/Feature/Dashboard/DashboardSnapshotTest.php`

**Reference:** Snapshot creation is already in DashboardGrid (Task 6). This task adds restore and version history UI.

- [ ] **Step 1: Write snapshot tests**

```bash
php artisan make:test Dashboard/DashboardSnapshotTest --pest --no-interaction
```

Tests:
- Entering edit mode creates a snapshot with correct layout data
- Restoring a snapshot replaces all widgets with snapshot data
- Version history returns snapshots ordered by created_at desc
- Only last 20 snapshots are retained (after pruning)
- Editors can restore when dashboard is unlocked
- Viewers cannot restore

- [ ] **Step 2: Implement restore in DashboardController**

The `restore` method loads the snapshot's layout JSON, deletes all current widgets for the dashboard, and bulk-inserts the snapshot widgets. Wrap in a transaction.

- [ ] **Step 3: Add version history to dashboard page**

Add a "Version History" item to the dashboard header dropdown menu. Opens a modal listing snapshots with: timestamp, creator name, widget count, and "Restore" button.

- [ ] **Step 4: Run tests**

```bash
php artisan test --compact --filter=DashboardSnapshotTest
```

- [ ] **Step 5: Run Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: add dashboard snapshot restore and version history UI"
```

---

## Task 10: Snapshot Pruning Command

**Files:**
- Create: `app/Console/Commands/PruneDashboardSnapshots.php`
- Modify: `routes/console.php` (register schedule)

- [ ] **Step 1: Create artisan command**

```bash
php artisan make:command PruneDashboardSnapshots --no-interaction
```

The command queries each dashboard, keeps the 20 most recent snapshots, and deletes the rest. Log how many were pruned.

```php
$this->signature = 'dashboard:prune-snapshots';
$this->description = 'Prune dashboard snapshots keeping only the most recent 20 per dashboard';
```

- [ ] **Step 2: Register in console routes**

In `routes/console.php`:

```php
Schedule::command('dashboard:prune-snapshots')->daily();
```

- [ ] **Step 3: Test the command**

```bash
php artisan dashboard:prune-snapshots
```

Expected: Runs without error.

- [ ] **Step 4: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: add dashboard:prune-snapshots command with daily schedule"
```

---

## Task 11: Starter Templates & Seeder

**Files:**
- Create: `database/seeders/DashboardTemplateSeeder.php`
- Test: `tests/Feature/Dashboard/DashboardTemplateTest.php`

**Reference:** Read `database/seeders/RolesAndPermissionsSeeder.php` for the seeder pattern. Use `firstOrCreate` to make it idempotent.

- [ ] **Step 1: Write template tests**

```bash
php artisan make:test Dashboard/DashboardTemplateTest --pest --no-interaction
```

Tests:
- Seeder creates 3 template dashboards with correct widgets
- Templates are `is_template = true` with null `workspace_id`
- Cloning a template creates a workspace dashboard with all widgets
- Each template has the expected number and types of widgets

- [ ] **Step 2: Create DashboardTemplateSeeder**

Create `database/seeders/DashboardTemplateSeeder.php` with the 3 templates defined in the spec:
- Executive Overview (6 widgets)
- Campaign Manager (5 widgets)
- Attribution Analyst (5 widgets)

Each widget has a complete `config` JSON with data_source, measure, visualization, and display settings. Use `firstOrCreate` on the dashboard (keyed by `template_slug`) so the seeder is idempotent.

- [ ] **Step 3: Run seeder**

```bash
php artisan db:seed --class=DashboardTemplateSeeder --no-interaction
```

- [ ] **Step 4: Run tests**

```bash
php artisan test --compact --filter=DashboardTemplateTest
```

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: add DashboardTemplateSeeder with 3 starter templates"
```

---

## Task 12: Export/Import Sharing

**Files:**
- Test: `tests/Feature/Dashboard/DashboardExportImportTest.php`
- Create: `resources/views/pages/dashboard/import.blade.php`

- [ ] **Step 1: Write export/import tests**

```bash
php artisan make:test Dashboard/DashboardExportImportTest --pest --no-interaction
```

Tests:
- Exporting creates a DashboardExport with snapshotted layout and valid token
- Export link URL is accessible and shows preview
- Importing creates a new dashboard in the user's workspace
- Imported dashboard has all widgets from the export
- Expired export links return 404
- Unauthenticated users cannot import (redirect to login)
- Viewers cannot export or import
- Revoking an export deletes the record
- Only exporter or admin/owner can revoke

- [ ] **Step 2: Create import preview page**

Create `resources/views/pages/dashboard/import.blade.php` as a Volt page showing:
- Dashboard name and description
- Widget count
- Schematic grid preview (simple visual representation of widget positions)
- "Import to Workspace" button (or login prompt if unauthenticated)

- [ ] **Step 3: Run tests**

```bash
php artisan test --compact --filter=DashboardExportImportTest
```

- [ ] **Step 4: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: add dashboard export/import with share links and preview page"
```

---

## Task 13: User Dashboard Preferences & First-Visit Flow

**Files:**
- Test: `tests/Feature/Dashboard/UserDashboardPreferenceTest.php`

- [ ] **Step 1: Write preference tests**

```bash
php artisan make:test Dashboard/UserDashboardPreferenceTest --pest --no-interaction
```

Tests:
- First visit shows template selection when no workspace dashboards exist
- First visit shows existing dashboards when workspace has dashboards
- Selecting a template clones it and sets it as active
- Switching dashboards updates the preference
- Active dashboard persists across page reloads
- "Start from scratch" creates empty dashboard in edit mode

- [ ] **Step 2: Implement first-visit flow in dashboard Volt page**

In `dashboard.blade.php`, check for `UserDashboardPreference` on mount:
- If no record → check if workspace has dashboards → show selector or template picker
- On template selection → clone, create preference, redirect
- On "Start from scratch" → create empty dashboard, set preference, enter edit mode

- [ ] **Step 3: Run tests**

```bash
php artisan test --compact --filter=UserDashboardPreferenceTest
```

- [ ] **Step 4: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: add first-visit template selection and user dashboard preferences"
```

---

## Task 14: Dashboard Locking

**Files:**
- Test: `tests/Feature/Dashboard/DashboardLockingTest.php`

- [ ] **Step 1: Write locking tests**

```bash
php artisan make:test Dashboard/DashboardLockingTest --pest --no-interaction
```

Tests:
- Admin/owner can lock and unlock dashboards
- Editor cannot lock or unlock
- Locked dashboard hides edit button for editors
- Admin/owner can still edit locked dashboards
- Locked dashboard cannot be deleted (must unlock first)
- Lock state persists and is visible to all users

- [ ] **Step 2: Implement lock toggle**

Already in DashboardController. Ensure the dashboard page checks `is_locked` and the user's role to show/hide the edit button and lock indicator.

- [ ] **Step 3: Run tests**

```bash
php artisan test --compact --filter=DashboardLockingTest
```

- [ ] **Step 4: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: add dashboard locking with role-based enforcement"
```

---

## Task 15: Browser Tests

**Files:**
- Test: `tests/Browser/Dashboard/DashboardGridTest.php`

**Reference:** Check `tests/Browser/` for existing Dusk/browser test patterns in this project.

- [ ] **Step 1: Write browser tests**

Tests:
- Dashboard page loads with GridStack grid
- Widgets render in correct grid positions
- Edit mode toggle shows drag handles and controls
- Widget config panel opens on gear icon click
- Removing a widget updates the grid

- [ ] **Step 2: Run browser tests**

```bash
php artisan dusk --filter=DashboardGridTest
```

- [ ] **Step 3: Commit**

```bash
git add -A && git commit -m "test: add browser tests for dashboard grid interactions"
```

---

## Task 16: Final Integration & Cleanup

- [ ] **Step 1: Remove old dashboard components**

Delete the following files that are replaced by the new widget system:
- `app/Livewire/Dashboard/StatCards.php`
- `app/Livewire/Dashboard/RevenueChart.php`
- `app/Livewire/Dashboard/CampaignPerformance.php`
- `app/Livewire/Dashboard/AttributionWidget.php`
- Their corresponding Blade views in `resources/views/livewire/dashboard/`

Keep `DateFilter.php` — it's reused.

- [ ] **Step 2: Run full test suite**

```bash
php artisan test --compact
```

Expected: All tests pass. No regressions from removing old components.

- [ ] **Step 3: Run Pint on all modified files**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 4: Build frontend assets**

```bash
npm run build
```

- [ ] **Step 5: Final commit**

```bash
git add -A && git commit -m "chore: remove old hardcoded dashboard components, replaced by customizable widget system"
```
