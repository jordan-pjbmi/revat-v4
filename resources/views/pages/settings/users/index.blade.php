<?php

use App\Models\Invitation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AuditService;
use App\Services\InvitationService;
use Livewire\Volt\Component;
use Spatie\Permission\PermissionRegistrar;

new class extends Component
{
    public ?int $confirmingRoleChangeFor = null;

    public string $newRole = '';

    public ?int $confirmingRemovalFor = null;

    public function getMembers(): \Illuminate\Support\Collection
    {
        $org = auth()->user()->currentOrganization;

        return $org->users()->withPivot('last_workspace_id')->get()->map(function ($user) use ($org) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
            $user->unsetRelation('roles');
            $userRole = $user->roles->first()?->name ?? 'viewer';

            return (object) [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $userRole,
                'is_deactivated' => $user->isDeactivated(),
                'last_active_at' => $user->last_active_at,
                'user' => $user,
            ];
        });
    }

    public function getPendingInvitations(): \Illuminate\Support\Collection
    {
        $org = auth()->user()->currentOrganization;

        return $org->invitations()->pending()->orderBy('created_at', 'desc')->get();
    }

    public function confirmRoleChange(int $userId, string $role): void
    {
        $this->confirmingRoleChangeFor = $userId;
        $this->newRole = $role;
    }

    public function changeRole(): void
    {
        $org = auth()->user()->currentOrganization;
        $user = User::findOrFail($this->confirmingRoleChangeFor);

        // Cannot elevate to owner via settings
        if ($this->newRole === 'owner') {
            $this->addError('role', 'Cannot assign owner role through settings.');
            $this->resetRoleChange();

            return;
        }

        // Cannot change role of last owner
        app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
        $user->unsetRelation('roles');
        if ($user->hasRole('owner')) {
            $ownerCount = $org->users->filter(function ($u) use ($org) {
                app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
                $u->unsetRelation('roles');

                return $u->hasRole('owner');
            })->count();

            if ($ownerCount <= 1) {
                $this->addError('role', 'Cannot change the role of the last owner.');
                $this->resetRoleChange();

                return;
            }
        }

        $fromRole = $user->roles->first()?->name;
        $user->syncRoles([$this->newRole]);

        AuditService::log(
            action: 'organization.member_role_changed',
            organizationId: $org->id,
            resourceType: 'user',
            resourceId: $user->id,
            metadata: [
                'from_role' => $fromRole,
                'to_role' => $this->newRole,
            ],
        );

        $this->resetRoleChange();
    }

    public function confirmRemoval(int $userId): void
    {
        $this->confirmingRemovalFor = $userId;
    }

    public function removeMember(): void
    {
        $org = auth()->user()->currentOrganization;
        $user = User::findOrFail($this->confirmingRemovalFor);

        // Cannot remove last owner
        app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
        $user->unsetRelation('roles');
        if ($user->hasRole('owner')) {
            $ownerCount = $org->users->filter(function ($u) use ($org) {
                app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
                $u->unsetRelation('roles');

                return $u->hasRole('owner');
            })->count();

            if ($ownerCount <= 1) {
                $this->addError('removal', 'Cannot remove the last owner.');
                $this->confirmingRemovalFor = null;

                return;
            }
        }

        // Detach from org workspaces
        $workspaceIds = $org->workspaces()->pluck('id');
        $user->workspaces()->detach($workspaceIds);

        // Detach from organization
        $org->users()->detach($user->id);

        // Remove roles for this org
        $user->roles()->detach();

        // Clear current_organization_id if it was this org
        if ($user->current_organization_id === $org->id) {
            $user->current_organization_id = null;
            $user->save();
        }

        AuditService::log(
            action: 'organization.member_removed',
            organizationId: $org->id,
            resourceType: 'user',
            resourceId: $user->id,
        );

        $this->confirmingRemovalFor = null;
    }

    public function revokeInvitation(int $invitationId): void
    {
        $org = auth()->user()->currentOrganization;
        $invitation = $org->invitations()->findOrFail($invitationId);

        app(InvitationService::class)->revoke($invitation);
    }

    public function resendInvitation(int $invitationId): void
    {
        $org = auth()->user()->currentOrganization;
        $invitation = $org->invitations()->findOrFail($invitationId);

        app(InvitationService::class)->resend($invitation);

        session()->flash('invitation-resent', true);
    }

    private function resetRoleChange(): void
    {
        $this->confirmingRoleChangeFor = null;
        $this->newRole = '';
    }

    public ?int $managingWorkspacesFor = null;
    public string $workspaceSearch = '';
    public ?int $confirmingLastWorkspaceRemoval = null;
    public ?int $lastWorkspaceRemovalWorkspaceId = null;

    public function showWorkspaceManager(int $userId): void
    {
        $this->managingWorkspacesFor = $userId;
        $this->workspaceSearch = '';
    }

    public function getWorkspaceAssignments(int $userId): array
    {
        $org = auth()->user()->currentOrganization;
        $user = $org->users()->findOrFail($userId);
        $workspaces = $org->workspaces()->orderBy('name')->get();
        $assignedIds = $user->workspaces()
            ->where('workspaces.organization_id', $org->id)
            ->pluck('workspaces.id')
            ->toArray();

        app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
        $user->unsetRelation('roles');
        $isImplicit = $user->hasRole(['owner', 'admin']);

        return [
            'workspaces' => $workspaces->map(fn ($ws) => [
                'id' => $ws->id,
                'name' => $ws->name,
                'assigned' => $isImplicit || in_array($ws->id, $assignedIds),
                'implicit' => $isImplicit,
            ])->toArray(),
            'isImplicit' => $isImplicit,
        ];
    }

    public function toggleWorkspaceAssignment(int $userId, int $workspaceId): void
    {
        $org = auth()->user()->currentOrganization;
        $user = $org->users()->findOrFail($userId);
        $workspace = $org->workspaces()->findOrFail($workspaceId);

        app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
        $user->unsetRelation('roles');
        if ($user->hasRole(['owner', 'admin'])) {
            return;
        }

        $isAssigned = $workspace->users()->where('users.id', $userId)->exists();

        if ($isAssigned) {
            $assignedCount = $user->workspaces()
                ->where('workspaces.organization_id', $org->id)
                ->count();

            if ($assignedCount <= 1) {
                $this->confirmingLastWorkspaceRemoval = $userId;
                $this->lastWorkspaceRemovalWorkspaceId = $workspaceId;
                return;
            }

            $workspace->users()->detach($userId);
            $this->dispatch('notify', message: "Removed from {$workspace->name}");
        } else {
            $workspace->users()->attach($userId);
            $this->dispatch('notify', message: "Added to {$workspace->name}");
        }
    }

    public function confirmLastWorkspaceRemoval(): void
    {
        if (! $this->confirmingLastWorkspaceRemoval || ! $this->lastWorkspaceRemovalWorkspaceId) {
            return;
        }

        $org = auth()->user()->currentOrganization;
        $workspace = $org->workspaces()->findOrFail($this->lastWorkspaceRemovalWorkspaceId);
        $workspace->users()->detach($this->confirmingLastWorkspaceRemoval);

        $this->dispatch('notify', message: "Removed from {$workspace->name}");
        $this->confirmingLastWorkspaceRemoval = null;
        $this->lastWorkspaceRemovalWorkspaceId = null;
    }
}; ?>

<x-layouts.app>
    <x-slot:title>User Management</x-slot:title>

    <div class="max-w-4xl mx-auto">
        <h1 class="text-xl font-bold text-zinc-900 dark:text-white mb-1">Settings</h1>
        <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-4">Manage your account settings and preferences.</p>

        <x-settings-tabs active="users" />

        @volt('settings.users.index')
        <div class="mt-6">
            {{-- Header --}}
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-[17px] font-semibold text-zinc-900 dark:text-white">Team Members</h2>
                <flux:button href="{{ route('settings.users.invite') }}" variant="primary" size="sm" icon="plus">
                    Invite User
                </flux:button>
            </div>

            {{-- Members Table --}}
            <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl overflow-hidden">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Name</flux:table.column>
                        <flux:table.column>Email</flux:table.column>
                        <flux:table.column>Role</flux:table.column>
                        <flux:table.column>Status</flux:table.column>
                        <flux:table.column>Last Active</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->getMembers() as $member)
                            <flux:table.row>
                                <flux:table.cell>
                                    <div class="flex items-center gap-2.5">
                                        <x-user-avatar :user="$member->user" />
                                        <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ $member->name }}</span>
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ $member->email }}</span>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <x-role-badge :role="$member->role" />
                                </flux:table.cell>
                                <flux:table.cell>
                                    <div class="flex items-center gap-2">
                                        <div class="w-[7px] h-[7px] rounded-full {{ $member->is_deactivated ? 'bg-zinc-400' : 'bg-green-600' }}"></div>
                                        <span class="text-sm {{ $member->is_deactivated ? 'text-zinc-400' : 'text-zinc-600 dark:text-zinc-300' }}">
                                            {{ $member->is_deactivated ? 'Inactive' : 'Active' }}
                                        </span>
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <span class="text-sm text-zinc-500 dark:text-zinc-400">
                                        {{ $member->last_active_at ? $member->last_active_at->diffForHumans() : 'Never' }}
                                    </span>
                                </flux:table.cell>
                                <flux:table.cell>
                                    @if ($member->role !== 'owner' || auth()->user()->id !== $member->id)
                                        <flux:dropdown>
                                            <flux:button variant="ghost" icon="ellipsis-horizontal" size="sm" />
                                            <flux:menu>
                                                @if ($member->role !== 'owner')
                                                    <flux:menu.heading>Change Role</flux:menu.heading>
                                                    @foreach (['admin', 'editor', 'viewer'] as $role)
                                                        @if ($role !== $member->role)
                                                            <flux:menu.item wire:click="confirmRoleChange({{ $member->id }}, '{{ $role }}')">
                                                                {{ ucfirst($role) }}
                                                            </flux:menu.item>
                                                        @endif
                                                    @endforeach
                                                    <flux:menu.separator />
                                                @endif
                                                <flux:menu.item wire:click="showWorkspaceManager({{ $member->id }})">
                                                    Manage Workspaces
                                                </flux:menu.item>
                                                <flux:menu.item variant="danger" wire:click="confirmRemoval({{ $member->id }})">
                                                    Remove from organization
                                                </flux:menu.item>
                                            </flux:menu>
                                        </flux:dropdown>
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>

            {{-- Role Change Confirmation Modal --}}
            <flux:modal wire:model.self="confirmingRoleChangeFor" class="max-w-sm">
                <div class="space-y-4">
                    <flux:heading>Change Role</flux:heading>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        Are you sure you want to change this user's role to <strong>{{ ucfirst($newRole) }}</strong>?
                    </p>
                    @error('role')
                        <p class="text-sm text-red-600">{{ $message }}</p>
                    @enderror
                    <div class="flex justify-end gap-2">
                        <flux:button wire:click="$set('confirmingRoleChangeFor', null)" variant="ghost">Cancel</flux:button>
                        <flux:button wire:click="changeRole" variant="primary">Confirm</flux:button>
                    </div>
                </div>
            </flux:modal>

            {{-- Remove Member Confirmation Modal --}}
            <flux:modal wire:model.self="confirmingRemovalFor" class="max-w-sm">
                <div class="space-y-4">
                    <flux:heading>Remove Member</flux:heading>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        Are you sure you want to remove this user from the organization? They will lose access to all workspaces.
                    </p>
                    @error('removal')
                        <p class="text-sm text-red-600">{{ $message }}</p>
                    @enderror
                    <div class="flex justify-end gap-2">
                        <flux:button wire:click="$set('confirmingRemovalFor', null)" variant="ghost">Cancel</flux:button>
                        <flux:button wire:click="removeMember" variant="danger">Remove</flux:button>
                    </div>
                </div>
            </flux:modal>

            {{-- Pending Invitations --}}
            @php $invitations = $this->getPendingInvitations(); @endphp
            @if ($invitations->isNotEmpty())
                <h3 class="text-[15px] font-semibold text-zinc-900 dark:text-white mb-3 mt-8">Pending Invitations</h3>
                <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl overflow-hidden">
                    @foreach ($invitations as $invitation)
                        <div class="flex justify-between items-center px-4 py-3.5 {{ ! $loop->last ? 'border-b border-zinc-200 dark:border-zinc-700' : '' }}">
                            <div>
                                <p class="text-[13.5px] font-medium text-zinc-900 dark:text-white">{{ $invitation->email }}</p>
                                <p class="text-xs text-zinc-400 mt-0.5">
                                    Invited as {{ ucfirst($invitation->role) }} &middot; Sent {{ $invitation->created_at->diffForHumans() }}
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <flux:button wire:click="resendInvitation({{ $invitation->id }})" variant="ghost" size="xs">
                                    Resend
                                </flux:button>
                                <flux:button wire:click="revokeInvitation({{ $invitation->id }})" variant="danger" size="xs">
                                    Revoke
                                </flux:button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Workspace Assignment Modal --}}
            <flux:modal wire:model.self="managingWorkspacesFor" class="max-w-sm">
                @if ($managingWorkspacesFor)
                    @php $assignments = $this->getWorkspaceAssignments($managingWorkspacesFor); @endphp
                    <div class="space-y-4">
                        <flux:heading>Manage Workspaces</flux:heading>

                        @if ($assignments['isImplicit'])
                            <p class="text-sm text-zinc-400">Has access to all workspaces via organization role</p>
                        @endif

                        <div class="mb-3">
                            <input type="text" wire:model.live.debounce.300ms="workspaceSearch" placeholder="Search workspaces..."
                                class="w-full text-sm bg-transparent border border-zinc-200 dark:border-zinc-600 rounded-md px-2.5 py-1.5 focus:outline-none focus:ring-1 focus:ring-blue-500 text-zinc-900 dark:text-white placeholder-zinc-400" />
                        </div>

                        <div class="max-h-64 overflow-y-auto">
                            @foreach ($assignments['workspaces'] as $ws)
                                @if (! $workspaceSearch || str_contains(strtolower($ws['name']), strtolower($workspaceSearch)))
                                    <label class="flex items-center gap-3 px-2 py-2 hover:bg-zinc-50 dark:hover:bg-zinc-700 rounded cursor-pointer">
                                        <input type="checkbox"
                                            {{ $ws['assigned'] ? 'checked' : '' }}
                                            {{ $ws['implicit'] ? 'disabled' : '' }}
                                            wire:click="toggleWorkspaceAssignment({{ $managingWorkspacesFor }}, {{ $ws['id'] }})"
                                            class="rounded border-zinc-300 dark:border-zinc-600 text-blue-600 {{ $ws['implicit'] ? 'opacity-50' : '' }}">
                                        <span class="text-sm text-zinc-900 dark:text-white">{{ $ws['name'] }}</span>
                                    </label>
                                @endif
                            @endforeach
                        </div>

                        <div class="flex justify-end">
                            <flux:button wire:click="$set('managingWorkspacesFor', null)" variant="ghost" size="sm">Close</flux:button>
                        </div>
                    </div>
                @endif
            </flux:modal>

            {{-- Last Workspace Removal Warning --}}
            <flux:modal wire:model.self="confirmingLastWorkspaceRemoval" class="max-w-sm">
                <div class="space-y-4">
                    <flux:heading>Remove Last Workspace</flux:heading>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        This user will lose access to all workspaces. They'll be prompted to select a workspace on next login.
                    </p>
                    <div class="flex justify-end gap-2">
                        <flux:button wire:click="$set('confirmingLastWorkspaceRemoval', null)" variant="ghost">Cancel</flux:button>
                        <flux:button wire:click="confirmLastWorkspaceRemoval" variant="danger">Remove</flux:button>
                    </div>
                </div>
            </flux:modal>
        </div>
        @endvolt
    </div>
</x-layouts.app>
