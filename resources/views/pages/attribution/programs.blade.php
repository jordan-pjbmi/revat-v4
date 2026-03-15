<?php

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

    public string $description = '';

    public string $status = 'active';

    public ?string $startDate = null;

    public ?string $endDate = null;

    public function openCreateModal(): void
    {
        $this->reset(['editingId', 'name', 'code', 'description', 'status', 'startDate', 'endDate']);
        $this->status = 'active';
        $this->showModal = true;
    }

    public function openEditModal(int $id): void
    {
        $workspace = app(WorkspaceContext::class)->getWorkspace();
        if (! $workspace) {
            return;
        }

        $program = Program::forWorkspace($workspace->id)->findOrFail($id);
        $this->editingId = $program->id;
        $this->name = $program->name;
        $this->code = $program->code;
        $this->description = $program->description ?? '';
        $this->status = $program->status;
        $this->startDate = $program->start_date?->format('Y-m-d');
        $this->endDate = $program->end_date?->format('Y-m-d');
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
                Rule::unique('programs', 'code')
                    ->where('workspace_id', $workspace->id)
                    ->whereNull('deleted_at')
                    ->ignore($this->editingId),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', 'in:active,paused,completed'],
            'startDate' => ['nullable', 'date'],
            'endDate' => ['nullable', 'date', 'after_or_equal:startDate'],
        ]);

        $data = [
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description ?: null,
            'status' => $this->status,
            'start_date' => $this->startDate ?: null,
            'end_date' => $this->endDate ?: null,
        ];

        if ($this->editingId) {
            $program = Program::forWorkspace($workspace->id)->findOrFail($this->editingId);
            $program->update($data);
        } else {
            $data['workspace_id'] = $workspace->id;
            Program::create($data);
        }

        $this->showModal = false;
    }

    public function delete(int $id): void
    {
        $workspace = app(WorkspaceContext::class)->getWorkspace();
        if (! $workspace) {
            return;
        }

        $program = Program::forWorkspace($workspace->id)->findOrFail($id);
        $program->delete();
    }

    public function with(): array
    {
        $workspace = app(WorkspaceContext::class)->getWorkspace();

        if (! $workspace) {
            return ['programs' => collect()];
        }

        return [
            'programs' => Program::forWorkspace($workspace->id)->orderBy('name')->get(),
        ];
    }
}; ?>

<x-layouts.app>
    <x-slot:title>Programs</x-slot:title>

    @volt('attribution.programs')
    <div>
        <div class="flex justify-between items-start mb-6">
            <div>
                <h1 class="text-[22px] font-bold text-slate-900 dark:text-white">Programs</h1>
                <p class="text-[13px] text-slate-600 dark:text-slate-300 mt-0.5">Top-level marketing programs</p>
            </div>
            <flux:button variant="primary" icon="plus" wire:click="openCreateModal">
                Add Program
            </flux:button>
        </div>

        <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden">
            <div class="overflow-x-auto">
                @if ($programs->count() > 0)
                    <table class="w-full text-left">
                        <thead>
                            <tr class="border-b border-slate-200 dark:border-slate-700">
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Name</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Code</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Status</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Description</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Dates</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="text-[12.5px]">
                            @foreach ($programs as $program)
                                <tr class="border-b border-slate-100 dark:border-slate-700/50 hover:bg-slate-50 dark:hover:bg-slate-700/30">
                                    <td class="px-3 py-2.5 font-medium text-slate-800 dark:text-slate-200">
                                        {{ $program->name }}
                                        @if ($program->is_default)
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-indigo-50 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-400 ml-1.5">Default</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5 font-mono text-slate-500">{{ $program->code }}</td>
                                    <td class="px-3 py-2.5">
                                        @if ($program->status === 'active')
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400">
                                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                                Active
                                            </span>
                                        @elseif ($program->status === 'paused')
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
                                    <td class="px-3 py-2.5 text-slate-600 dark:text-slate-300 max-w-[200px] truncate">{{ $program->description ?? '-' }}</td>
                                    <td class="px-3 py-2.5 text-slate-500 text-[11px]">
                                        @if ($program->start_date || $program->end_date)
                                            {{ $program->start_date?->format('M j, Y') ?? '...' }} &ndash; {{ $program->end_date?->format('M j, Y') ?? '...' }}
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5">
                                        <div class="flex items-center gap-1">
                                            <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="openEditModal({{ $program->id }})" title="Edit" />
                                            @unless ($program->is_default)
                                                <flux:button
                                                    size="xs"
                                                    variant="ghost"
                                                    icon="trash"
                                                    wire:click="delete({{ $program->id }})"
                                                    wire:confirm="Are you sure you want to delete this program? All initiatives and efforts under it will also be deleted."
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
                        <flux:icon name="folder" class="w-10 h-10 text-slate-300 dark:text-slate-600 mx-auto mb-3" />
                        <p class="text-sm font-medium text-slate-500 dark:text-slate-400">No programs yet</p>
                        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Create a program to organize your marketing initiatives</p>
                    </div>
                @endif
            </div>
        </div>

        {{-- Create/Edit Program Modal --}}
        <flux:modal wire:model.self="showModal" class="max-w-lg">
            <div class="space-y-6">
                <flux:heading>{{ $editingId ? 'Edit Program' : 'Add Program' }}</flux:heading>

                <form wire:submit="save" class="space-y-4">
                    <flux:input wire:model="name" label="Name" type="text" placeholder="e.g. Q1 Email Campaign" required />
                    <flux:input wire:model="code" label="Code" type="text" placeholder="e.g. Q1-EMAIL" required />

                    <flux:textarea wire:model="description" label="Description" placeholder="Optional description..." rows="3" />

                    <flux:select wire:model="status" label="Status">
                        <flux:select.option value="active">Active</flux:select.option>
                        <flux:select.option value="paused">Paused</flux:select.option>
                        <flux:select.option value="completed">Completed</flux:select.option>
                    </flux:select>

                    <div class="grid grid-cols-2 gap-4">
                        <flux:input wire:model="startDate" label="Start Date" type="date" />
                        <flux:input wire:model="endDate" label="End Date" type="date" />
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <flux:button wire:click="$set('showModal', false)" variant="ghost">Cancel</flux:button>
                        <flux:button type="submit" variant="primary">{{ $editingId ? 'Update' : 'Create' }} Program</flux:button>
                    </div>
                </form>
            </div>
        </flux:modal>
    </div>
    @endvolt
</x-layouts.app>
