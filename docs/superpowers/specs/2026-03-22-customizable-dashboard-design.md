# Customizable Dashboard Widget System

## Overview

Replace the current hardcoded dashboard with a fully customizable widget-based dashboard system. Users build their own dashboards by selecting, positioning, and configuring widgets on a drag-and-drop grid. Multiple named dashboards per workspace, shared across members, with role-based permissions and starter templates for first-time users.

## Goals

- Let users tailor their dashboard to their role and workflow
- Provide curated widget presets for quick setup with advanced configuration for power users
- Maintain the existing data layer (summary tables, MetricsService) as the foundation
- Support multiple dashboards per workspace with shared visibility

## Non-Goals

- Live fact-table queries (v1 uses summary tables only; data source registry is extensible for future)
- Real-time polling or websocket push (widgets refresh on filter interaction only)
- Dashboard sharing across workspaces
- Custom color themes per dashboard

---

## Architecture

### Approach

Livewire Component Per Widget. Each widget type is a dedicated Livewire component. The dashboard page loads the grid layout from the database, renders GridStack.js via Alpine.js, and mounts each widget as a Livewire component inside its grid cell.

```
dashboard.blade.php (Volt page)
  └─ DashboardGrid (Alpine + GridStack, wire:ignore)
       └─ grid-stack-item
            └─ <livewire:dynamic-component :is="widget.component" :config="widget.config" />
```

Key benefits:
- Each widget is self-contained — owns its data fetching, rendering, and loading state
- `#[Lazy]` loading works naturally (widgets load independently)
- Existing components (StatCards, RevenueChart, etc.) can be refactored into widgets
- Widget-level error isolation — one widget failing doesn't break the dashboard

### Frontend Stack

- **Grid:** GridStack.js (~10KB, MIT, zero dependencies, 8.8k GitHub stars)
- **Charts:** Chart.js v4 directly via Alpine.js wrapper (already installed, no Filament dependency)
- **Bridge:** Alpine.js initializes GridStack, listens to events, calls `$wire` methods

---

## Data Model

### `dashboards` table

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| workspace_id | FK → workspaces, nullable | null for global templates |
| created_by | FK → users | |
| name | string(100) | |
| description | string(255), nullable | |
| is_template | boolean, default false | starter templates are seeded |
| template_slug | string, nullable | `executive`, `campaign_manager`, `attribution_analyst` |
| is_locked | boolean, default false | owner/admin can lock to prevent member edits |
| created_at / updated_at | timestamps | |

### `dashboard_widgets` table

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| dashboard_id | FK → dashboards (cascade delete) | |
| widget_type | string(50) | registry key |
| grid_x | tinyint unsigned | column position (0-11) |
| grid_y | smallint unsigned | row position |
| grid_w | tinyint unsigned | width in columns |
| grid_h | tinyint unsigned | height in row units |
| config | JSON | data source, filters, labels, viz options |
| sort_order | smallint unsigned | fallback ordering |
| created_at / updated_at | timestamps | |

### `dashboard_snapshots` table

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| dashboard_id | FK → dashboards (cascade delete) | |
| created_by | FK → users | who triggered the edit session |
| layout | JSON | full serialized widget array (positions + configs) |
| widget_count | tinyint unsigned | for display in version history |
| created_at | timestamp | |

Retention: keep last 20 snapshots per dashboard, prune oldest via daily scheduled command (`dashboard:prune-snapshots`) registered in `routes/console.php`.

### `user_dashboard_preferences` table

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| user_id | FK → users | |
| workspace_id | FK → workspaces | |
| active_dashboard_id | FK → dashboards, nullable | |
| created_at / updated_at | timestamps | |
| | | unique(user_id, workspace_id) |

### Widget Config JSON Structure

```json
{
  "data_source": "campaign_metrics",
  "measure": "revenue",
  "group_by": "channel",
  "filters": [
    { "dimension": "platform", "operator": "in", "values": ["google", "meta"] }
  ],
  "date_range_override": null,
  "limit": 10,
  "visualization": {
    "chart_type": "horizontal_bar",
    "show_labels": true,
    "show_legend": false,
    "color_scheme": "default"
  },
  "display": {
    "title": "Revenue by Channel",
    "subtitle": "Top 10"
  }
}
```

### Relationships

- Workspace hasMany Dashboards
- Dashboard hasMany DashboardWidgets
- Dashboard hasMany DashboardSnapshots
- Dashboard belongsTo Workspace, User (creator)
- User hasOne UserDashboardPreference per workspace (unique constraint)

---

## Widget Registry

A PHP class (`App\Dashboard\WidgetRegistry`) defining all available widget types and their grid constraints:

| Widget Type | Default Size | Min Size | Component |
|-------------|-------------|----------|-----------|
| `single_metric` | 3×2 | 2×2 | `widgets.single-metric` |
| `line_chart` | 6×4 | 4×3 | `widgets.line-chart` |
| `bar_chart` | 6×4 | 3×3 | `widgets.bar-chart` |
| `pie_chart` | 4×4 | 3×3 | `widgets.pie-chart` |
| `table` | 6×5 | 4×3 | `widgets.data-table` |
| `stat_row` | 12×2 | 6×2 | `widgets.stat-row` |

Each entry includes: name, description, icon, default/min/max dimensions, component class, and supported visualization subtypes.

## Data Source Registry

A PHP class (`App\Dashboard\DataSourceRegistry`) defining available data sources backed by summary tables:

| Data Source | Summary Model | Measures | Dimensions |
|-------------|--------------|----------|------------|
| `campaign_metrics` | SummaryCampaignDaily | sent, opens, clicks, open_rate, click_rate | platform, campaign |
| `conversion_metrics` | SummaryConversionDaily | conversions, revenue, cost, roas | platform, campaign |
| `attribution` | SummaryAttributionByEffort | attributed_conversions, attributed_revenue, weight | effort, model |
| `platform_breakdown` | SummaryCampaignByPlatform | sent, opens, clicks, revenue | platform |

Each entry includes: name, description, summary model class, measures with format types (number, currency, percent, decimal), available dimensions, trend support flag, and extra config options (e.g., attribution model selector).

Adding a new data source = add an entry + ensure the summary table/model exists. No widget code changes.

---

## Widget Data Service

`App\Services\Dashboard\WidgetDataService` — a generic data layer between widgets and summary tables.

### Responsibilities

- Accept widget config + date range → return data ready to render
- Resolve which summary model to query based on `data_source` key
- Apply dimension filters, grouping, and limits
- Compute derived measures (open_rate, roas)
- Format values based on measure format type
- For trend widgets: return current value + previous period comparison

### Workspace Scoping

Workspace ID comes from authenticated session via middleware, never from client input:

```
Livewire widget → auth middleware → WidgetDataService::forWorkspace($workspaceId) → scoped queries
```

No widget config can override which workspace's data is returned.

### Return Formats

Standardized per widget type:

```php
// Single metric
['value' => 12450, 'previous' => 10200, 'change' => 22.06, 'format' => 'currency']

// Chart (bar, line, pie)
['labels' => [...], 'datasets' => [['label' => 'Revenue', 'data' => [...]]]]

// Table
['columns' => [...], 'rows' => [[...], [...]], 'totals' => [...]]
```

### Relationship to MetricsService

`MetricsService` stays as-is (used by reports page). `WidgetDataService` is a new layer that can delegate to `MetricsService` for complex queries or query summary models directly. Future data sources plug into `WidgetDataService` via `DataSourceRegistry`.

---

## Component Architecture

### Livewire Components

| Component | Responsibility |
|-----------|---------------|
| `Dashboard\DashboardPage` | Volt page — loads active dashboard, manages edit mode toggle, dashboard CRUD |
| `Dashboard\DashboardGrid` | Passes widget layout data to Alpine/GridStack, receives layout changes back |
| `Dashboard\DateFilter` | Existing component reused — dispatches `date-range-changed` event |
| `Dashboard\WidgetConfigPanel` | Side panel for adding/editing widgets — curated catalog + advanced form |
| `Dashboard\Widgets\SingleMetric` | Big number + trend arrow + optional sparkline |
| `Dashboard\Widgets\LineChart` | Time series chart via Alpine + Chart.js |
| `Dashboard\Widgets\BarChart` | Comparison chart (vertical, horizontal, stacked) |
| `Dashboard\Widgets\PieChart` | Pie or donut chart |
| `Dashboard\Widgets\DataTable` | Sortable table with pagination |
| `Dashboard\Widgets\StatRow` | Row of 3-5 metric cards (replaces current StatCards) |

### Base Widget Class

```php
abstract class BaseWidget extends Component
{
    public int $widgetId;
    public array $config;

    abstract protected function getData(): array;
    abstract public function render(): View;

    #[On('date-range-changed')]
    public function onDateRangeChanged(string $start, string $end): void
    {
        if (!empty($this->config['date_range_override'])) return;
        // Re-fetch data with new range
    }

    #[On('widget-config-updated.{widgetId}')]
    public function onConfigUpdated(array $config): void
    {
        $this->config = $config;
    }
}
```

### Data Flow

1. **Page load** → DashboardPage loads user's active dashboard (or prompts template selection)
2. **Grid render** → passes widget records to Alpine, which initializes GridStack with positions
3. **Widget mount** → each widget receives its config JSON as a prop, fetches data from WidgetDataService
4. **Date change** → DateFilter dispatches event → all widgets with `#[On('date-range-changed')]` re-query
5. **Edit mode** → toggle enables GridStack drag/resize, shows controls
6. **Layout save** → GridStack change event → Alpine debounces 500ms → `$wire.saveLayout(json)` → bulk update positions
7. **Widget config** → gear icon or "Add Widget" → opens WidgetConfigPanel → save creates/updates widget row

### The `wire:ignore` Boundary

GridStack manages DOM inside the grid container. Livewire manages each widget's internal content. Alpine bridges the two by listening to GridStack events and calling `$wire` methods.

---

## Edit Mode & Permissions

### Mode Behavior

| State | Behavior |
|-------|----------|
| **View mode** (default) | Widgets static, no drag handles or gear icons. Dashboard selector and date filter visible. |
| **Edit mode** | Drag handles, resize corners, gear icon, delete (×) button on each widget. "Add Widget" button. Dashed grid overlay. |

### Permission Matrix

| Action | Owner | Admin | Member | Viewer |
|--------|-------|-------|--------|--------|
| View any dashboard | ✓ | ✓ | ✓ | ✓ |
| Switch active dashboard | ✓ | ✓ | ✓ | ✓ |
| Create dashboard | ✓ | ✓ | ✓ | ✗ |
| Edit dashboard (widgets, layout) | ✓ | ✓ | ✓* | ✗ |
| Rename dashboard | ✓ | ✓ | ✓* | ✗ |
| Delete dashboard | ✓ | ✓ | ✗ | ✗ |
| Restore snapshot | ✓ | ✓ | ✓* | ✗ |
| Lock/unlock dashboard | ✓ | ✓ | ✗ | ✗ |

\* Only when dashboard is unlocked.

### Dashboard Locking

- `is_locked` boolean on dashboards table (default false)
- Lock toggle in dashboard header menu (visible to owner/admin only)
- When locked: edit button hidden for members, lock indicator shown
- Owner/admin can still edit locked dashboards
- Locked dashboards can't be deleted (must unlock first)

---

## Snapshots & Version History

- Snapshot created automatically when a user enters edit mode
- "Cancel" restores from the most recent snapshot in Alpine memory (no DB round trip)
- Version history accessible from dashboard header menu — list of snapshots with timestamp, creator, widget count
- "Restore" loads snapshot JSON and bulk replaces dashboard_widgets
- Retention: keep last 20 per dashboard, prune oldest via scheduled job
- All roles that can edit can also view history and restore

---

## Widget Configuration Panel

Slide-over panel with two modes:

### Curated Mode (Default)

Searchable categorized catalog of widget types (Key Metrics, Charts, Data). Clicking a type opens the config form pre-filled with sensible defaults.

### Advanced Mode

Full configuration form with sections:
- **Display** — title, subtitle
- **Data Source** — source, measure, group by, limit
- **Visualization** — type selector (toggle buttons), show labels, show legend
- **Filters** — add/remove dimension filters (dimension, operator, values)
- **Date Range** — "Use Global" or "Fixed Range" toggle

### Flow

- Adding (curated): pick type → form pre-fills → adjust → "Add to Dashboard"
- Adding (advanced): toggle advanced → build from scratch → "Add to Dashboard"
- Editing: gear icon on widget → same panel pre-filled with current config → "Update Widget"

---

## Starter Templates

Three seeded templates cloned on first visit:

### Executive Overview
- Stat row (12 cols): Revenue, Conversions, ROAS, Cost, Open Rate
- Line chart (8 cols): Revenue & Cost trend
- Pie chart (4 cols): Revenue by platform
- Bar chart (6 cols): Top 10 campaigns by revenue
- Single metric (3 cols): Total conversions
- Single metric (3 cols): Average ROAS

### Campaign Manager
- Stat row (12 cols): Sent, Opens, Clicks, Open Rate, Click Rate
- Line chart (6 cols): Send volume trend
- Bar chart (6 cols): Open rate by campaign
- Data table (12 cols): Campaign breakdown
- Pie chart (4 cols): Sends by platform

### Attribution Analyst
- Stat row (12 cols): Attributed Conversions, Attributed Revenue, Total Weight
- Bar chart (6 cols): Attribution by effort (first touch)
- Bar chart (6 cols): Attribution by effort (last touch)
- Data table (12 cols): Effort attribution detail
- Pie chart (4 cols): Attribution distribution by model

### First-Visit Flow

1. User hits `/dashboard` — no preference record exists
2. If workspace has existing dashboards → show them in a selector; user must explicitly pick one to set as active. "Start from a template" option available at the bottom.
3. If no dashboards exist → template selection screen with 3 cards
4. On selection → clone template into workspace, set as user's active dashboard
5. "Start from scratch" → empty dashboard, land in edit mode

Templates are seeded via `DashboardTemplateSeeder` as `is_template = true` rows with no workspace_id. Cloning copies all widget rows with new IDs.

---

## GridStack Integration

### Configuration

- 12-column grid, 80px row height, 12px margin
- `animate: true`, `float: false` (widgets pack upward)
- Drag/resize disabled by default, enabled in edit mode

### Alpine Bridge

```
x-data="dashboardGrid"
├── init() — GridStack.init, load positions from Livewire prop
├── onGridChange(items) — debounce 500ms → $wire.saveLayout()
├── enterEditMode() — $wire.createSnapshot(), enable drag/resize
├── exitEditMode() — disable drag/resize, hide controls
├── cancelEdit() — restore from in-memory snapshot
└── addWidget() — grid.addWidget(), Livewire renders component
```

### Chart.js Resize

GridStack `resizestop` event → Alpine dispatches `widget-resized` browser event → chart widget calls `chart.resize()`. Debounced to avoid thrashing.

### Responsive Breakpoints

| Breakpoint | Columns | Behavior |
|------------|---------|----------|
| Desktop (>1200px) | 12 | Full grid, edit mode available |
| Tablet (768-1200px) | 6 | Auto-reflow, edit mode disabled |
| Mobile (<768px) | 1 | Single column stack, edit mode unavailable |

Only desktop layout stored. GridStack handles responsive adaptation via `columnOpts: { breakpoints: [{w: 768, c: 1}, {w: 1200, c: 6}] }`.

---

## Dashboard Template Sharing (Export/Import)

Users can export a dashboard as a shareable link and others can import it into their workspace.

### Export Flow

- "Share" option in dashboard header menu (available to anyone who can edit)
- Generates a shareable link with a unique token: `/dashboard/import/{token}`
- Exported payload is the dashboard layout + widget configs only — no data, no workspace-specific IDs
- Layout is snapshotted at export time so the link remains valid even if the source dashboard is later modified or deleted

### Import Flow

- User opens share link → preview screen showing dashboard name, description, widget count, and schematic layout preview
- "Import to Workspace" button → clones the template into their active workspace as a new dashboard
- User must be authenticated and have create-dashboard permission (owner/admin/member)

### `dashboard_exports` table

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| dashboard_id | FK → dashboards | source dashboard |
| created_by | FK → users | |
| token | string(64), unique | URL-safe random token |
| layout | JSON | snapshot of widgets at export time (positions + configs, no data) |
| name | string(100) | dashboard name at time of export |
| description | string(255), nullable | |
| widget_count | tinyint unsigned | |
| expires_at | timestamp, nullable | null = never expires |
| created_at | timestamp | |

### Permissions

- Export: anyone who can edit the dashboard
- Import: anyone with create-dashboard permission in their workspace
- Revoke: the exporter or any owner/admin can delete the export link

---

## Global Date Filter

Reuses the existing `DateFilter` Livewire component. Dispatches `date-range-changed` event. All widgets listen via `#[On('date-range-changed')]`.

Widgets with `date_range_override` set in their config ignore the global event and use their fixed date range.

---

## Testing Strategy

### Unit Tests
- WidgetRegistry — all types have required keys, component classes exist
- DataSourceRegistry — all sources reference valid models, measures/dimensions valid
- WidgetDataService — returns correct structure per widget type, computed measures calculate correctly, filters apply correctly

### Feature Tests
- Dashboard CRUD with permission checks
- Dashboard locking (locked rejects member edits)
- Widget CRUD with permission checks
- Layout save (positions persist and reload)
- Snapshot creation on entering edit mode
- Snapshot restore replaces widgets
- Template cloning creates correct workspace dashboard
- Date filter cascades to all widgets, overridden widgets unaffected
- User preferences persist active dashboard per workspace
- Export creates a valid share link with snapshotted layout
- Import via share link clones dashboard into workspace with correct widgets
- Import requires authentication and create-dashboard permission
- Expired export links return 404
- Revoking an export link (exporter or owner/admin) invalidates it

### Browser Tests
- GridStack initializes with correct positions
- Drag/resize updates positions
- Edit mode toggle shows/hides controls
- Widget config panel opens, form submits, widget appears
- Responsive collapse to single column on mobile

### Out of Scope for Testing
- GridStack.js internals (third-party library)
- Chart.js rendering (visual output)

---

## File Structure

```
app/
├── Dashboard/
│   ├── WidgetRegistry.php
│   └── DataSourceRegistry.php
├── Livewire/Dashboard/
│   ├── DashboardGrid.php
│   ├── DateFilter.php (existing, reused)
│   ├── WidgetConfigPanel.php
│   └── Widgets/
│       ├── BaseWidget.php
│       ├── SingleMetric.php
│       ├── LineChart.php
│       ├── BarChart.php
│       ├── PieChart.php
│       ├── DataTable.php
│       └── StatRow.php
├── Models/
│   ├── Dashboard.php
│   ├── DashboardWidget.php
│   ├── DashboardSnapshot.php
│   ├── DashboardExport.php
│   └── UserDashboardPreference.php
└── Services/Dashboard/
    ├── MetricsService.php (existing, unchanged)
    └── WidgetDataService.php

resources/views/
├── pages/
│   └── dashboard.blade.php (rewritten as Volt page)
└── livewire/dashboard/
    ├── dashboard-grid.blade.php
    ├── widget-config-panel.blade.php
    └── widgets/
        ├── single-metric.blade.php
        ├── line-chart.blade.php
        ├── bar-chart.blade.php
        ├── pie-chart.blade.php
        ├── data-table.blade.php
        └── stat-row.blade.php

database/
├── migrations/
│   ├── xxxx_create_dashboards_table.php
│   ├── xxxx_create_dashboard_widgets_table.php
│   ├── xxxx_create_dashboard_snapshots_table.php
│   ├── xxxx_create_dashboard_exports_table.php
│   └── xxxx_create_user_dashboard_preferences_table.php
└── seeders/
    └── DashboardTemplateSeeder.php
```
