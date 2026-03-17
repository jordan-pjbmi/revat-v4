<?php

namespace App\Livewire\Dashboard;

use App\Services\Dashboard\MetricsService;
use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

#[Lazy]
class AttributionWidget extends Component
{
    public string $model = 'first_touch';

    #[Locked]
    public array $summary = [];

    #[Locked]
    public array $topEfforts = [];

    public string $start;

    public string $end;

    public function mount(): void
    {
        $range = session('dashboard_date_range', [
            'start' => today()->subDays(29)->toDateString(),
            'end' => today()->toDateString(),
        ]);

        $this->start = $range['start'];
        $this->end = $range['end'];

        $this->loadAttribution();
    }

    #[On('date-range-changed')]
    public function onDateRangeChanged(string $start, string $end): void
    {
        $this->start = $start;
        $this->end = $end;
        $this->loadAttribution();
    }

    public function updatedModel(): void
    {
        $this->loadAttribution();
    }

    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-5 animate-pulse">
            <div class="h-4 bg-slate-200 dark:bg-slate-700 rounded w-32 mb-4"></div>
            <div class="grid grid-cols-3 gap-4 mb-4">
                @for ($i = 0; $i < 3; $i++)
                    <div class="h-16 bg-slate-100 dark:bg-slate-700 rounded-lg"></div>
                @endfor
            </div>
        </div>
        HTML;
    }

    protected function loadAttribution(): void
    {
        $workspace = app(WorkspaceContext::class)->getWorkspace();

        if (! $workspace) {
            $this->summary = ['attributed_conversions' => 0, 'attributed_revenue' => 0.00, 'total_weight' => 0.00];
            $this->topEfforts = [];

            return;
        }

        $service = MetricsService::forWorkspace($workspace->id);
        $start = Carbon::parse($this->start);
        $end = Carbon::parse($this->end);

        $this->summary = $service->getAttributionSummary($start, $end, $this->model);
        $this->topEfforts = array_slice(
            $service->getAttributionByEffort($start, $end, $this->model),
            0,
            5,
        );
    }

    public function render()
    {
        return view('livewire.dashboard.attribution-widget');
    }
}
