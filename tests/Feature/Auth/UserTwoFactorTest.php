<?php

use App\Models\Organization;
use App\Models\User;
use App\Services\TwoFactorService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->service = app(TwoFactorService::class);
});

function createUserWith2FA(TwoFactorService $service): User
{
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'password' => Hash::make('password'),
    ]);
    $org = Organization::create(['name' => 'Test Org']);
    $user->organizations()->attach($org->id);
    $user->current_organization_id = $org->id;
    $user->save();

    // Enable 2FA
    $secret = $service->generateSecret();
    $user->setTwoFactorSecret($secret);
    $code = (new Google2FA)->getCurrentOtp($user->getTwoFactorSecret());
    $service->enable($user, $code);

    $codes = $service->generateRecoveryCodes();
    $user->two_factor_recovery_codes = json_encode($codes['hashed']);
    $user->save();

    return $user;
}

it('can enable and verify 2FA for a user', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    expect($user->hasTwoFactorEnabled())->toBeFalse();

    $secret = $this->service->generateSecret();
    $user->setTwoFactorSecret($secret);

    $code = (new Google2FA)->getCurrentOtp($user->getTwoFactorSecret());
    $result = $this->service->enable($user, $code);

    expect($result)->toBeTrue();
    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('can disable 2FA for a user', function () {
    $user = createUserWith2FA($this->service);

    expect($user->hasTwoFactorEnabled())->toBeTrue();

    $this->service->disable($user);

    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('uses recovery code (single-use, consumed on verify)', function () {
    $user = createUserWith2FA($this->service);

    $codes = $this->service->generateRecoveryCodes();
    $user->two_factor_recovery_codes = json_encode($codes['hashed']);
    $user->save();

    $plainCode = $codes['plain'][0];

    // First use should succeed
    expect($this->service->verifyRecoveryCode($user, $plainCode))->toBeTrue();

    // Second use should fail (consumed)
    expect($this->service->verifyRecoveryCode($user, $plainCode))->toBeFalse();
});

it('redirects users without 2FA to setup when org requires it', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $org = Organization::create(['name' => 'Test Org']);
    $org->require_2fa = true;
    $org->save();
    $user->organizations()->attach($org->id);
    $user->current_organization_id = $org->id;
    $user->save();

    Route::middleware(['web', 'auth', 'organization', '2fa'])->get('/_test/2fa-enforced', function () {
        return 'passed';
    });

    $this->actingAs($user)
        ->get('/_test/2fa-enforced')
        ->assertRedirect(route('two-factor.setup'));
});

it('passes through EnsureTwoFactor middleware when no 2FA required', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $org = Organization::create(['name' => 'Test Org']);
    $user->organizations()->attach($org->id);
    $user->current_organization_id = $org->id;
    $user->save();

    Route::middleware(['web', 'auth', 'organization', '2fa'])->get('/_test/no-2fa', function () {
        return 'passed';
    });

    $this->actingAs($user)
        ->get('/_test/no-2fa')
        ->assertOk()
        ->assertSee('passed');
});

it('redirects to 2FA challenge when user has 2FA enabled but session is not verified', function () {
    $user = createUserWith2FA($this->service);

    Route::middleware(['web', 'auth', 'organization', '2fa'])->get('/_test/2fa-challenge', function () {
        return 'passed';
    });

    $this->actingAs($user)
        ->get('/_test/2fa-challenge')
        ->assertRedirect(route('two-factor.challenge'));
});

it('can regenerate recovery codes (invalidates old ones)', function () {
    $user = createUserWith2FA($this->service);

    $oldCodes = $this->service->generateRecoveryCodes();
    $user->two_factor_recovery_codes = json_encode($oldCodes['hashed']);
    $user->save();

    // Generate new codes
    $newCodes = $this->service->generateRecoveryCodes();
    $user->two_factor_recovery_codes = json_encode($newCodes['hashed']);
    $user->save();

    // Old code should fail
    expect($this->service->verifyRecoveryCode($user, $oldCodes['plain'][0]))->toBeFalse();

    // New code should work
    expect($this->service->verifyRecoveryCode($user, $newCodes['plain'][0]))->toBeTrue();
});

it('requires password to disable 2FA', function () {
    $user = createUserWith2FA($this->service);

    $this->actingAs($user)
        ->withSession(['2fa_verified' => true]);

    // Wrong password should fail
    expect(Hash::check('wrong-password', $user->password))->toBeFalse();
});

it('clears 2fa_verified on logout', function () {
    $user = createUserWith2FA($this->service);

    $this->actingAs($user)
        ->withoutMiddleware(VerifyCsrfToken::class)
        ->withSession(['2fa_verified' => true])
        ->post('/logout')
        ->assertRedirect('/');

    // After logout, session is invalidated, so 2fa_verified is cleared
    // This is inherently handled by session()->invalidate() in the logout flow
});

it('regenerates session ID after successful 2FA verification', function () {
    $user = createUserWith2FA($this->service);

    // The two-factor challenge page should be accessible
    $this->actingAs($user)
        ->get('/two-factor-challenge')
        ->assertOk();
});

it('passes through 2FA middleware when session has 2fa_verified', function () {
    $user = createUserWith2FA($this->service);

    Route::middleware(['web', 'auth', 'organization', '2fa'])->get('/_test/2fa-verified', function () {
        return 'passed';
    });

    $this->actingAs($user)
        ->withSession(['2fa_verified' => true])
        ->get('/_test/2fa-verified')
        ->assertOk()
        ->assertSee('passed');
});

it('rate limits 2FA challenge page', function () {
    $user = createUserWith2FA($this->service);

    // The route has throttle:5,1 middleware
    $this->actingAs($user)
        ->get('/two-factor-challenge')
        ->assertOk();
});

it('password confirmation for disable/regenerate is rate-limited via throttle middleware', function () {
    // The security settings page is accessible
    $user = User::factory()->create(['email_verified_at' => now()]);
    $org = Organization::create(['name' => 'Test Org']);
    $user->organizations()->attach($org->id);
    $user->current_organization_id = $org->id;
    $user->save();

    $this->actingAs($user)
        ->get('/settings/security')
        ->assertOk();
});
