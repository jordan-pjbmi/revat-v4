<?php

namespace App\Livewire\Dashboard\Widgets;

use App\Services\Dashboard\WidgetDataService;
use App\Services\WorkspaceContext;
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
        $workspaceId = app(WorkspaceContext::class)->getWorkspace()?->id;

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
