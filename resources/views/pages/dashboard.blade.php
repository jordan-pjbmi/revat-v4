<?php

use App\Models\Dashboard;
use App\Models\DashboardExport;
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

    public bool $showNewDashboardModal = false;

    public bool $showRenameDashboardModal = false;

    public bool $activeDashboardLocked = false;

    public string $newDashboardName = '';

    public string $renameDashboardName = '';

    public ?string $shareUrl = null;

    /** @var array<int, array{id: int, name: string}> */
    public array $dashboards = [];

    public function mount(): void
    {
        $workspace = app(WorkspaceContext::class)->getWorkspace();
        if (! $workspace) {
            return;
        }

        $this->loadDashboards($workspace->id);

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
        $this->dispatch('dashboard-enter-edit-mode');
    }

    public function exitEditMode(): void
    {
        $this->editing = false;
        $this->dispatch('dashboard-exit-edit-mode');
    }

    public function cancelEditMode(): void
    {
        $this->editing = false;
        $this->dispatch('dashboard-cancel-edit-mode');
    }

    public function openVersionHistory(): void
    {
        $this->showVersionHistory = true;
    }

    public function closeVersionHistory(): void
    {
        $this->showVersionHistory = false;
    }

    public function openNewDashboardModal(): void
    {
        $this->newDashboardName = '';
        $this->showNewDashboardModal = true;
    }

    public function openRenameDashboardModal(): void
    {
        $this->renameDashboardName = $this->activeDashboardName;
        $this->showRenameDashboardModal = true;
    }

    public function openAddWidget(): void
    {
        $this->dispatch('open-widget-catalog');
    }

    public function toggleLock(): void
    {
        $this->authorize('manage');
        if (! $this->activeDashboardId) {
            return;
        }
        $dashboard = Dashboard::find($this->activeDashboardId);
        if ($dashboard) {
            $dashboard->update(['is_locked' => ! $dashboard->is_locked]);
            $this->activeDashboardLocked = (bool) $dashboard->fresh()->is_locked;
        }
    }

    public function deleteDashboard(): void
    {
        $this->authorize('manage');
        if (! $this->activeDashboardId) {
            return;
        }
        $dashboard = Dashboard::find($this->activeDashboardId);
        if ($dashboard && ! $dashboard->is_locked) {
            $dashboard->delete();
            $workspace = app(WorkspaceContext::class)->getWorkspace();
            $this->loadDashboards($workspace->id);
            if (count($this->dashboards) > 0) {
                $this->switchDashboard($this->dashboards[0]['id']);
            } else {
                $this->activeDashboardId = null;
                $this->activeDashboardName = '';
                $this->showTemplates = true;
            }
        }
    }

    public function exportDashboard(): void
    {
        if (! $this->activeDashboardId) {
            return;
        }

        $dashboard = Dashboard::with('widgets')->find($this->activeDashboardId);
        if (! $dashboard) {
            return;
        }

        $export = DashboardExport::create([
            'dashboard_id' => $dashboard->id,
            'created_by' => auth()->id(),
            'token' => DashboardExport::generateToken(),
            'layout' => $dashboard->widgets->map(fn ($w) => [
                'widget_type' => $w->widget_type,
                'grid_x' => $w->grid_x,
                'grid_y' => $w->grid_y,
                'grid_w' => $w->grid_w,
                'grid_h' => $w->grid_h,
                'config' => $w->config,
                'sort_order' => $w->sort_order,
            ])->toArray(),
            'name' => $dashboard->name,
            'description' => $dashboard->description,
            'widget_count' => $dashboard->widgets->count(),
            'created_at' => now(),
        ]);

        $this->shareUrl = url('/dashboard/import/' . $export->token);
    }

    public function clearShareUrl(): void
    {
        $this->shareUrl = null;
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
            $this->shareUrl = null;
        }
    }

    protected function loadDashboards(int $workspaceId): void
    {
        $this->dashboards = Dashboard::forWorkspace($workspaceId)
            ->notTemplates()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])
            ->toArray();
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

                    {{-- Dashboard Switcher (visible to all roles) --}}
                    <flux:dropdown>
                        <flux:button variant="ghost" size="sm" icon-trailing="chevron-down">
                            @if (count($dashboards) > 1) Switch @else Dashboards @endif
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

                            @can('integrate')
                                <flux:menu.separator />

                                {{-- Create from template --}}
                                <flux:menu.submenu heading="New from Template">
                                    <flux:menu.item onclick="event.preventDefault(); document.getElementById('tpl-executive').submit();">
                                        Executive Overview
                                    </flux:menu.item>
                                    <flux:menu.item onclick="event.preventDefault(); document.getElementById('tpl-campaign').submit();">
                                        Campaign Manager
                                    </flux:menu.item>
                                    <flux:menu.item onclick="event.preventDefault(); document.getElementById('tpl-attribution').submit();">
                                        Attribution Analyst
                                    </flux:menu.item>
                                </flux:menu.submenu>

                                <flux:menu.item wire:click="openNewDashboardModal">
                                    + Blank Dashboard
                                </flux:menu.item>
                            @endcan
                        </flux:menu>
                    </flux:dropdown>

                    {{-- Dashboard Actions Menu --}}
                    @if ($activeDashboardId)
                        <flux:dropdown>
                            <flux:button variant="ghost" size="sm" icon="ellipsis-vertical" />

                            <flux:menu>
                                @can('integrate')
                                    <flux:menu.item wire:click="openRenameDashboardModal" icon="pencil">
                                        Rename
                                    </flux:menu.item>
                                    <flux:menu.item wire:click="exportDashboard" icon="share">
                                        Share Link
                                    </flux:menu.item>
                                @endcan

                                <flux:menu.item wire:click="openVersionHistory" icon="clock">
                                    Version History
                                </flux:menu.item>

                                @can('manage')
                                    <flux:menu.separator />
                                    <flux:menu.item wire:click="toggleLock" icon="{{ $activeDashboardLocked ? 'lock-open' : 'lock-closed' }}">
                                        {{ $activeDashboardLocked ? 'Unlock' : 'Lock' }} Dashboard
                                    </flux:menu.item>
                                    <flux:menu.item variant="danger" wire:click="deleteDashboard" wire:confirm="Delete this dashboard?" icon="trash">
                                        Delete Dashboard
                                    </flux:menu.item>
                                @endcan
                            </flux:menu>
                        </flux:dropdown>
                    @endif
                </div>

                <div class="flex items-center gap-3">
                    <livewire:dashboard.date-filter />

                    @can('integrate')
                        @if ($editing)
                            <flux:button size="sm" variant="primary" wire:click="openAddWidget" icon="plus">
                                Add Widget
                            </flux:button>
                            <flux:button size="sm" variant="primary" wire:click="exitEditMode">Done</flux:button>
                            <flux:button size="sm" variant="ghost" wire:click="cancelEditMode">Cancel</flux:button>
                        @elseif (! $activeDashboardLocked || auth()->user()->can('manage'))
                            <flux:button size="sm" variant="ghost" wire:click="enterEditMode" icon="pencil-square">
                                Customize
                            </flux:button>
                        @endif
                    @endcan
                </div>
            </div>

            {{-- Share URL notification --}}
            @if ($shareUrl)
                <div class="mb-4 p-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <flux:icon.link class="w-4 h-4 text-blue-500" />
                        <span class="text-sm text-blue-700 dark:text-blue-300">Share link created:</span>
                        <code class="text-xs bg-blue-100 dark:bg-blue-900/40 px-2 py-0.5 rounded text-blue-800 dark:text-blue-200 select-all">{{ $shareUrl }}</code>
                    </div>
                    <flux:button size="xs" variant="ghost" wire:click="clearShareUrl">Dismiss</flux:button>
                </div>
            @endif

            {{-- Dashboard Grid --}}
            @if ($activeDashboardId)
                <livewire:dashboard.dashboard-grid :dashboard-id="$activeDashboardId" :key="'grid-' . $activeDashboardId" />
            @endif

            {{-- Widget Config Panel (slide-over) --}}
            <livewire:dashboard.widget-config-panel />

            {{-- Hidden template forms --}}
            @can('integrate')
                <form id="tpl-executive" method="POST" action="{{ route('dashboard.store') }}" class="hidden">
                    @csrf
                    <input type="hidden" name="name" value="Executive Overview" />
                    <input type="hidden" name="template_slug" value="executive" />
                </form>
                <form id="tpl-campaign" method="POST" action="{{ route('dashboard.store') }}" class="hidden">
                    @csrf
                    <input type="hidden" name="name" value="Campaign Manager" />
                    <input type="hidden" name="template_slug" value="campaign-manager" />
                </form>
                <form id="tpl-attribution" method="POST" action="{{ route('dashboard.store') }}" class="hidden">
                    @csrf
                    <input type="hidden" name="name" value="Attribution Analyst" />
                    <input type="hidden" name="template_slug" value="attribution-analyst" />
                </form>
            @endcan

            {{-- New Dashboard Modal --}}
            @if ($showNewDashboardModal)
                <flux:modal name="new-dashboard" :show="true" wire:close="$set('showNewDashboardModal', false)" class="max-w-sm">
                    <div class="mb-4">
                        <flux:heading size="lg">New Dashboard</flux:heading>
                        <flux:subheading>Give your dashboard a name.</flux:subheading>
                    </div>
                    <form method="POST" action="{{ route('dashboard.store') }}">
                        @csrf
                        <flux:input name="name" label="Name" placeholder="My Dashboard" required class="mb-4" />
                        <div class="flex gap-2 justify-end">
                            <flux:button variant="ghost" wire:click="$set('showNewDashboardModal', false)">Cancel</flux:button>
                            <flux:button type="submit" variant="primary">Create</flux:button>
                        </div>
                    </form>
                </flux:modal>
            @endif

            {{-- Rename Dashboard Modal --}}
            @if ($showRenameDashboardModal && $activeDashboardId)
                <flux:modal name="rename-dashboard" :show="true" wire:close="$set('showRenameDashboardModal', false)" class="max-w-sm">
                    <div class="mb-4">
                        <flux:heading size="lg">Rename Dashboard</flux:heading>
                    </div>
                    <form method="POST" action="{{ route('dashboard.update', $activeDashboardId) }}">
                        @csrf
                        @method('PUT')
                        <flux:input name="name" label="Name" value="{{ $activeDashboardName }}" required class="mb-4" />
                        <div class="flex gap-2 justify-end">
                            <flux:button variant="ghost" wire:click="$set('showRenameDashboardModal', false)">Cancel</flux:button>
                            <flux:button type="submit" variant="primary">Save</flux:button>
                        </div>
                    </form>
                </flux:modal>
            @endif

            {{-- Version History Modal --}}
            @if ($showVersionHistory)
                <flux:modal name="version-history" :show="true" wire:close="closeVersionHistory" class="max-w-lg">
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
        @endif
    </div>
    @endvolt
</x-layouts.app>
