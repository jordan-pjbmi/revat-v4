<?php

use App\Models\Dashboard;
use App\Models\DashboardSnapshot;
use App\Models\UserDashboardPreference;
use App\Services\WorkspaceContext;
use Livewire\Volt\Component;

new class extends Component
{
    public ?int $activeDashboardId = null;

    public string $activeDashboardName = '';

    public bool $editing = false;

    public bool $showTemplates = false;

    public bool $showVersionHistory = false;

    public bool $activeDashboardLocked = false;

    /** @var array<int, array{id: int, name: string}> */
    public array $dashboards = [];

    public function mount(): void
    {
        $workspace = app(WorkspaceContext::class)->getWorkspace();
        if (! $workspace) {
            return;
        }

        $this->dashboards = Dashboard::forWorkspace($workspace->id)
            ->notTemplates()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])
            ->toArray();

        $preference = UserDashboardPreference::where('user_id', auth()->id())
            ->where('workspace_id', $workspace->id)
            ->first();

        if ($preference && $preference->active_dashboard_id) {
            $this->setActiveDashboard($preference->active_dashboard_id);
        } elseif (count($this->dashboards) > 0) {
            $this->setActiveDashboard($this->dashboards[0]['id']);
        } else {
            $this->showTemplates = true;
        }
    }

    public function switchDashboard(int $dashboardId): void
    {
        $workspace = app(WorkspaceContext::class)->getWorkspace();
        if (! $workspace) {
            return;
        }

        $dashboard = Dashboard::forWorkspace($workspace->id)
            ->notTemplates()
            ->find($dashboardId);

        if (! $dashboard) {
            return;
        }

        UserDashboardPreference::updateOrCreate(
            [
                'user_id' => auth()->id(),
                'workspace_id' => $workspace->id,
            ],
            ['active_dashboard_id' => $dashboard->id],
        );

        $this->setActiveDashboard($dashboard->id);
    }

    public function enterEditMode(): void
    {
        $this->authorize('integrate');
        $this->editing = true;
    }

    public function exitEditMode(): void
    {
        $this->editing = false;
    }

    public function openVersionHistory(): void
    {
        $this->showVersionHistory = true;
    }

    public function closeVersionHistory(): void
    {
        $this->showVersionHistory = false;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, DashboardSnapshot> */
    public function getSnapshotsProperty(): \Illuminate\Database\Eloquent\Collection
    {
        if (! $this->activeDashboardId) {
            return collect();
        }

        return DashboardSnapshot::where('dashboard_id', $this->activeDashboardId)
            ->with('creator')
            ->orderByDesc('created_at')
            ->get();
    }

    protected function setActiveDashboard(int $id): void
    {
        $dashboard = Dashboard::find($id);
        if ($dashboard) {
            $this->activeDashboardId = $dashboard->id;
            $this->activeDashboardName = $dashboard->name;
            $this->activeDashboardLocked = (bool) $dashboard->is_locked;
            $this->showTemplates = false;
            $this->showVersionHistory = false;
        }
    }
}; ?>

<x-layouts.app>
    <x-slot:title>Dashboard</x-slot:title>

    @volt('dashboard')
    <div>
        @if ($showTemplates)
            {{-- Template Selection Screen --}}
            <div class="max-w-4xl mx-auto py-12">
                <div class="text-center mb-8">
                    <h1 class="text-2xl font-bold text-slate-900 dark:text-white">Create Your Dashboard</h1>
                    <p class="text-sm text-slate-600 dark:text-slate-300 mt-2">Choose a template to get started or build from scratch.</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                    {{-- Executive Overview --}}
                    <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-6">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-10 h-10 rounded-lg bg-blue-100 dark:bg-blue-900/40 flex items-center justify-center">
                                <flux:icon.chart-bar class="w-5 h-5 text-blue-600 dark:text-blue-400" />
                            </div>
                            <h3 class="text-base font-semibold text-slate-900 dark:text-white">Executive Overview</h3>
                        </div>
                        <p class="text-sm text-slate-600 dark:text-slate-300 mb-4">High-level KPIs, revenue trends, and campaign performance at a glance.</p>
                        <form method="POST" action="{{ route('dashboard.store') }}">
                            @csrf
                            <input type="hidden" name="name" value="Executive Overview" />
                            <input type="hidden" name="template_slug" value="executive" />
                            <flux:button type="submit" variant="primary" size="sm" class="w-full">Start with this</flux:button>
                        </form>
                    </div>

                    {{-- Campaign Manager --}}
                    <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-6">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-10 h-10 rounded-lg bg-emerald-100 dark:bg-emerald-900/40 flex items-center justify-center">
                                <flux:icon.megaphone class="w-5 h-5 text-emerald-600 dark:text-emerald-400" />
                            </div>
                            <h3 class="text-base font-semibold text-slate-900 dark:text-white">Campaign Manager</h3>
                        </div>
                        <p class="text-sm text-slate-600 dark:text-slate-300 mb-4">Campaign metrics, email performance, and click-through analytics.</p>
                        <form method="POST" action="{{ route('dashboard.store') }}">
                            @csrf
                            <input type="hidden" name="name" value="Campaign Manager" />
                            <input type="hidden" name="template_slug" value="campaign-manager" />
                            <flux:button type="submit" variant="primary" size="sm" class="w-full">Start with this</flux:button>
                        </form>
                    </div>

                    {{-- Attribution Analyst --}}
                    <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-6">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-10 h-10 rounded-lg bg-violet-100 dark:bg-violet-900/40 flex items-center justify-center">
                                <flux:icon.adjustments-horizontal class="w-5 h-5 text-violet-600 dark:text-violet-400" />
                            </div>
                            <h3 class="text-base font-semibold text-slate-900 dark:text-white">Attribution Analyst</h3>
                        </div>
                        <p class="text-sm text-slate-600 dark:text-slate-300 mb-4">Deep attribution data, model comparisons, and conversion paths.</p>
                        <form method="POST" action="{{ route('dashboard.store') }}">
                            @csrf
                            <input type="hidden" name="name" value="Attribution Analyst" />
                            <input type="hidden" name="template_slug" value="attribution-analyst" />
                            <flux:button type="submit" variant="primary" size="sm" class="w-full">Start with this</flux:button>
                        </form>
                    </div>
                </div>

                <div class="text-center">
                    <form method="POST" action="{{ route('dashboard.store') }}">
                        @csrf
                        <input type="hidden" name="name" value="My Dashboard" />
                        <flux:button type="submit" variant="ghost" size="sm">Start from scratch</flux:button>
                    </form>
                </div>
            </div>
        @else
            {{-- Page Header --}}
            <div class="flex justify-between items-start mb-6">
                <div class="flex items-center gap-4">
                    <div class="flex items-center gap-2">
                        <h1 class="text-[22px] font-bold text-slate-900 dark:text-white">{{ $activeDashboardName }}</h1>
                        @if ($activeDashboardLocked)
                            <flux:icon.lock-closed class="w-4 h-4 text-slate-400 dark:text-slate-500" title="Dashboard is locked" />
                        @endif
                    </div>

                    @can('integrate')
                        <flux:dropdown>
                            <flux:button variant="ghost" size="sm" icon-trailing="chevron-down">
                                @if (count($dashboards) > 1)
                                    Switch
                                @else
                                    Dashboards
                                @endif
                            </flux:button>

                            <flux:menu>
                                @foreach ($dashboards as $d)
                                    <flux:menu.item wire:click="switchDashboard({{ $d['id'] }})">
                                        {{ $d['name'] }}
                                        @if ($d['id'] === $activeDashboardId)
                                            <flux:icon.check class="w-4 h-4 ml-auto" />
                                        @endif
                                    </flux:menu.item>
                                @endforeach
                                <flux:menu.separator />
                                <flux:menu.item onclick="event.preventDefault(); document.getElementById('create-dashboard-form').submit();">
                                    + New Dashboard
                                </flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    @endcan
                </div>

                <div class="flex items-center gap-3">
                    <livewire:dashboard.date-filter />

                    @if ($activeDashboardId)
                        <flux:button size="sm" variant="ghost" wire:click="openVersionHistory" icon="clock">
                            History
                        </flux:button>
                    @endif

                    @can('integrate')
                        @if ($editing)
                            <flux:button size="sm" variant="primary" wire:click="exitEditMode">Done</flux:button>
                            <flux:button size="sm" variant="ghost" wire:click="exitEditMode">Cancel</flux:button>
                        @elseif (! $activeDashboardLocked || auth()->user()->can('manage'))
                            <flux:button size="sm" variant="ghost" wire:click="enterEditMode" icon="pencil-square">
                                Customize
                            </flux:button>
                        @endif
                    @endcan
                </div>
            </div>

            {{-- Dashboard Grid --}}
            @if ($activeDashboardId)
                <livewire:dashboard.dashboard-grid :dashboard-id="$activeDashboardId" :key="'grid-' . $activeDashboardId" />
            @endif

            {{-- Widget Config Panel (slide-over) --}}
            <livewire:dashboard.widget-config-panel />

            {{-- Hidden form for creating new dashboard from the dropdown --}}
            <form id="create-dashboard-form" method="POST" action="{{ route('dashboard.store') }}" class="hidden">
                @csrf
                <input type="hidden" name="name" value="New Dashboard" />
            </form>

            {{-- Version History Modal --}}
            <flux:modal name="version-history" :show="$showVersionHistory" wire:close="closeVersionHistory" class="max-w-lg">
                <div class="mb-4">
                    <flux:heading size="lg">Version History</flux:heading>
                    <flux:subheading>Restore the dashboard to a previous snapshot.</flux:subheading>
                </div>

                <div class="divide-y divide-slate-100 dark:divide-slate-700">
                    @forelse ($this->snapshots as $snapshot)
                        <div class="flex items-center justify-between py-3">
                            <div>
                                <p class="text-sm font-medium text-slate-900 dark:text-white">
                                    {{ $snapshot->created_at->diffForHumans() }}
                                </p>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    {{ $snapshot->widget_count }} {{ Str::plural('widget', $snapshot->widget_count) }}
                                    &middot; {{ $snapshot->creator?->name ?? 'Unknown' }}
                                </p>
                            </div>

                            @can('integrate')
                                <form method="POST" action="{{ route('dashboard.restore', [$activeDashboardId, $snapshot->id]) }}">
                                    @csrf
                                    <flux:button type="submit" size="xs" variant="ghost">
                                        Restore
                                    </flux:button>
                                </form>
                            @endcan
                        </div>
                    @empty
                        <p class="py-6 text-center text-sm text-slate-500 dark:text-slate-400">No snapshots yet.</p>
                    @endforelse
                </div>
            </flux:modal>
        @endif
    </div>
    @endvolt
</x-layouts.app>
