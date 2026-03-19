<?php

use App\Enums\SupportLevel;
use App\Filament\Resources\AlphaInviteResource;
use App\Filament\Resources\WaitlistEntryResource;
use App\Models\Admin;
use App\Models\AlphaInvite;
use App\Models\WaitlistEntry;
use App\Services\AlphaInviteService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    $this->superAdmin = Admin::factory()->create(['support_level' => SupportLevel::Super]);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Mail::fake();
});

// ── Alpha Invite Resource ────────────────────────────────────────────

it('loads alpha invite list page', function () {
    Livewire::actingAs($this->superAdmin, 'admin')
        ->test(AlphaInviteResource\Pages\ListAlphaInvites::class)
        ->assertSuccessful();
})->group('filament');

it('creates an alpha invite and sends email', function () {
    Livewire::actingAs($this->superAdmin, 'admin')
        ->test(AlphaInviteResource\Pages\CreateAlphaInvite::class)
        ->fillForm([
            'email' => 'newtester@example.com',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(AlphaInvite::where('email', 'newtester@example.com')->exists())->toBeTrue();
    Mail::assertSent(\App\Mail\AlphaInviteMail::class);
})->group('filament');

it('shows correct status badges in the list', function () {
    AlphaInvite::factory()->create(['email' => 'pending@test.com']);
    AlphaInvite::factory()->registered()->create(['email' => 'registered@test.com']);
    AlphaInvite::factory()->revoked()->create(['email' => 'revoked@test.com']);

    Livewire::actingAs($this->superAdmin, 'admin')
        ->test(AlphaInviteResource\Pages\ListAlphaInvites::class)
        ->assertCanSeeTableRecords(AlphaInvite::all())
        ->assertSuccessful();
})->group('filament');

it('can resend a pending invite', function () {
    $invite = app(AlphaInviteService::class)->create('resend@example.com');
    $oldHash = $invite->token_hash;

    Livewire::actingAs($this->superAdmin, 'admin')
        ->test(AlphaInviteResource\Pages\ListAlphaInvites::class)
        ->callTableAction('resend', $invite);

    expect($invite->fresh()->token_hash)->not->toBe($oldHash);
    Mail::assertSent(\App\Mail\AlphaInviteMail::class, 2);
})->group('filament');

it('can revoke a pending invite', function () {
    $invite = app(AlphaInviteService::class)->create('revoke@example.com');

    Livewire::actingAs($this->superAdmin, 'admin')
        ->test(AlphaInviteResource\Pages\ListAlphaInvites::class)
        ->callTableAction('revoke', $invite);

    expect($invite->fresh()->revoked_at)->not->toBeNull();
})->group('filament');

// ── Waitlist Entry Resource ──────────────────────────────────────────

it('loads waitlist entry list page', function () {
    Livewire::actingAs($this->superAdmin, 'admin')
        ->test(WaitlistEntryResource\Pages\ListWaitlistEntries::class)
        ->assertSuccessful();
})->group('filament');

it('shows verified and unverified waitlist entries', function () {
    WaitlistEntry::factory()->create(['email' => 'unverified@test.com']);
    WaitlistEntry::factory()->verified()->create(['email' => 'verified@test.com']);

    Livewire::actingAs($this->superAdmin, 'admin')
        ->test(WaitlistEntryResource\Pages\ListWaitlistEntries::class)
        ->assertCanSeeTableRecords(WaitlistEntry::all())
        ->assertSuccessful();
})->group('filament');

it('cannot create waitlist entries', function () {
    expect(WaitlistEntryResource::canCreate())->toBeFalse();
})->group('filament');
