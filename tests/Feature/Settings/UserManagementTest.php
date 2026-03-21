<?php

use App\Mail\InvitationMail;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->workspace = new Workspace(['name' => 'Default']);
    $this->workspace->organization_id = $this->org->id;
    $this->workspace->is_default = true;
    $this->workspace->save();

    $this->owner = User::factory()->create(['email_verified_at' => now()]);
    $this->owner->organizations()->attach($this->org->id);
    $this->owner->current_organization_id = $this->org->id;
    $this->owner->save();
    $this->workspace->users()->attach($this->owner->id);

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $this->owner->assignRole('owner');
});

it('lists organization members', function () {
    $this->actingAs($this->owner)
        ->get(route('settings.users'))
        ->assertOk()
        ->assertSee($this->owner->name)
        ->assertSee('Team Members');
});

it('shows pending invitations', function () {
    $this->org->invitations()->create([
        'email' => 'invited@example.com',
        'role' => 'editor',
        'token_hash' => hash('sha256', 'test-token'),
        'expires_at' => now()->addDays(7),
    ]);

    $this->actingAs($this->owner)
        ->get(route('settings.users'))
        ->assertOk()
        ->assertSee('invited@example.com')
        ->assertSee('Pending Invitations');
});

it('creates invitation via invite form', function () {
    Volt::actingAs($this->owner)
        ->test('settings.users.invite')
        ->set('email', 'newuser@example.com')
        ->set('role', 'editor')
        ->call('invite')
        ->assertHasNoErrors()
        ->assertRedirect(route('settings.users'));

    expect(Invitation::where('email', 'newuser@example.com')->exists())->toBeTrue();
});

it('prevents inviting as owner from invite form', function () {
    Volt::actingAs($this->owner)
        ->test('settings.users.invite')
        ->set('email', 'newuser@example.com')
        ->set('role', 'owner')
        ->call('invite')
        ->assertHasErrors('role');
});

it('changes user role via settings', function () {
    $member = User::factory()->create(['email_verified_at' => now()]);
    $member->organizations()->attach($this->org->id);
    $member->current_organization_id = $this->org->id;
    $member->save();
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $member->assignRole('editor');

    Volt::actingAs($this->owner)
        ->test('settings.users.index')
        ->call('confirmRoleChange', $member->id, 'admin')
        ->call('changeRole');

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $member->unsetRelation('roles');
    expect($member->hasRole('admin'))->toBeTrue();
});

it('cannot remove last owner', function () {
    Volt::actingAs($this->owner)
        ->test('settings.users.index')
        ->call('confirmRemoval', $this->owner->id)
        ->call('removeMember')
        ->assertHasErrors('removal');

    expect($this->org->users()->where('users.id', $this->owner->id)->exists())->toBeTrue();
});

it('logs role change via audit service', function () {
    $member = User::factory()->create(['email_verified_at' => now()]);
    $member->organizations()->attach($this->org->id);
    $member->current_organization_id = $this->org->id;
    $member->save();
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $member->assignRole('editor');

    Volt::actingAs($this->owner)
        ->test('settings.users.index')
        ->call('confirmRoleChange', $member->id, 'admin')
        ->call('changeRole');

    $log = AuditLog::where('action', 'organization.member_role_changed')->first();
    expect($log)->not->toBeNull()
        ->and($log->metadata['from_role'])->toBe('editor')
        ->and($log->metadata['to_role'])->toBe('admin');
});

it('logs member removal via audit service', function () {
    $member = User::factory()->create(['email_verified_at' => now()]);
    $member->organizations()->attach($this->org->id);
    $member->current_organization_id = $this->org->id;
    $member->save();
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $member->assignRole('editor');

    Volt::actingAs($this->owner)
        ->test('settings.users.index')
        ->call('confirmRemoval', $member->id)
        ->call('removeMember');

    $log = AuditLog::where('action', 'organization.member_removed')->first();
    expect($log)->not->toBeNull()
        ->and($log->resource_id)->toBe($member->id);
});

it('denies non-admin users access to user management', function () {
    $viewer = User::factory()->create(['email_verified_at' => now()]);
    $viewer->organizations()->attach($this->org->id);
    $viewer->current_organization_id = $this->org->id;
    $viewer->save();
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $viewer->assignRole('viewer');

    $this->actingAs($viewer)
        ->get(route('settings.users'))
        ->assertForbidden();
});

it('sends invitation email when creating invitation', function () {
    Mail::fake();

    Volt::actingAs($this->owner)
        ->test('settings.users.invite')
        ->set('email', 'mail-test@example.com')
        ->set('role', 'editor')
        ->call('invite');

    Mail::assertSent(InvitationMail::class, function (InvitationMail $mail) {
        return $mail->invitation->email === 'mail-test@example.com'
            && str_contains($mail->acceptUrl, '/invitations/');
    });
});

it('sends invitation email when resending invitation', function () {
    Mail::fake();

    $invitation = $this->org->invitations()->create([
        'email' => 'resend-test@example.com',
        'role' => 'editor',
        'invited_by' => $this->owner->id,
        'token_hash' => hash('sha256', 'old-token'),
        'expires_at' => now()->addDays(7),
    ]);

    Volt::actingAs($this->owner)
        ->test('settings.users.index')
        ->call('resendInvitation', $invitation->id);

    Mail::assertSent(InvitationMail::class, function (InvitationMail $mail) {
        return $mail->invitation->email === 'resend-test@example.com'
            && str_contains($mail->acceptUrl, '/invitations/');
    });
});

it('can toggle workspace assignment for editor', function () {
    $editor = User::factory()->create(['email_verified_at' => now()]);
    $editor->organizations()->attach($this->org->id);
    $editor->current_organization_id = $this->org->id;
    $editor->save();
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $editor->assignRole('editor');

    // Editor starts without workspace assignment
    expect($this->workspace->users()->where('users.id', $editor->id)->exists())->toBeFalse();

    Volt::actingAs($this->owner)
        ->test('settings.users.index')
        ->call('showWorkspaceManager', $editor->id)
        ->call('toggleWorkspaceAssignment', $editor->id, $this->workspace->id)
        ->assertHasNoErrors();

    expect($this->workspace->users()->where('users.id', $editor->id)->exists())->toBeTrue();
});

it('logs invitation creation via audit service', function () {
    Volt::actingAs($this->owner)
        ->test('settings.users.invite')
        ->set('email', 'audit-test@example.com')
        ->set('role', 'editor')
        ->call('invite');

    $log = AuditLog::where('action', 'organization.member_invited')->first();
    expect($log)->not->toBeNull()
        ->and($log->metadata['email'])->toBe('audit-test@example.com')
        ->and($log->metadata['role'])->toBe('editor');
});
