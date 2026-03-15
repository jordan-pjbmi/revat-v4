<?php

use App\Models\CampaignEmailClick;
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

    public string $sortField = 'clicked_at';

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
            return ['clicks' => collect()->paginate(25)];
        }

        $query = CampaignEmailClick::query()
            ->with('campaignEmail:id,name,subject')
            ->where('workspace_id', $workspace->id)
            ->whereBetween('clicked_at', [$this->start, Carbon::parse($this->end)->endOfDay()])
            ->orderBy($this->sortField, $this->sortDirection);

        return [
            'clicks' => $query->paginate(25),
        ];
    }
}; ?>

<x-layouts.app>
    <x-slot:title>Email Clicks</x-slot:title>

    @volt('campaigns.email-clicks')
    <div>
        <div class="flex justify-between items-start mb-6">
            <div>
                <h1 class="text-[22px] font-bold text-slate-900 dark:text-white">Email Clicks</h1>
                <p class="text-[13px] text-slate-600 dark:text-slate-300 mt-0.5">Browse individual email click events</p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3 mb-4">
            <livewire:dashboard.date-filter />
        </div>

        <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden">
            <div class="overflow-x-auto" wire:loading.class="opacity-50">
                @if ($clicks->count() > 0)
                    <table class="w-full text-left">
                        <thead>
                            <tr class="border-b border-slate-200 dark:border-slate-700">
                                <th class="sticky left-0 bg-white dark:bg-slate-800 text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Campaign</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Identity Hash</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px] cursor-pointer" wire:click="sort('clicked_at')">Clicked At</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px] cursor-pointer" wire:click="sort('created_at')">Created At</th>
                            </tr>
                        </thead>
                        <tbody class="text-[12.5px]">
                            @foreach ($clicks as $click)
                                <tr class="border-b border-slate-100 dark:border-slate-700/50 hover:bg-slate-50 dark:hover:bg-slate-700/30">
                                    <td class="sticky left-0 bg-white dark:bg-slate-800 px-3 py-2.5 font-sans">
                                        <div class="font-medium text-slate-800 dark:text-slate-200">{{ $click->campaignEmail?->name ?? 'Unknown' }}</div>
                                        @if ($click->campaignEmail?->subject)
                                            <div class="text-xs text-slate-400 truncate max-w-[250px]">{{ $click->campaignEmail->subject }}</div>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5 font-mono text-slate-600 dark:text-slate-300">{{ $click->identity_hash_id ?? '-' }}</td>
                                    <td class="px-3 py-2.5 font-mono text-slate-500">{{ $click->clicked_at?->format('M j, Y g:ia') ?? '-' }}</td>
                                    <td class="px-3 py-2.5 font-mono text-slate-500">{{ $click->created_at?->format('M j, Y g:ia') ?? '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <div class="px-4 py-3 border-t border-slate-200 dark:border-slate-700">
                        {{ $clicks->links() }}
                    </div>
                @else
                    <div class="text-center py-16">
                        <flux:icon name="cursor-arrow-rays" class="w-10 h-10 text-slate-300 dark:text-slate-600 mx-auto mb-3" />
                        <p class="text-sm font-medium text-slate-500 dark:text-slate-400">No email clicks found</p>
                        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Try adjusting your date range</p>
                    </div>
                @endif
            </div>
        </div>
    </div>
    @endvolt
</x-layouts.app>
