<?php

use App\Jobs\Extraction\ExtractIntegration;
use App\Models\Integration;
use App\Services\PlanEnforcement\IntegrationLimitService;
use App\Services\WorkspaceContext;
use Livewire\Volt\Component;

new class extends Component
{
    public function toggleStatus(int $integrationId): void
    {
        $workspace = app(WorkspaceContext::class)->getWorkspace();

        if (! $workspace) {
            return;
        }

        $integration = Integration::forWorkspace($workspace->id)->findOrFail($integrationId);
        $integration->is_active = ! $integration->is_active;
        $integration->save();
    }

    public function syncNow(int $integrationId): void
    {
        $workspace = app(WorkspaceContext::class)->getWorkspace();

        if (! $workspace) {
            return;
        }

        $integration = Integration::forWorkspace($workspace->id)->findOrFail($integrationId);

        if ($integration->sync_in_progress && ! $integration->isSyncStale()) {
            return;
        }

        $integration->markSyncStarted();
        ExtractIntegration::dispatch($integration);
    }

    public function deleteIntegration(int $integrationId): void
    {
        $workspace = app(WorkspaceContext::class)->getWorkspace();

        if (! $workspace) {
            return;
        }

        $integration = Integration::forWorkspace($workspace->id)->findOrFail($integrationId);

        $integration->delete();
    }

    public function with(): array
    {
        $workspace = app(WorkspaceContext::class)->getWorkspace();

        if (! $workspace) {
            return ['integrations' => collect(), 'platforms' => [], 'hasSyncInProgress' => false, 'atCapacity' => false, 'integrationLimit' => 0];
        }

        $integrations = Integration::forWorkspace($workspace->id)
            ->orderBy('name')
            ->get();

        $platforms = config('integrations.platforms', []);
        $hasSyncInProgress = $integrations->contains(fn ($i) => $i->sync_in_progress);

        // Plan limit check
        $limitService = app(IntegrationLimitService::class);
        $limit = $limitService->maxAllowed($workspace->organization);
        $atCapacity = ! $limitService->canAdd($workspace->organization, $workspace);

        return [
            'integrations' => $integrations,
            'platforms' => $platforms,
            'hasSyncInProgress' => $hasSyncInProgress,
            'atCapacity' => $atCapacity,
            'integrationLimit' => $limit,
        ];
    }
}; ?>

<x-layouts.app>
    <x-slot:title>Integrations</x-slot:title>

    @volt('integrations.index')
    <div @if ($hasSyncInProgress) wire:poll.5s @endif>
        <div class="flex justify-between items-start mb-6">
            <div>
                <h1 class="text-[22px] font-bold text-slate-900 dark:text-white">Integrations</h1>
                <p class="text-[13px] text-slate-600 dark:text-slate-300 mt-0.5">Manage your data source connections</p>
            </div>
            <a href="{{ route('integrations.create') }}">
                <flux:button variant="primary" icon="plus" :disabled="$atCapacity">
                    Add Integration
                </flux:button>
            </a>
        </div>

        {{-- Plan Limit Banner --}}
        @if ($atCapacity)
            <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 dark:bg-amber-900/20 dark:border-amber-800 px-4 py-3">
                <p class="text-sm text-amber-800 dark:text-amber-300">
                    You've used all {{ $integrationLimit }} of your integration slots.
                    <a href="{{ route('billing') }}" class="font-medium underline hover:no-underline">Upgrade your plan</a> to add more.
                </p>
            </div>
        @endif

        {{-- Integrations Table --}}
        <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden">
            <div class="overflow-x-auto" wire:loading.class="opacity-50">
                @if ($integrations->count() > 0)
                    <table class="w-full text-left">
                        <thead>
                            <tr class="border-b border-slate-200 dark:border-slate-700">
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Name</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Platform</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Status</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Sync Status</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Last Synced</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Data Types</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="text-[12.5px]">
                            @foreach ($integrations as $integration)
                                <tr class="border-b border-slate-100 dark:border-slate-700/50 hover:bg-slate-50 dark:hover:bg-slate-700/30">
                                    <td class="px-3 py-2.5 font-medium text-slate-800 dark:text-slate-200">{{ $integration->name }}</td>
                                    <td class="px-3 py-2.5 text-slate-600 dark:text-slate-300">{{ $platforms[$integration->platform]['label'] ?? ucfirst($integration->platform) }}</td>
                                    <td class="px-3 py-2.5">
                                        @if ($integration->is_active)
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400">
                                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                                Active
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-slate-100 text-slate-500 dark:bg-slate-700 dark:text-slate-400">
                                                <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span>
                                                Inactive
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5">
                                        @if ($integration->sync_in_progress)
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400">
                                                <svg class="w-3 h-3 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                                Syncing
                                            </span>
                                        @elseif ($integration->last_sync_status === 'completed')
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400">
                                                Completed
                                            </span>
                                        @elseif ($integration->last_sync_status === 'failed')
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-400" title="{{ $integration->last_sync_error }}">
                                                Failed
                                            </span>
                                        @else
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-slate-100 text-slate-500 dark:bg-slate-700 dark:text-slate-400">
                                                Never synced
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5 font-mono text-slate-500">{{ $integration->last_synced_at?->diffForHumans() ?? '-' }}</td>
                                    <td class="px-3 py-2.5 text-slate-600 dark:text-slate-300">
                                        @php $dataTypeLabels = config('integrations.data_type_labels', []); @endphp
                                        @foreach ($integration->data_types ?? [] as $dataType)
                                            <span class="inline-block px-1.5 py-0.5 rounded text-[10px] font-medium bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300 mr-1">{{ $dataTypeLabels[$dataType] ?? str_replace('_', ' ', $dataType) }}</span>
                                        @endforeach
                                    </td>
                                    <td class="px-3 py-2.5">
                                        <div class="flex items-center gap-1">
                                            <a href="{{ route('integrations.edit', $integration) }}">
                                                <flux:button size="xs" variant="ghost" icon="pencil-square">
                                                    Edit
                                                </flux:button>
                                            </a>
                                            <flux:button
                                                size="xs"
                                                variant="ghost"
                                                icon="arrow-path"
                                                wire:click="syncNow({{ $integration->id }})"
                                                :disabled="$integration->sync_in_progress"
                                                title="Sync Now"
                                            >
                                                Sync
                                            </flux:button>
                                            <flux:button
                                                size="xs"
                                                variant="ghost"
                                                wire:click="toggleStatus({{ $integration->id }})"
                                                title="{{ $integration->is_active ? 'Pause' : 'Resume' }}"
                                            >
                                                {{ $integration->is_active ? 'Pause' : 'Resume' }}
                                            </flux:button>
                                            <flux:button
                                                size="xs"
                                                variant="ghost"
                                                icon="trash"
                                                wire:click="deleteIntegration({{ $integration->id }})"
                                                wire:confirm="Are you sure you want to delete this integration? This cannot be undone."
                                                title="Delete"
                                                class="text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                                            />
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="text-center py-16">
                        <svg class="w-10 h-10 text-slate-300 dark:text-slate-600 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.25 6.087c0-.355.186-.676.401-.959.221-.29.349-.634.349-1.003 0-1.036-1.007-1.875-2.25-1.875s-2.25.84-2.25 1.875c0 .369.128.713.349 1.003.215.283.401.604.401.959v0a.64.64 0 0 1-.657.643 48.39 48.39 0 0 1-4.163-.3c.186 1.613.293 3.25.315 4.907a.656.656 0 0 1-.658.663v0c-.355 0-.676-.186-.959-.401a1.647 1.647 0 0 0-1.003-.349c-1.036 0-1.875 1.007-1.875 2.25s.84 2.25 1.875 2.25c.369 0 .713-.128 1.003-.349.283-.215.604-.401.959-.401v0c.31 0 .555.26.532.57a48.039 48.039 0 0 1-.642 5.056c1.518.19 3.058.309 4.616.354a.64.64 0 0 0 .657-.643v0c0-.355-.186-.676-.401-.959a1.647 1.647 0 0 1-.349-1.003c0-1.035 1.008-1.875 2.25-1.875 1.243 0 2.25.84 2.25 1.875 0 .369-.128.713-.349 1.003-.215.283-.4.604-.4.959v0c0 .333.277.599.61.58a48.1 48.1 0 0 0 5.427-.63 48.05 48.05 0 0 0 .582-4.717.532.532 0 0 0-.533-.57v0c-.355 0-.676.186-.959.401-.29.221-.634.349-1.003.349-1.035 0-1.875-1.007-1.875-2.25s.84-2.25 1.875-2.25c.37 0 .713.128 1.003.349.283.215.604.401.96.401v0a.656.656 0 0 0 .658-.663 48.422 48.422 0 0 0-.37-5.36c-1.886.342-3.81.574-5.766.689a.578.578 0 0 1-.61-.58v0Z" /></svg>
                        <p class="text-sm font-medium text-slate-500 dark:text-slate-400">No integrations configured</p>
                        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Connect a data source to start syncing your marketing data</p>
                    </div>
                @endif
            </div>
        </div>
    </div>
    @endvolt
</x-layouts.app>
