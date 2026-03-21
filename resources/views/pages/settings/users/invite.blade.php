<?php

use App\Mail\InvitationMail;
use App\Services\AuditService;
use App\Services\InvitationService;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Component;

new class extends Component
{
    public string $email = '';

    public string $role = 'editor';

    public function invite(InvitationService $service): void
    {
        $this->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'in:admin,editor,viewer'],
        ]);

        $org = auth()->user()->currentOrganization;

        // Check if user is already a member
        if ($org->users()->where('email', $this->email)->exists()) {
            $this->addError('email', 'This user is already a member of the organization.');

            return;
        }

        $invitation = $service->create($org, $this->email, $this->role, auth()->user());

        $acceptUrl = route('invitations.accept', ['token' => $invitation->plaintext_token]);
        Mail::send(new InvitationMail($invitation, $acceptUrl));

        AuditService::log(
            action: 'organization.member_invited',
            organizationId: $org->id,
            metadata: [
                'email' => $this->email,
                'role' => $this->role,
            ],
        );

        session()->flash('invitation-sent', true);

        $this->redirect(route('settings.users', absolute: false), navigate: true);
    }
}; ?>

<x-layouts.app>
    <x-slot:title>Invite User</x-slot:title>

    <div class="max-w-4xl mx-auto">
        <h1 class="text-xl font-bold text-zinc-900 dark:text-white mb-1">Settings</h1>
        <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-4">Manage your account settings and preferences.</p>

        <x-settings-tabs active="users" />

        @volt('settings.users.invite')
        <div class="mt-6 max-w-lg">
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-white mb-4">Invite User</h2>

            <form wire:submit="invite" class="space-y-6">
                <flux:input
                    wire:model="email"
                    label="Email address"
                    type="email"
                    placeholder="user@example.com"
                    required
                />

                <flux:select wire:model="role" label="Role">
                    <flux:select.option value="admin">Admin</flux:select.option>
                    <flux:select.option value="editor">Editor</flux:select.option>
                    <flux:select.option value="viewer">Viewer</flux:select.option>
                </flux:select>

                <div class="flex items-center gap-3">
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                        Send Invitation
                    </flux:button>
                    <flux:button href="{{ route('settings.users') }}" variant="ghost">
                        Cancel
                    </flux:button>
                </div>
            </form>
        </div>
        @endvolt
    </div>
</x-layouts.app>
