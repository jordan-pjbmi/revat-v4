# Laravel 13 + PHP 8.5 Upgrade Design

## Overview

Upgrade Revat v4 from Laravel 12 / PHP 8.4 to Laravel 13 / PHP 8.5 in a single big-bang pass, plus install `laravel/boost` for AI-assisted development tooling.

## Motivation

Stay current and secure. Laravel 13 released March 17, 2026. PHP 8.5 released November 2025. The ecosystem is mature — all major dependencies support both.

## Current State

| Component | Current | Target |
|---|---|---|
| PHP (runtime) | 8.4.18 | 8.5 |
| PHP (constraint) | `^8.2` | `^8.3` |
| laravel/framework | `^12.0` | `^13.0` |
| filament/filament | `^5.0` | `^5.4` |
| laravel/cashier | `^16.4` | `^16.5` |
| phpunit/phpunit | (transitive) | `^12.0` |
| laravel/boost | not installed | `^2.3` (dev) |

Packages requiring no version bump (already compatible): livewire/flux, livewire/volt, laravel/horizon, laravel/pulse, spatie/laravel-permission, spatie/laravel-csp, pragmarx/google2fa-laravel, saloonphp/saloon, pestphp/pest, nunomaduro/collision.

**Verify at upgrade time:** Confirm `pragmarx/google2fa-laravel`, `spatie/laravel-csp`, and `pestphp/pest` (with PHPUnit 12) resolve without conflicts. Third-party packages with tight framework constraints may need bumps.

## Potential Blocker

`saloonphp/laravel-plugin` v3.9.x does not yet declare `^13.0` in its illuminate constraints (check `vendor/saloonphp/laravel-plugin/composer.json`). If this blocks composer resolution, use a temporary composer inline alias (e.g., `"saloonphp/laravel-plugin": "3.9.x-dev as 3.9.99"` in `require`). Remove the workaround once an official update ships.

## Breaking Changes Affecting This Codebase

### High Impact: VerifyCsrfToken Renamed

`Illuminate\Foundation\Http\Middleware\VerifyCsrfToken` → `Illuminate\Foundation\Http\Middleware\PreventRequestForgery`

**Affected files:**
- `app/Providers/Filament/AdminPanelProvider.php` (import + usage)
- 9 test files using `->withoutMiddleware(VerifyCsrfToken::class)`:
  - `tests/E2E/Pipeline/HardeningVerificationTest.php`
  - `tests/Feature/Settings/ContextSwitchingTest.php`
  - `tests/Feature/Billing/PlanSwapTest.php`
  - `tests/Feature/Billing/BillingAuthorizationTest.php`
  - `tests/Feature/Billing/CheckoutTest.php`
  - `tests/Feature/Auth/AuthPagesTest.php`
  - `tests/Feature/Auth/EmailVerificationTest.php`
  - `tests/Feature/Auth/UserTwoFactorTest.php`
  - `tests/Feature/Auth/ImpersonationTest.php`

### Medium Impact: Cache/Session Prefix Delimiter Change

Laravel 13 changes the default delimiter from underscore to hyphen for cache prefixes, Redis prefixes, and session cookie names. Existing cached data would be orphaned if prefixes change.

**Current state:** `REDIS_PREFIX=revat_v4_` is already pinned in `.env.schema`. `CACHE_PREFIX` exists commented out in `.env`/`.env.example` but is absent from `.env.schema`. `SESSION_COOKIE` is not set anywhere. Both must be explicitly pinned in `.env`, `.env.example`, and `.env.schema` to preserve existing values.

### Low Impact: Polymorphic Pivot Table Pluralization

Laravel 13 generates pluralized names for polymorphic pivot tables. `AttributionResult` and `SummaryAttributionByCampaign` use `morphTo()` (not polymorphic many-to-many with pivot tables), so this change does not apply. Verify during testing.

### No Impact: PHP 8.5 Deprecations

No app code uses `__sleep`/`__wakeup`, non-canonical casts (`(boolean)`, `(integer)`, etc.), or backtick shell_exec. No action required.

## Rollback Plan

This is a big-bang upgrade on a feature branch. If anything fails:
1. `git checkout main` to return to the pre-upgrade state
2. `composer install` to restore the old lock file
3. `php artisan optimize:clear` to flush stale caches

## Upgrade Steps

### Step 1: Create Feature Branch

```bash
git checkout -b feature/laravel13-php85-upgrade
```

### Step 2: Update composer.json Constraints

Update `composer.json`:
```json
"php": "^8.3",
"laravel/framework": "^13.0",
"filament/filament": "^5.4",
"laravel/cashier": "^16.5"
```

Add to `require-dev`:
```json
"phpunit/phpunit": "^12.0"
```

### Step 3: Resolve Saloon Constraint (if needed)

If `saloonphp/laravel-plugin` blocks resolution:
1. Check if a newer version has been released with `^13.0` support
2. If not, add a temporary composer inline alias: `"saloonphp/laravel-plugin": "3.9.x-dev as 3.9.99"`
3. Track the issue and remove the workaround once resolved

### Step 4: Run Composer Update

```bash
composer update --with-all-dependencies
```

Verify all packages resolve. Check for conflicts with `pragmarx/google2fa-laravel`, `spatie/laravel-csp`, and Pest + PHPUnit 12 compatibility.

### Step 5: Pin Cache/Session Prefixes

Add to `.env`, `.env.example`, and `.env.schema`:
```
CACHE_PREFIX=revat_v4_cache_
SESSION_COOKIE=revat_v4_session
```

Ensure `REDIS_PREFIX=revat_v4_` remains as-is.

### Step 6: VerifyCsrfToken → PreventRequestForgery

Find-and-replace across all affected files:
- `use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;` → `use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;`
- `VerifyCsrfToken::class` → `PreventRequestForgery::class`

### Step 7: Install Laravel Boost

```bash
composer require laravel/boost --dev
php artisan boost:install
```

Configure Claude Code MCP:
```bash
claude mcp add -s local -t stdio laravel-boost php artisan boost:mcp
```

Add to `composer.json` post-update-cmd (after the existing `vendor:publish` entry):
```json
"@php artisan boost:update --ansi"
```

Add generated files to `.gitignore`:
```
boost.json
```

Note: Verify exact command names and generated file names against the package documentation at install time.

### Step 8: Clear Caches & Run Full Test Suite

```bash
php artisan optimize:clear
php artisan test
```

Smoke test:
- Filament admin panel loads and renders
- Billing/checkout flows work
- Auth flows (login, 2FA, email verification, impersonation)
- Horizon dashboard accessible
- Pulse dashboard accessible
- Livewire/Volt pages render correctly
- Queue jobs dispatch and process via Horizon
- Saloon API connectors function correctly (especially if constraint was patched)
- Polymorphic relations on `AttributionResult` and `SummaryAttributionByCampaign` resolve correctly

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Saloon plugin blocks composer | Medium | Inline alias patch; monitor for release |
| Cache invalidation from prefix change | Low | Explicitly pin all prefix env vars before upgrading |
| Filament breaking changes in 5.0→5.4 | Low | Review Filament 5.4 changelog |
| Pest 4 + PHPUnit 12 incompatibility | Low | Verify during composer update; bump Pest if needed |
| google2fa-laravel / laravel-csp constraints | Low | Verify during composer update; bump or replace if needed |
| Undocumented L13 breaking changes | Low | Full test suite + smoke testing |

## Out of Scope

- Adopting new Laravel 13 features (PHP attributes on controllers/jobs, AI SDK, passkeys, event streaming, etc.) — these are additive and can be adopted incrementally later
- PHP 8.5 new features (pipe operator, clone-with, etc.) — adopt opportunistically in future work
- `laravel/ai` SDK — not needed for current platform requirements
