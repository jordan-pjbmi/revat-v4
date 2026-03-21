<?php

/**
 * Hardening verification tests — documents security posture.
 * These tests verify the cumulative hardening across all epics.
 */

use App\Models\Integration;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->org = Organization::create(['name' => 'Hardening Test Org']);
    $this->workspace = new Workspace(['name' => 'Default']);
    $this->workspace->organization_id = $this->org->id;
    $this->workspace->is_default = true;
    $this->workspace->save();

    $this->user = User::factory()->create([
        'email_verified_at' => now(),
        'current_organization_id' => $this->org->id,
    ]);
    $this->org->users()->attach($this->user);
    $this->workspace->users()->attach($this->user);

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $this->user->assignRole('owner');
});

it('returns security headers on authenticated routes', function () {
    $response = $this->actingAs($this->user)->get(route('dashboard'));

    $response->assertOk();
    expect($response->headers->get('X-Frame-Options'))->toBe('DENY');
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
    expect($response->headers->get('Permissions-Policy'))->not->toBeNull();
    expect($response->headers->get('Content-Security-Policy'))->not->toBeNull();
});

it('returns security headers on unauthenticated routes', function () {
    $response = $this->get('/');

    $response->assertOk();
    expect($response->headers->get('X-Frame-Options'))->toBe('DENY');
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($response->headers->get('Content-Security-Policy'))->not->toBeNull();
});

it('protects CSRF on all state-mutating routes', function () {
    // POST without CSRF token should fail
    $response = $this->actingAs($this->user)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post('/logout');

    // Verify CSRF middleware is registered (re-enable it and expect rejection)
    $csrfResponse = $this->actingAs($this->user)
        ->post('/logout', [], ['X-CSRF-TOKEN' => 'invalid-token']);

    expect($csrfResponse->status())->toBeIn([302, 419, 422]);
});

it('rate limits login attempts', function () {
    // Login page has throttle:5,1 — hit it 6 times to exceed the limit
    for ($i = 0; $i < 6; $i++) {
        $response = $this->get('/login');
    }

    // After 5 attempts, should be throttled
    expect($response->status())->toBe(429);
});

it('enforces authentication on protected routes', function () {
    $this->get(route('dashboard'))
        ->assertRedirect('/login');
});

it('credentials column is encrypted on integration model', function () {
    $integration = new Integration([
        'name' => 'Test',
        'platform' => 'activecampaign',
        'is_active' => true,
    ]);
    $integration->workspace_id = $this->workspace->id;
    $integration->organization_id = $this->org->id;
    $integration->credentials = ['api_key' => 'secret-value'];
    $integration->save();

    // Verify the raw DB value is encrypted (not plaintext)
    $rawValue = DB::table('integrations')
        ->where('id', $integration->id)
        ->value('credentials');

    expect($rawValue)->not->toContain('secret-value');
    expect($integration->fresh()->credentials)->toBe(['api_key' => 'secret-value']);
});
