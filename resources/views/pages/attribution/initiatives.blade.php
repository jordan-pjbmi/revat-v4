<?php

use App\Models\Initiative;
use App\Models\Program;
use App\Services\WorkspaceContext;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component
{
    public bool $showModal = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $code = '';

    public string $programId = '';

    public string $description = '';

    public string $status = 'active';

    public string $budget = '';

    public function openCreateModal(): void
    {
        $this->reset(['editingId', 'name', 'code', 'programId', 'description', 'status', 'budget']);
        $this->status = 'active';
        $this->showModal = true;
    }

    public function openEditModal(int $id): void
    {
        $workspace = app(WorkspaceContext::class)->getWorkspace();
        if (! $workspace) {
            return;
        }

        $initiative = Initiative::forWorkspace($workspace->id)->findOrFail($id);
        $this->editingId = $initiative->id;
        $this->name = $initiative->name;
        $this->code = $initiative->code;
        $this->programId = (string) $initiative->program_id;
        $this->description = $initiative->description ?? '';
        $this->status = $initiative->status;
        $this->budget = $initiative->budget ? (string) $initiative->budget : '';
        $this->showModal = true;
    }

    public function save(): void
    {
        $workspace = app(WorkspaceContext::class)->getWorkspace();
        if (! $workspace) {
            return;
        }

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('initiatives', 'code')
                    ->where('workspace_id', $workspace->id)
                    ->whereNull('deleted_at')
                    ->ignore($this->editingId),
            ],
            'programId' => ['required', 'exists:programs,id'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', 'in:active,paused,completed'],
            'budget' => ['nullable', 'numeric', 'min:0'],
        ]);

        $data = [
            'name' => $this->name,
            'code' => $this->code,
            'program_id' => $this->programId,
            'description' => $this->description ?: null,
            'status' => $this->status,
            'budget' => $this->budget !== '' ? $this->budget : null,
        ];

        if ($this->editingId) {
            $initiative = Initiative::forWorkspace($workspace->id)->findOrFail($this->editingId);
            $initiative->update($data);
        } else {
            $data['workspace_id'] = $workspace->id;
            Initiative::create($data);
        }

        $this->showModal = false;
    }

    public function delete(int $id): void
    {
        $workspace = app(WorkspaceContext::class)->getWorkspace();
        if (! $workspace) {
            return;
        }

        $initiative = Initiative::forWorkspace($workspace->id)->findOrFail($id);
        $initiative->delete();
    }

    public function with(): array
    {
        $workspace = app(WorkspaceContext::class)->getWorkspace();

        if (! $workspace) {
            return ['initiatives' => collect(), 'programs' => collect()];
        }

        return [
            'initiatives' => Initiative::forWorkspace($workspace->id)
                ->with('program')
                ->orderBy('name')
                ->get(),
            'programs' => Program::forWorkspace($workspace->id)->orderBy('name')->get(),
        ];
    }
}; ?>

<x-layouts.app>
    <x-slot:title>Initiatives</x-slot:title>

    @volt('attribution.initiatives')
    <div>
        <div class="flex justify-between items-start mb-6">
            <div>
                <h1 class="text-[22px] font-bold text-slate-900 dark:text-white">Initiatives</h1>
                <p class="text-[13px] text-slate-600 dark:text-slate-300 mt-0.5">Mid-level groupings within programs</p>
            </div>
            <flux:button variant="primary" icon="plus" wire:click="openCreateModal">
                Add Initiative
            </flux:button>
        </div>

        <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden">
            <div class="overflow-x-auto">
                @if ($initiatives->count() > 0)
                    <table class="w-full text-left">
                        <thead>
                            <tr class="border-b border-slate-200 dark:border-slate-700">
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Name</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Code</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Program</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Status</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Budget</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="text-[12.5px]">
                            @foreach ($initiatives as $initiative)
                                <tr class="border-b border-slate-100 dark:border-slate-700/50 hover:bg-slate-50 dark:hover:bg-slate-700/30">
                                    <td class="px-3 py-2.5 font-medium text-slate-800 dark:text-slate-200">
                                        {{ $initiative->name }}
                                        @if ($initiative->is_default)
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-indigo-50 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-400 ml-1.5">Default</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5 font-mono text-slate-500">{{ $initiative->code }}</td>
                                    <td class="px-3 py-2.5 text-slate-600 dark:text-slate-300">{{ $initiative->program?->name ?? '-' }}</td>
                                    <td class="px-3 py-2.5">
                                        @if ($initiative->status === 'active')
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400">
                                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                                Active
                                            </span>
                                        @elseif ($initiative->status === 'paused')
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                                                <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                                Paused
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-slate-100 text-slate-500 dark:bg-slate-700 dark:text-slate-400">
                                                <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span>
                                                Completed
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5 text-slate-600 dark:text-slate-300">
                                        {{ $initiative->budget ? '$' . number_format((float) $initiative->budget, 2) : '-' }}
                                    </td>
                                    <td class="px-3 py-2.5">
                                        <div class="flex items-center gap-1">
                                            <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="openEditModal({{ $initiative->id }})" title="Edit" />
                                            @unless ($initiative->is_default)
                                                <flux:button
                                                    size="xs"
                                                    variant="ghost"
                                                    icon="trash"
                                                    wire:click="delete({{ $initiative->id }})"
                                                    wire:confirm="Are you sure you want to delete this initiative? All efforts under it will also be deleted."
                                                    title="Delete"
                                                    class="text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                                                />
                                            @endunless
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="text-center py-16">
                        <flux:icon name="rectangle-group" class="w-10 h-10 text-slate-300 dark:text-slate-600 mx-auto mb-3" />
                        <p class="text-sm font-medium text-slate-500 dark:text-slate-400">No initiatives yet</p>
                        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Create an initiative within a program to group your efforts</p>
                    </div>
                @endif
            </div>
        </div>

        {{-- Create/Edit Initiative Modal --}}
        <flux:modal wire:model.self="showModal" class="max-w-lg">
            <div class="space-y-6">
                <flux:heading>{{ $editingId ? 'Edit Initiative' : 'Add Initiative' }}</flux:heading>

                <form wire:submit="save" class="space-y-4">
                    <flux:input wire:model="name" label="Name" type="text" placeholder="e.g. Spring Newsletter" required />
                    <flux:input wire:model="code" label="Code" type="text" placeholder="e.g. SPRING-NL" required />

                    <flux:select wire:model="programId" label="Program" placeholder="Select a program...">
                        @foreach ($programs as $program)
                            <flux:select.option value="{{ $program->id }}">{{ $program->name }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:textarea wire:model="description" label="Description" placeholder="Optional description..." rows="3" />

                    <flux:select wire:model="status" label="Status">
                        <flux:select.option value="active">Active</flux:select.option>
                        <flux:select.option value="paused">Paused</flux:select.option>
                        <flux:select.option value="completed">Completed</flux:select.option>
                    </flux:select>

                    <flux:input wire:model="budget" label="Budget" type="number" step="0.01" min="0" placeholder="0.00" />

                    <div class="flex justify-end gap-2 pt-2">
                        <flux:button wire:click="$set('showModal', false)" variant="ghost">Cancel</flux:button>
                        <flux:button type="submit" variant="primary">{{ $editingId ? 'Update' : 'Create' }} Initiative</flux:button>
                    </div>
                </form>
            </div>
        </flux:modal>
    </div>
    @endvolt
</x-layouts.app>
