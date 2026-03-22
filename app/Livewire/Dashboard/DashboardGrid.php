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
