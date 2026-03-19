<?php

use App\Http\Controllers\BillingController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\SwitchOrganizationController;
use App\Http\Controllers\SwitchWorkspaceController;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Placeholder routes for middleware redirects
Route::get('/organization/select', function () {
    return 'Please select an organization.';
})->name('organization.select')->middleware('auth');

Route::get('/workspace/none', function () {
    return 'You do not have access to any workspaces.';
})->name('workspace.none')->middleware('auth');

// Placeholder admin 2FA challenge route
Route::get('/admin/two-factor-challenge', function () {
    return 'Two-factor challenge page';
})->name('admin.two-factor.challenge');

// ── App Routes ─────────────────────────────────────────────────────────
Route::middleware(['auth', 'verified', 'onboarded', 'organization', 'workspace'])->group(function () {
    Route::get('/dashboard', fn () => view('pages.dashboard'))
        ->name('dashboard');

    Route::get('/reports', fn () => view('pages.reports.index'))
        ->name('reports')
        ->middleware('can:view');

    Route::get('/reports/campaign-revenue', fn () => view('pages.reports.campaign-revenue'))
        ->name('reports.campaign-revenue')
        ->middleware('can:view');

    Route::get('/campaigns/emails', fn () => view('pages.campaigns.emails'))
        ->name('campaigns.emails')
        ->middleware('can:view');

    Route::get('/campaigns/email-clicks', fn () => view('pages.campaigns.email-clicks'))
        ->name('campaigns.email-clicks')
        ->middleware('can:view');

    Route::get('/campaigns', fn () => redirect()->route('campaigns.emails'))
        ->name('campaigns');

    Route::get('/conversions/sales', fn () => view('pages.conversions.sales'))
        ->name('conversions.sales')
        ->middleware('can:view');

    Route::get('/attribution', fn () => redirect()->route('attribution.stats'))
        ->name('attribution');

    Route::get('/attribution/programs', fn () => view('pages.attribution.programs'))
        ->name('attribution.programs')
        ->middleware('can:view');

    Route::get('/attribution/initiatives', fn () => view('pages.attribution.initiatives'))
        ->name('attribution.initiatives')
        ->middleware('can:view');

    Route::get('/attribution/efforts', fn () => view('pages.attribution.efforts'))
        ->name('attribution.efforts')
        ->middleware('can:view');

    Route::get('/attribution/connectors', fn () => view('pages.attribution.connectors'))
        ->name('attribution.connectors')
        ->middleware('can:view');

    Route::get('/attribution/stats', fn () => view('pages.attribution.stats'))
        ->name('attribution.stats')
        ->middleware('can:view');

    Route::get('/attribution/clicks', fn () => view('pages.attribution.clicks'))
        ->name('attribution.clicks')
        ->middleware('can:view');

    Route::get('/attribution/conversion-sales', fn () => view('pages.attribution.conversions'))
        ->name('attribution.conversion-sales')
        ->middleware('can:view');

    Route::get('/integrations', fn () => view('pages.integrations.index'))
        ->name('integrations')
        ->middleware('can:integrate');

    Route::get('/integrations/create', fn () => view('pages.integrations.create'))
        ->name('integrations.create')
        ->middleware('can:integrate');

    Route::get('/integrations/{integration}/edit', fn () => view('pages.integrations.edit'))
        ->name('integrations.edit')
        ->middleware('can:integrate');

    Route::get('/integrations/{integration}/mapping', fn () => view('pages.integrations.mapping'))
        ->name('integrations.mapping')
        ->middleware('can:integrate');

    Route::redirect('/conversion-sales', '/conversions/sales', 301);
});

// ── Auth Routes ─────────────────────────────────────────────────────────
Route::middleware('guest')->group(function () {
    Route::get('/login', fn () => view('pages.auth.login'))
        ->name('login')
        ->middleware('throttle:5,1');

    Route::get('/register', fn () => view('pages.auth.register'))
        ->name('register')
        ->middleware('throttle:3,1');

    Route::get('/waitlist/verify', fn () => view('pages.waitlist-verify'))
        ->name('waitlist.verify')
        ->middleware('throttle:10,1');

    Route::get('/forgot-password', fn () => view('pages.auth.forgot-password'))
        ->name('password.request')
        ->middleware('throttle:3,1');

    Route::get('/reset-password/{token}', fn ($token) => view('pages.auth.reset-password', ['token' => $token]))
        ->name('password.reset');
});

// ── Email Verification Routes ───────────────────────────────────────────
Route::middleware('auth')->group(function () {
    Route::get('/email/verify', fn () => view('pages.auth.verify-email'))
        ->name('verification.notice');

    Route::get('/email/verify/{id}/{hash}', function (EmailVerificationRequest $request) {
        $request->fulfill();

        return redirect()->route('dashboard');
    })->name('verification.verify')->middleware(['signed', 'throttle:6,1']);

    Route::post('/email/resend', function () {
        Auth::user()->sendEmailVerificationNotification();

        return back()->with('status', 'verification-link-sent');
    })->name('verification.send')->middleware('throttle:6,1');
});

// ── Two-Factor Authentication Routes ────────────────────────────────────
Route::middleware('auth')->group(function () {
    Route::get('/two-factor-challenge', fn () => view('pages.auth.two-factor-challenge'))
        ->name('two-factor.challenge')
        ->middleware('throttle:5,1');

    Route::get('/two-factor-setup', fn () => view('pages.auth.two-factor-setup'))
        ->name('two-factor.setup');
});

// ── Onboarding Routes ──────────────────────────────────────────────────
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/onboarding', fn () => view('pages.onboarding.create-organization'))
        ->name('onboarding')
        ->middleware('throttle:5,1');
});

// ── Settings Routes ────────────────────────────────────────────────────
Route::middleware(['auth', 'verified', 'onboarded', 'organization'])->prefix('settings')->group(function () {
    // User-scoped settings
    Route::get('/profile', fn () => view('pages.settings.profile'))
        ->name('settings.profile');
    Route::get('/password', fn () => view('pages.settings.password'))
        ->name('settings.password');
    Route::get('/appearance', fn () => view('pages.settings.appearance'))
        ->name('settings.appearance');
    Route::get('/security', fn () => view('pages.settings.security'))
        ->name('settings.security');

    // Org-scoped settings (require manage permission)
    Route::get('/organization', fn () => view('pages.settings.organization'))
        ->name('settings.organization')
        ->middleware('can:manage');
    Route::get('/users', fn () => view('pages.settings.users.index'))
        ->name('settings.users')
        ->middleware('can:manage');
    Route::get('/users/invite', fn () => view('pages.settings.users.invite'))
        ->name('settings.users.invite')
        ->middleware('can:manage');
    Route::get('/workspaces', fn () => view('pages.settings.workspaces.index'))
        ->name('settings.workspaces')
        ->middleware('can:manage');
    Route::get('/workspaces/{workspace}/users', fn (Workspace $workspace) => view('pages.settings.workspaces.users', ['workspace' => $workspace]))
        ->name('settings.workspaces.users')
        ->middleware('can:manage');
    Route::get('/support-access', fn () => view('pages.settings.support-access'))
        ->name('settings.support-access')
        ->middleware('can:billing');

    Route::get('/organization/security', fn () => view('pages.settings.organization.security'))
        ->name('settings.organization.security')
        ->middleware('can:manage');
});

// ── Context Switching Routes ────────────────────────────────────────────
Route::middleware(['auth', 'verified', 'onboarded', 'organization', 'throttle:30,1'])->group(function () {
    Route::post('/switch-organization/{organization}', SwitchOrganizationController::class)
        ->name('switch-organization');
    Route::post('/switch-workspace/{workspace}', SwitchWorkspaceController::class)
        ->name('switch-workspace');
});

// ── Invitation Routes ───────────────────────────────────────────────────
Route::get('/alpha-agreement', fn () => view('pages.alpha-agreement'))
    ->name('alpha.agreement');

Route::get('/invitations/{token}', fn (string $token) => view('pages.auth.accept-invitation', ['token' => $token]))
    ->name('invitations.accept')
    ->middleware('throttle:10,1');

// ── Billing Routes ──────────────────────────────────────────────────────
Route::middleware(['auth', 'verified', 'organization'])->prefix('billing')->group(function () {
    Route::get('/', fn () => view('pages.billing.index'))->name('billing');
    Route::get('/subscribe', fn () => view('pages.billing.subscribe'))->name('billing.subscribe');
});

Route::middleware(['auth', 'verified', 'organization', 'can:billing'])->prefix('billing')->group(function () {
    Route::post('/checkout', [BillingController::class, 'checkout'])->name('billing.checkout');
    Route::put('/swap', [BillingController::class, 'swap'])->name('billing.swap');
    Route::post('/cancel', [BillingController::class, 'cancel'])->name('billing.cancel');
    Route::post('/resume', [BillingController::class, 'resume'])->name('billing.resume');
    Route::get('/portal', [BillingController::class, 'portal'])->name('billing.portal');
});

// ── Stripe Webhook Route ────────────────────────────────────────────────
// Excluded from CSRF verification: Stripe webhooks are server-to-server
// calls authenticated via HMAC-SHA256 signature (STRIPE_WEBHOOK_SECRET).
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handleWebhook'])
    ->name('stripe.webhook');

// ── Impersonation Routes ────────────────────────────────────────────────
Route::post('/support/impersonate', [ImpersonationController::class, 'start'])
    ->name('support.impersonate');

Route::post('/support/stop-impersonation', [ImpersonationController::class, 'stop'])
    ->name('support.stop-impersonation');

Route::post('/logout', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect('/');
})->name('logout')->middleware('auth');

// Layout preview routes (for smoke testing)
if (app()->environment('local', 'testing')) {
    Route::prefix('_layouts')->group(function () {
        Route::get('/auth-split', fn () => view('pages.layout-previews.auth-split'))
            ->name('preview.auth-split');
        Route::get('/auth-card', fn () => view('pages.layout-previews.auth-card'))
            ->name('preview.auth-card');
        Route::get('/auth-simple', fn () => view('pages.layout-previews.auth-simple'))
            ->name('preview.auth-simple');
        Route::get('/onboarding', fn () => view('pages.layout-previews.onboarding'))
            ->name('preview.onboarding');
        Route::get('/app', fn () => view('pages.layout-previews.app'))
            ->name('preview.app');
    });

    // Test-only auto-login route for Playwright browser tests
    Route::get('/_test/login/{userId}', function (int $userId) {
        $user = User::findOrFail($userId);
        Auth::login($user);

        return redirect('/dashboard');
    })->name('test.login');
}
