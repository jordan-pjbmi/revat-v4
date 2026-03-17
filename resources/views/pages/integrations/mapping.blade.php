<?php

use App\Models\Integration;
use App\Services\WorkspaceContext;
use Livewire\Volt\Component;

new class extends Component
{
    public Integration $integration;

    public function mount(): void
    {
        $workspace = app(WorkspaceContext::class)->getWorkspace();

        if (! $workspace) {
            $this->redirect(route('integrations'));
            return;
        }

        $integrationId = request()->route('integration');
        $this->integration = Integration::forWorkspace($workspace->id)->findOrFail($integrationId);
    }

    public function with(): array
    {
        $platformConfig = config("integrations.platforms.{$this->integration->platform}", []);

        return [
            'platformConfig' => $platformConfig,
        ];
    }
}; ?>

<x-layouts.app>
    <x-slot:title>Field Mapping</x-slot:title>

    @volt('integrations.mapping')
    <div class="max-w-4xl">
        {{-- Header --}}
        <div class="flex items-center gap-3 mb-6">
            <a href="{{ route('integrations') }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
                Back
            </a>
            <h1 class="text-[22px] font-bold text-slate-900 dark:text-white">Field Mapping</h1>
        </div>

        {{-- Integration badge --}}
        <div class="flex items-center gap-3 mb-6">
            <span class="inline-flex items-center justify-center w-10 h-10 rounded-lg bg-slate-100 dark:bg-slate-700 text-sm font-bold text-slate-700 dark:text-slate-200">
                {{ $platformConfig['short'] ?? strtoupper(substr($integration->platform, 0, 2)) }}
            </span>
            <div>
                <p class="text-sm font-semibold text-slate-900 dark:text-white">{{ $integration->name }}</p>
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ $platformConfig['label'] ?? ucfirst($integration->platform) }}</p>
            </div>
        </div>

        {{-- Stub content --}}
        <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-8 text-center">
            <svg class="w-10 h-10 text-slate-300 dark:text-slate-600 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" /></svg>
            <p class="text-sm font-medium text-slate-500 dark:text-slate-400">Field mapping coming soon</p>
            <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Configure how source fields map to your data model</p>
        </div>
    </div>
    @endvolt
</x-layouts.app>
