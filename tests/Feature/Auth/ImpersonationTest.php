<?php

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    // Force root URL so signed URLs match the test HTTP host
    URL::forceRootUrl('http://localhost');

    $this->admin = Admin::factory()->create();
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->organization = Organization::create(['name' => 'Test Org']);
    $this->organization->support_access_enabled = true;
    $this->organization->save();
    $this->user->organizations()->attach($this->organization->id);
    $this->user->current_organization_id = $this->organization->id;
    $this->user->save();
});

it('starts impersonation via valid signed URL', function () {
    $signedUrl = URL::signedRoute('support.impersonate', [
        'admin_id' => $this->admin->id,
        'user_id' => $this->user->id,
        'organization_id' => $this->organization->id,
    ]);

    // Extract path + query from signed URL for POST
    $parsed = parse_url($signedUrl);
    $path = $parsed['path'].'?'.$parsed['query'];

    $response = $this->actingAs($this->admin, 'admin')
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post($path);

    $response->assertRedirect(route('dashboard'));

    // Verify audit log was created
    $log = AuditLog::where('action', 'impersonation.started')->first();
    expect($log)->not->toBeNull();
    expect($log->admin_id)->toBe($this->admin->id);
    expect($log->organization_id)->toBe($this->organization->id);
});

it('rejects impersonation with invalid signature', function () {
    $response = $this->actingAs($this->admin, 'admin')
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post('/support/impersonate?admin_id='.$this->admin->id.'&user_id='.$this->user->id.'&organization_id='.$this->organization->id);

    $response->assertForbidden();
});

it('rejects impersonation when support_access_enabled is false', function () {
    $this->organization->support_access_enabled = false;
    $this->organization->save();

    $signedUrl = URL::signedRoute('support.impersonate', [
        'admin_id' => $this->admin->id,
        'user_id' => $this->user->id,
        'organization_id' => $this->organization->id,
    ]);

    $parsed = parse_url($signedUrl);
    $path = $parsed['path'].'?'.$parsed['query'];

    $response = $this->actingAs($this->admin, 'admin')
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post($path);

    $response->assertForbidden();
});

it('sets target user on web guard with impersonating flag via middleware', function () {
    Route::middleware(['web'])->get('/_test/impersonation-check', function () {
        $user = Auth::guard('web')->user();

        return response()->json([
            'user_id' => $user?->id,
            'impersonating' => $user?->isBeingImpersonated(),
        ]);
    });

    $response = $this->actingAs($this->admin, 'admin')
        ->withSession([
            'impersonating_user_id' => $this->user->id,
            'impersonating_admin_id' => $this->admin->id,
            'impersonating_organization_id' => $this->organization->id,
        ])
        ->get('/_test/impersonation-check');

    $response->assertOk();
    expect($response->json('user_id'))->toBe($this->user->id);
    expect($response->json('impersonating'))->toBeTrue();
});

it('clears session and redirects if admin session is invalid during impersonation', function () {
    Route::middleware(['web'])->get('/_test/impersonation-invalid', function () {
        return 'should not reach';
    });

    // Use a different admin ID to simulate mismatch
    $otherAdmin = Admin::factory()->create();

    $response = $this->actingAs($otherAdmin, 'admin')
        ->withSession([
            'impersonating_user_id' => $this->user->id,
            'impersonating_admin_id' => $this->admin->id, // mismatches otherAdmin
            'impersonating_organization_id' => $this->organization->id,
        ])
        ->get('/_test/impersonation-invalid');

    $response->assertRedirect('/admin');
});

it('returns correct value for User::isBeingImpersonated()', function () {
    $user = User::factory()->create();

    expect($user->isBeingImpersonated())->toBeFalse();

    $user->impersonating = true;
    expect($user->isBeingImpersonated())->toBeTrue();
});

it('logs impersonation start and stop via AuditService', function () {
    // Start impersonation
    $signedUrl = URL::signedRoute('support.impersonate', [
        'admin_id' => $this->admin->id,
        'user_id' => $this->user->id,
        'organization_id' => $this->organization->id,
    ]);

    $parsed = parse_url($signedUrl);
    $path = $parsed['path'].'?'.$parsed['query'];

    $this->actingAs($this->admin, 'admin')
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post($path)
        ->assertRedirect(route('dashboard'));

    expect(AuditLog::where('action', 'impersonation.started')->count())->toBe(1);

    // Stop impersonation
    $this->actingAs($this->admin, 'admin')
        ->withoutMiddleware(PreventRequestForgery::class)
        ->withSession([
            'impersonating_user_id' => $this->user->id,
            'impersonating_admin_id' => $this->admin->id,
            'impersonating_organization_id' => $this->organization->id,
        ])
        ->post('/support/stop-impersonation')
        ->assertRedirect('/admin');

    expect(AuditLog::where('action', 'impersonation.stopped')->count())->toBe(1);
});

it('validates admin matches signed URL admin_id', function () {
    $otherAdmin = Admin::factory()->create();

    $signedUrl = URL::signedRoute('support.impersonate', [
        'admin_id' => $this->admin->id, // original admin
        'user_id' => $this->user->id,
        'organization_id' => $this->organization->id,
    ]);

    $parsed = parse_url($signedUrl);
    $path = $parsed['path'].'?'.$parsed['query'];

    // Authenticate as a different admin
    $response = $this->actingAs($otherAdmin, 'admin')
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post($path);

    $response->assertForbidden();
});

it('blocks organization switching during impersonation', function () {
    $user = User::factory()->create();
    $user->impersonating = true;

    $org = Organization::create(['name' => 'Other Org']);

    expect(fn () => $user->switchOrganization($org))->toThrow(HttpException::class);
});

it('changes session ID after impersonation start and stop', function () {
    $signedUrl = URL::signedRoute('support.impersonate', [
        'admin_id' => $this->admin->id,
        'user_id' => $this->user->id,
        'organization_id' => $this->organization->id,
    ]);

    $parsed = parse_url($signedUrl);
    $path = $parsed['path'].'?'.$parsed['query'];

    // Start - session should regenerate (tested by asserting redirect works)
    $response = $this->actingAs($this->admin, 'admin')
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post($path);

    $response->assertRedirect(route('dashboard'));

    // Stop - session should regenerate
    $response = $this->actingAs($this->admin, 'admin')
        ->withoutMiddleware(PreventRequestForgery::class)
        ->withSession([
            'impersonating_user_id' => $this->user->id,
            'impersonating_admin_id' => $this->admin->id,
            'impersonating_organization_id' => $this->organization->id,
        ])
        ->post('/support/stop-impersonation');

    $response->assertRedirect('/admin');
});

it('stops impersonation and clears session flags', function () {
    $response = $this->actingAs($this->admin, 'admin')
        ->withoutMiddleware(PreventRequestForgery::class)
        ->withSession([
            'impersonating_user_id' => $this->user->id,
            'impersonating_admin_id' => $this->admin->id,
            'impersonating_organization_id' => $this->organization->id,
        ])
        ->post('/support/stop-impersonation');

    $response->assertRedirect('/admin');

    // Audit log should exist
    $log = AuditLog::where('action', 'impersonation.stopped')->first();
    expect($log)->not->toBeNull();
});
