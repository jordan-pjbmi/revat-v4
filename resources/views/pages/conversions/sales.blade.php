<?php

use App\Models\ConversionSale;
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

    public string $sortField = 'converted_at';

    public string $sortDirection = 'desc';

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

    public function sort(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'desc';
        }
    }

    public function with(): array
    {
        $workspace = app(WorkspaceContext::class)->getWorkspace();

        if (! $workspace) {
            return ['conversions' => collect()->paginate(25)];
        }

        $query = ConversionSale::query()
            ->where('workspace_id', $workspace->id)
            ->whereBetween('converted_at', [$this->start, Carbon::parse($this->end)->endOfDay()])
            ->orderBy($this->sortField, $this->sortDirection);

        return [
            'conversions' => $query->paginate(25),
        ];
    }
}; ?>

<x-layouts.app>
    <x-slot:title>Conversion Sales</x-slot:title>

    @volt('conversions.sales')
    <div>
        <div class="flex justify-between items-start mb-6">
            <div>
                <h1 class="text-[22px] font-bold text-slate-900 dark:text-white">Conversion Sales</h1>
                <p class="text-[13px] text-slate-600 dark:text-slate-300 mt-0.5">Browse conversion and sales data</p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3 mb-4">
            <livewire:dashboard.date-filter />
        </div>

        <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden">
            <div class="overflow-x-auto" wire:loading.class="opacity-50">
                @if ($conversions->count() > 0)
                    <table class="w-full text-left">
                        <thead>
                            <tr class="border-b border-slate-200 dark:border-slate-700">
                                <th class="sticky left-0 bg-white dark:bg-slate-800 text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">External ID</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px] cursor-pointer" wire:click="sort('revenue')">Revenue</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Payout</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Cost</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Profit</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px] cursor-pointer" wire:click="sort('converted_at')">Converted At</th>
                            </tr>
                        </thead>
                        <tbody class="text-[12.5px]">
                            @foreach ($conversions as $conversion)
                                @php
                                    $profit = ($conversion->revenue ?? 0) - ($conversion->cost ?? 0);
                                @endphp
                                <tr class="border-b border-slate-100 dark:border-slate-700/50 hover:bg-slate-50 dark:hover:bg-slate-700/30">
                                    <td class="sticky left-0 bg-white dark:bg-slate-800 px-3 py-2.5 font-mono text-slate-800 dark:text-slate-200">{{ $conversion->external_id ?? '-' }}</td>
                                    <td class="px-3 py-2.5 font-mono">${{ number_format($conversion->revenue ?? 0, 2) }}</td>
                                    <td class="px-3 py-2.5 font-mono">${{ number_format($conversion->payout ?? 0, 2) }}</td>
                                    <td class="px-3 py-2.5 font-mono">${{ number_format($conversion->cost ?? 0, 2) }}</td>
                                    <td class="px-3 py-2.5 font-mono {{ $profit >= 0 ? 'text-green-600' : 'text-red-600' }} font-semibold">
                                        ${{ number_format($profit, 2) }}
                                    </td>
                                    <td class="px-3 py-2.5 font-mono text-slate-500">{{ $conversion->converted_at?->format('M j, Y g:ia') ?? '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <div class="px-4 py-3 border-t border-slate-200 dark:border-slate-700">
                        {{ $conversions->links() }}
                    </div>
                @else
                    <div class="text-center py-16">
                        <flux:icon name="banknotes" class="w-10 h-10 text-slate-300 dark:text-slate-600 mx-auto mb-3" />
                        <p class="text-sm font-medium text-slate-500 dark:text-slate-400">No conversion sales found</p>
                        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Try adjusting your date range</p>
                    </div>
                @endif
            </div>
        </div>
    </div>
    @endvolt
</x-layouts.app>
