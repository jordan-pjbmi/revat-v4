<?php

use App\Services\Dashboard\MetricsService;
use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component
{
    public string $start;

    public string $end;

    public string $model = 'first_click';

    #[Locked]
    public array $summary = [];

    #[Locked]
    public array $efforts = [];

    #[Locked]
    public int $activeConnectors = 0;

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

    protected function loadAttribution(): void
    {
        $workspace = app(WorkspaceContext::class)->getWorkspace();

        if (! $workspace) {
            $this->summary = ['attributed_conversions' => 0, 'attributed_revenue' => 0.00, 'total_weight' => 0.00];
            $this->efforts = [];
            $this->activeConnectors = 0;

            return;
        }

        $service = MetricsService::forWorkspace($workspace->id);
        $start = Carbon::parse($this->start);
        $end = Carbon::parse($this->end);

        $this->summary = $service->getAttributionSummary($start, $end, $this->model);
        $this->efforts = $service->getAttributionByEffort($start, $end, $this->model);

        $this->activeConnectors = DB::table('attribution_connectors')
            ->where('workspace_id', $workspace->id)
            ->where('is_active', true)
            ->count();
    }
}; ?>

<x-layouts.app>
    <x-slot:title>Attribution</x-slot:title>

    @volt('attribution.stats')
    <div>
        <div class="flex justify-between items-start mb-6">
            <div>
                <h1 class="text-[22px] font-bold text-slate-900 dark:text-white">Attribution</h1>
                <p class="text-[13px] text-slate-600 dark:text-slate-300 mt-0.5">Attribution analysis by model</p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3 mb-6">
            <livewire:dashboard.date-filter />

            <flux:select wire:model.live="model" size="sm" class="w-36">
                <flux:select.option value="first_click">First Touch</flux:select.option>
                <flux:select.option value="last_click">Last Touch</flux:select.option>
                <flux:select.option value="linear">Linear</flux:select.option>
            </flux:select>
        </div>

        {{-- Summary Cards --}}
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
            <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-5 py-4">
                <div class="text-[11px] font-medium uppercase tracking-[0.4px] text-slate-500 dark:text-slate-400 mb-1">Attributed Conversions</div>
                <div class="text-2xl font-bold font-mono tabular-nums">{{ number_format($summary['attributed_conversions'] ?? 0) }}</div>
            </div>
            <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-5 py-4">
                <div class="text-[11px] font-medium uppercase tracking-[0.4px] text-slate-500 dark:text-slate-400 mb-1">Attributed Revenue</div>
                <div class="text-2xl font-bold font-mono tabular-nums">${{ number_format($summary['attributed_revenue'] ?? 0, 2) }}</div>
            </div>
            <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-5 py-4">
                <div class="text-[11px] font-medium uppercase tracking-[0.4px] text-slate-500 dark:text-slate-400 mb-1">Active Connectors</div>
                <div class="text-2xl font-bold font-mono tabular-nums">{{ $activeConnectors }}</div>
            </div>
        </div>

        {{-- Top Efforts Table --}}
        <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-200 dark:border-slate-700">
                <span class="text-[15px] font-semibold text-slate-800 dark:text-slate-200">Top Efforts by Revenue</span>
            </div>
            <div class="overflow-x-auto">
                @if (count($efforts) > 0)
                    <table class="w-full text-left">
                        <thead>
                            <tr class="border-b border-slate-200 dark:border-slate-700">
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Effort</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Conversions</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Revenue</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Weight</th>
                            </tr>
                        </thead>
                        <tbody class="text-[12.5px]">
                            @foreach ($efforts as $effort)
                                <tr class="border-b border-slate-100 dark:border-slate-700/50 hover:bg-slate-50 dark:hover:bg-slate-700/30">
                                    <td class="px-3 py-2.5 font-sans font-medium text-slate-800 dark:text-slate-200">{{ $effort['effort_name'] }}</td>
                                    <td class="px-3 py-2.5 font-mono">{{ number_format($effort['attributed_conversions']) }}</td>
                                    <td class="px-3 py-2.5 font-mono">${{ number_format($effort['attributed_revenue'], 2) }}</td>
                                    <td class="px-3 py-2.5 font-mono">{{ number_format($effort['total_weight'], 4) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="text-center py-16">
                        <flux:icon name="arrow-path" class="w-10 h-10 text-slate-300 dark:text-slate-600 mx-auto mb-3" />
                        <p class="text-sm font-medium text-slate-500 dark:text-slate-400">No attribution data available</p>
                        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Run attribution to see results</p>
                    </div>
                @endif
            </div>
        </div>

        {{-- Navigation Links --}}
        <div class="mt-6 flex gap-3">
            <flux:button href="{{ route('attribution.clicks') }}" variant="ghost" size="sm">View Attribution Clicks</flux:button>
            <flux:button href="{{ route('attribution.conversion-sales') }}" variant="ghost" size="sm">View Attribution Conversions</flux:button>
        </div>
    </div>
    @endvolt
</x-layouts.app>
