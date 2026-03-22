<?php

namespace App\Livewire\Dashboard;

use App\Dashboard\DataSourceRegistry;
use App\Dashboard\WidgetRegistry;
use App\Models\DashboardWidget;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class WidgetConfigPanel extends Component
{
    public bool $open = false;

    public string $mode = 'catalog';

    public ?int $editingWidgetId = null;

    public string $widgetType = '';

    public string $searchQuery = '';

    // Config form fields
    public string $title = '';

    public ?string $subtitle = null;

    public string $dataSource = '';

    public string $measure = '';

    public ?string $groupBy = null;

    public int $limit = 10;

    public string $chartType = 'bar';

    public bool $showLabels = true;

    public bool $showLegend = false;

    public bool $useGlobalDate = true;

    public ?string $fixedDateStart = null;

    public ?string $fixedDateEnd = null;

    public bool $advancedMode = false;

    public array $filters = [];

    #[On('open-widget-catalog')]
    public function openCatalog(): void
    {
        $this->reset(['widgetType', 'searchQuery', 'title', 'subtitle', 'dataSource', 'measure',
            'groupBy', 'limit', 'chartType', 'showLabels', 'showLegend', 'useGlobalDate',
            'fixedDateStart', 'fixedDateEnd', 'advancedMode', 'filters', 'editingWidgetId']);
        $this->limit = 10;
        $this->chartType = 'bar';
        $this->showLabels = true;
        $this->useGlobalDate = true;
        $this->mode = 'catalog';
        $this->open = true;
    }

    #[On('open-widget-config')]
    public function openForEdit(int $widgetId): void
    {
        $widget = DashboardWidget::find($widgetId);
        if (! $widget) {
            return;
        }

        $this->editingWidgetId = $widgetId;
        $this->widgetType = $widget->widget_type;

        $config = $widget->config ?? [];

        $this->title = $config['display']['title'] ?? '';
        $this->subtitle = $config['display']['subtitle'] ?? null;
        $this->dataSource = $config['data_source'] ?? '';
        $this->measure = $config['measure'] ?? '';
        $this->groupBy = $config['group_by'] ?? null;
        $this->limit = $config['limit'] ?? 10;
        $this->chartType = $config['visualization']['chart_type'] ?? 'bar';
        $this->showLabels = $config['visualization']['show_labels'] ?? true;
        $this->showLegend = $config['visualization']['show_legend'] ?? false;
        $this->filters = $config['filters'] ?? [];

        $dateOverride = $config['date_range_override'] ?? null;
        $this->useGlobalDate = $dateOverride === null;
        $this->fixedDateStart = $dateOverride['start'] ?? null;
        $this->fixedDateEnd = $dateOverride['end'] ?? null;

        $this->mode = 'config';
        $this->open = true;
    }

    public function selectWidgetType(string $type): void
    {
        $widget = WidgetRegistry::get($type);
        if (! $widget) {
            return;
        }

        $this->widgetType = $type;
        $this->mode = 'config';
        $this->title = $widget['name'];

        if ($type === 'line_chart') {
            $this->chartType = 'line';
            $this->dataSource = 'conversion_metrics';
            $this->measure = 'revenue';
        } elseif ($type === 'bar_chart') {
            $this->chartType = 'bar';
            $this->dataSource = 'campaign_metrics';
            $this->measure = 'sent';
        } elseif ($type === 'pie_chart') {
            $this->chartType = 'pie';
            $this->dataSource = 'platform_breakdown';
            $this->measure = 'sent';
            $this->groupBy = 'platform';
        } elseif ($type === 'single_metric') {
            $this->dataSource = 'conversion_metrics';
            $this->measure = 'revenue';
        } elseif ($type === 'stat_row') {
            $this->dataSource = 'campaign_metrics';
            $this->measure = 'sent';
        } elseif ($type === 'table') {
            $this->dataSource = 'campaign_metrics';
        }
    }

    public function addFilter(): void
    {
        $this->filters[] = [
            'dimension' => '',
            'operator' => 'in',
            'values' => '',
        ];
    }

    public function removeFilter(int $index): void
    {
        array_splice($this->filters, $index, 1);
        $this->filters = array_values($this->filters);
    }

    public function save(): void
    {
        $config = [
            'data_source' => $this->dataSource,
            'measure' => $this->measure,
            'group_by' => $this->groupBy ?: null,
            'limit' => $this->limit,
            'filters' => collect($this->filters)->filter(fn ($f) => ! empty($f['dimension']))->map(function ($f) {
                return [
                    'dimension' => $f['dimension'],
                    'operator' => $f['operator'] ?? 'in',
                    'values' => is_string($f['values']) ? array_map('trim', explode(',', $f['values'])) : ($f['values'] ?? []),
                ];
            })->values()->toArray(),
            'date_range_override' => ! $this->useGlobalDate ? ['start' => $this->fixedDateStart, 'end' => $this->fixedDateEnd] : null,
            'visualization' => [
                'chart_type' => $this->chartType,
                'show_labels' => $this->showLabels,
                'show_legend' => $this->showLegend,
            ],
            'display' => [
                'title' => $this->title,
                'subtitle' => $this->subtitle ?: null,
            ],
        ];

        if ($this->editingWidgetId) {
            DashboardWidget::where('id', $this->editingWidgetId)->update([
                'config' => $config,
                'widget_type' => $this->widgetType,
            ]);
            $this->dispatch('widget-config-updated.'.$this->editingWidgetId, config: $config);
        } else {
            $this->dispatch('add-widget-to-grid', widgetType: $this->widgetType, config: $config);
        }

        $this->close();
    }

    public function close(): void
    {
        $this->reset(['widgetType', 'searchQuery', 'title', 'subtitle', 'dataSource', 'measure',
            'groupBy', 'limit', 'chartType', 'showLabels', 'showLegend', 'useGlobalDate',
            'fixedDateStart', 'fixedDateEnd', 'advancedMode', 'filters', 'editingWidgetId']);
        $this->limit = 10;
        $this->chartType = 'bar';
        $this->showLabels = true;
        $this->useGlobalDate = true;
        $this->mode = 'catalog';
        $this->open = false;
    }

    #[Computed]
    public function availableWidgetTypes(): array
    {
        $byCategory = WidgetRegistry::byCategory();

        if (empty($this->searchQuery)) {
            return $byCategory;
        }

        $query = mb_strtolower($this->searchQuery);
        $filtered = [];

        foreach ($byCategory as $category => $widgets) {
            foreach ($widgets as $type => $widget) {
                if (str_contains(mb_strtolower($widget['name']), $query) ||
                    str_contains(mb_strtolower($widget['description']), $query) ||
                    str_contains(mb_strtolower($category), $query)) {
                    $filtered[$category][$type] = $widget;
                }
            }
        }

        return $filtered;
    }

    #[Computed]
    public function availableMeasures(): array
    {
        return DataSourceRegistry::measuresFor($this->dataSource);
    }

    #[Computed]
    public function availableDimensions(): array
    {
        return DataSourceRegistry::dimensionsFor($this->dataSource);
    }

    #[Computed]
    public function availableDataSources(): array
    {
        return DataSourceRegistry::all();
    }

    public function render()
    {
        return view('livewire.dashboard.widget-config-panel');
    }
}
