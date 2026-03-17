<?php

use App\Models\AttributionResult;
use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

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
    }

    #[On('date-range-changed')]
    public function onDateRangeChanged(string $start, string $end): void
    {
        $this->start = $start;
        $this->end = $end;
        $this->resetPage();
    }

    public function with(): array
    {
        $workspace = app(WorkspaceContext::class)->getWorkspace();

        if (! $workspace) {
            return ['results' => collect()->paginate(25)];
        }

        $query = AttributionResult::query()
            ->where('attribution_results.workspace_id', $workspace->id)
            ->join('conversion_sales', function ($join) {
                $join->on('attribution_results.conversion_id', '=', 'conversion_sales.id')
                    ->where('attribution_results.conversion_type', '=', 'conversion_sale');
            })
            ->leftJoin('efforts', 'attribution_results.effort_id', '=', 'efforts.id')
            ->leftJoin('campaign_emails as ce', function ($join) {
                $join->on('attribution_results.campaign_id', '=', 'ce.id')
                    ->where('attribution_results.campaign_type', '=', 'campaign_email');
            })
            ->whereBetween('conversion_sales.converted_at', [$this->start, Carbon::parse($this->end)->endOfDay()])
            ->select(
                'attribution_results.*',
                'conversion_sales.external_id as conversion_external_id',
                'conversion_sales.revenue as conversion_revenue',
                'efforts.name as effort_name',
                'ce.name as campaign_name',
            )
            ->orderByDesc('attribution_results.matched_at');

        return [
            'results' => $query->paginate(25),
        ];
    }
}; ?>

<x-layouts.app>
    <x-slot:title>Attribution Conversions</x-slot:title>

    @volt('attribution.conversions')
    <div>
        <div class="flex justify-between items-start mb-6">
            <div>
                <h1 class="text-[22px] font-bold text-slate-900 dark:text-white">Attribution Conversions</h1>
                <p class="text-[13px] text-slate-600 dark:text-slate-300 mt-0.5">Conversion sales with attribution details</p>
            </div>
            <flux:button href="{{ route('attribution.stats') }}" variant="ghost" size="sm">Back to Stats</flux:button>
        </div>

        <div class="flex flex-wrap items-center gap-3 mb-4">
            <livewire:dashboard.date-filter />
        </div>

        <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden">
            <div class="overflow-x-auto" wire:loading.class="opacity-50">
                @if ($results->count() > 0)
                    <table class="w-full text-left">
                        <thead>
                            <tr class="border-b border-slate-200 dark:border-slate-700">
                                <th class="sticky left-0 bg-white dark:bg-slate-800 text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">External ID</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Revenue</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Attributed To</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Campaign</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Model</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Weight</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Matched At</th>
                            </tr>
                        </thead>
                        <tbody class="text-[12.5px]">
                            @foreach ($results as $result)
                                <tr class="border-b border-slate-100 dark:border-slate-700/50 hover:bg-slate-50 dark:hover:bg-slate-700/30">
                                    <td class="sticky left-0 bg-white dark:bg-slate-800 px-3 py-2.5 font-mono text-slate-800 dark:text-slate-200">{{ $result->conversion_external_id ?? '-' }}</td>
                                    <td class="px-3 py-2.5 font-mono">${{ number_format($result->conversion_revenue ?? 0, 2) }}</td>
                                    <td class="px-3 py-2.5 font-sans text-slate-700 dark:text-slate-300">{{ $result->effort_name ?? 'Unknown' }}</td>
                                    <td class="px-3 py-2.5 font-sans text-slate-700 dark:text-slate-300">{{ $result->campaign_name ?? '-' }}</td>
                                    <td class="px-3 py-2.5">
                                        <span class="inline-flex items-center px-2 py-0.5 text-xs font-medium bg-blue-100 dark:bg-blue-500/15 text-blue-700 dark:text-blue-300 rounded">
                                            {{ str_replace('_', ' ', ucfirst($result->model)) }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2.5 font-mono">{{ number_format($result->weight, 4) }}</td>
                                    <td class="px-3 py-2.5 font-mono text-slate-500">{{ $result->matched_at?->format('M j, Y g:ia') ?? '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <div class="px-4 py-3 border-t border-slate-200 dark:border-slate-700">
                        {{ $results->links() }}
                    </div>
                @else
                    <div class="text-center py-16">
                        <flux:icon name="arrow-path" class="w-10 h-10 text-slate-300 dark:text-slate-600 mx-auto mb-3" />
                        <p class="text-sm font-medium text-slate-500 dark:text-slate-400">No attribution results found</p>
                        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Run attribution to see results</p>
                    </div>
                @endif
            </div>
        </div>
    </div>
    @endvolt
</x-layouts.app>
