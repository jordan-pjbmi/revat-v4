<?php

use App\Models\CampaignEmail;
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

    public string $search = '';

    public string $sortField = 'sent_at';

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

    public function updatedSearch(): void
    {
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
            return ['campaigns' => collect()->paginate(25)];
        }

        $query = CampaignEmail::query()
            ->where('workspace_id', $workspace->id)
            ->whereBetween('sent_at', [$this->start, Carbon::parse($this->end)->endOfDay()]);

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'like', "%{$this->search}%")
                    ->orWhere('subject', 'like', "%{$this->search}%");
            });
        }

        $query->orderBy($this->sortField, $this->sortDirection);

        return [
            'campaigns' => $query->paginate(25),
        ];
    }
}; ?>

<x-layouts.app>
    <x-slot:title>Campaigns</x-slot:title>

    @volt('campaigns.emails')
    <div>
        <div class="flex justify-between items-start mb-6">
            <div>
                <h1 class="text-[22px] font-bold text-slate-900 dark:text-white">Campaigns</h1>
                <p class="text-[13px] text-slate-600 dark:text-slate-300 mt-0.5">Browse campaign email performance data</p>
            </div>
        </div>

        {{-- Filter Bar --}}
        <div class="flex flex-wrap items-center gap-3 mb-4">
            <livewire:dashboard.date-filter />

            <div class="ml-auto">
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search campaigns..."
                    class="text-sm border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-1.5 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 w-56"
                />
            </div>
        </div>

        {{-- Campaigns Table --}}
        <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden">
            <div class="overflow-x-auto" wire:loading.class="opacity-50">
                @if ($campaigns->count() > 0)
                    <table class="w-full text-left">
                        <thead>
                            <tr class="border-b border-slate-200 dark:border-slate-700">
                                <th class="sticky left-0 bg-white dark:bg-slate-800 text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px] cursor-pointer" wire:click="sort('name')">Name / Subject</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Type</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">From</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px] cursor-pointer" wire:click="sort('sent')">Sent</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Opens</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Open Rate</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Clicks</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Click Rate</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px] cursor-pointer" wire:click="sort('platform_revenue')">Revenue</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px] cursor-pointer" wire:click="sort('sent_at')">Sent Date</th>
                            </tr>
                        </thead>
                        <tbody class="text-[12.5px]">
                            @foreach ($campaigns as $campaign)
                                <tr class="border-b border-slate-100 dark:border-slate-700/50 hover:bg-slate-50 dark:hover:bg-slate-700/30">
                                    <td class="sticky left-0 bg-white dark:bg-slate-800 px-3 py-2.5 font-sans">
                                        <div class="font-medium text-slate-800 dark:text-slate-200">{{ $campaign->name ?? 'Untitled' }}</div>
                                        @if ($campaign->subject)
                                            <div class="text-xs text-slate-400 truncate max-w-[250px]">{{ $campaign->subject }}</div>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5 font-sans text-slate-600 dark:text-slate-300">{{ $campaign->type ?? '-' }}</td>
                                    <td class="px-3 py-2.5 font-sans text-slate-600 dark:text-slate-300 truncate max-w-[150px]">{{ $campaign->from_name ?? '-' }}</td>
                                    <td class="px-3 py-2.5 font-mono">{{ number_format($campaign->sent ?? 0) }}</td>
                                    <td class="px-3 py-2.5 font-mono">{{ number_format($campaign->opens ?? 0) }}</td>
                                    <td class="px-3 py-2.5 font-mono">{{ $campaign->sent > 0 ? number_format($campaign->unique_opens / $campaign->sent * 100, 2) : '0.00' }}%</td>
                                    <td class="px-3 py-2.5 font-mono">{{ number_format($campaign->clicks ?? 0) }}</td>
                                    <td class="px-3 py-2.5 font-mono">{{ $campaign->sent > 0 ? number_format($campaign->unique_clicks / $campaign->sent * 100, 2) : '0.00' }}%</td>
                                    <td class="px-3 py-2.5 font-mono">${{ number_format($campaign->platform_revenue ?? 0, 2) }}</td>
                                    <td class="px-3 py-2.5 font-mono text-slate-500">{{ $campaign->sent_at?->format('M j, Y') ?? '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <div class="px-4 py-3 border-t border-slate-200 dark:border-slate-700">
                        {{ $campaigns->links() }}
                    </div>
                @else
                    <div class="text-center py-16">
                        <flux:icon name="megaphone" class="w-10 h-10 text-slate-300 dark:text-slate-600 mx-auto mb-3" />
                        <p class="text-sm font-medium text-slate-500 dark:text-slate-400">No campaigns found</p>
                        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Try adjusting your date range or search</p>
                    </div>
                @endif
            </div>
        </div>
    </div>
    @endvolt
</x-layouts.app>
