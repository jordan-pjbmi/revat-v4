# Laravel 13 + PHP 8.5 Upgrade Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Upgrade Revat v4 from Laravel 12 / PHP 8.4 to Laravel 13 / PHP 8.5, and install Laravel Boost for AI-assisted development.

**Architecture:** Big-bang upgrade on a feature branch. Update composer constraints, resolve dependencies, apply breaking change fixes (CSRF middleware rename, cache prefix pinning), install Boost, then verify with full test suite.

**Tech Stack:** Laravel 13, PHP 8.5, Filament 5.4, Pest 4, Laravel Boost 2.x

**Spec:** `docs/superpowers/specs/2026-03-21-laravel13-php85-upgrade-design.md`

**Rollback:** `git checkout main && composer install && php artisan optimize:clear`

---

## File Map

| Action | File | Responsibility |
|---|---|---|
| Modify | `composer.json` | Version constraints |
| Modify | `.env.example` | Pin cache/session prefixes |
| Modify | `.env.schema` | Pin cache/session prefixes with annotations |
| Manual | `.env` | Pin cache/session prefixes (local — not agent-edited per Varlock rules) |
| Modify | `app/Providers/Filament/AdminPanelProvider.php` | CSRF middleware rename |
| Modify | `tests/E2E/Pipeline/HardeningVerificationTest.php` | CSRF middleware rename |
| Modify | `tests/Feature/Settings/ContextSwitchingTest.php` | CSRF middleware rename |
| Modify | `tests/Feature/Billing/PlanSwapTest.php` | CSRF middleware rename |
| Modify | `tests/Feature/Billing/BillingAuthorizationTest.php` | CSRF middleware rename |
| Modify | `tests/Feature/Billing/CheckoutTest.php` | CSRF middleware rename |
| Modify | `tests/Feature/Auth/AuthPagesTest.php` | CSRF middleware rename |
| Modify | `tests/Feature/Auth/EmailVerificationTest.php` | CSRF middleware rename |
| Modify | `tests/Feature/Auth/UserTwoFactorTest.php` | CSRF middleware rename |
| Modify | `tests/Feature/Auth/ImpersonationTest.php` | CSRF middleware rename |
| Modify | `.gitignore` | Add Boost generated files |

---

### Task 1: Create Feature Branch

**Files:** None

- [ ] **Step 1: Create and switch to feature branch**

```bash
git checkout -b feature/laravel13-php85-upgrade
```

- [ ] **Step 2: Verify branch**

Run: `git branch --show-current`
Expected: `feature/laravel13-php85-upgrade`

---

### Task 2: Update Composer Constraints

**Files:**
- Modify: `composer.json:9,11-13`

- [ ] **Step 1: Update PHP constraint**

In `composer.json`, change:
```json
"php": "^8.2"
```
to:
```json
"php": "^8.3"
```

- [ ] **Step 2: Update Laravel framework constraint**

In `composer.json`, change:
```json
"laravel/framework": "^12.0"
```
to:
```json
"laravel/framework": "^13.0"
```

- [ ] **Step 3: Update Filament constraint**

In `composer.json`, change:
```json
"filament/filament": "^5.0"
```
to:
```json
"filament/filament": "^5.4"
```

- [ ] **Step 4: Update Cashier constraint**

In `composer.json`, change:
```json
"laravel/cashier": "^16.4"
```
to:
```json
"laravel/cashier": "^16.5"
```

- [ ] **Step 5: Add PHPUnit to require-dev**

In `composer.json` `require-dev`, add:
```json
"phpunit/phpunit": "^12.0"
```

Note: If this causes a conflict with Pest (which manages PHPUnit transitively), remove this explicit constraint and let Pest control the PHPUnit version.

---

### Task 3: Resolve Dependencies

**Files:**
- Modify: `composer.json` (only if Saloon patch needed)

- [ ] **Step 1: Attempt composer update (dry run)**

Run: `composer update --with-all-dependencies --dry-run`
Expected: All packages resolve successfully.

If resolution fails, check the error. Common blockers:
- `saloonphp/laravel-plugin` — see Steps 2-3
- `pragmarx/google2fa-laravel` — check for a newer version or replace
- `spatie/laravel-csp` — check for a newer version
- `pestphp/pest` + PHPUnit 12 conflict — remove explicit `phpunit/phpunit` from `require-dev`

- [ ] **Step 2: If Saloon blocks — check for updated version**

Run: `composer show saloonphp/laravel-plugin --available`
If a version with `^13.0` illuminate support exists, no action needed.

- [ ] **Step 3: If Saloon still blocks — apply inline alias**

In `composer.json` `require`, temporarily add:
```json
"saloonphp/laravel-plugin": "3.9.x-dev as 3.9.99"
```
Move the existing `saloonphp/laravel-plugin` entry from its current location.

- [ ] **Step 4: Run composer update for real**

Run: `composer update --with-all-dependencies`
Expected: All packages install successfully. Note any deprecation warnings.

- [ ] **Step 5: Verify key package versions**

Run: `composer show laravel/framework | head -2 && composer show filament/filament | head -2 && composer show pestphp/pest | head -2`
Expected: Laravel 13.x, Filament 5.4.x, Pest 4.x

- [ ] **Step 6: Commit dependency update**

```bash
git add composer.json composer.lock
git commit -m "chore: bump Laravel 13, PHP 8.3+, Filament 5.4, Cashier 16.5, PHPUnit 12"
```

---

### Task 4: Pin Cache/Session Prefixes

**Files:**
- Modify: `.env.example:42`
- Modify: `.env.schema:75,83`

> **Important — Session cookie impact:** `SESSION_COOKIE` was never explicitly set before. Laravel's default produces `revat-session` (via `Str::slug(APP_NAME).'-session'`). Setting `SESSION_COOKIE=revat-session` changes the cookie name, which **will log out all existing user sessions**. If this is unacceptable, use `revat-session` instead to match the current implicit default.

- [ ] **Step 1: Update .env.example**

Replace:
```
# CACHE_PREFIX=
```
with:
```
CACHE_PREFIX=revat_v4_cache_
REDIS_PREFIX=revat_v4_
SESSION_COOKIE=revat-session
```

Also adds `REDIS_PREFIX` to the template so new developers get the pinned value.

- [ ] **Step 2: Update .env.schema — add CACHE_PREFIX**

After the `CACHE_STORE=redis` line (line 83), add:
```
# Cache key prefix — pinned to prevent L13 delimiter change
CACHE_PREFIX=revat_v4_cache_
```

- [ ] **Step 3: Update .env.schema — add SESSION_COOKIE**

After the `SESSION_DOMAIN=` line (line 75), add:
```
# Session cookie name — pinned to prevent L13 delimiter change
SESSION_COOKIE=revat-session
```

- [ ] **Step 4: Manually update .env (not agent-automated)**

The developer must manually add `CACHE_PREFIX=revat_v4_cache_` and `SESSION_COOKIE=revat-session` to their local `.env` file. Agents must not read or edit `.env` directly per Varlock rules.

- [ ] **Step 5: Commit**

```bash
git add .env.example .env.schema
git commit -m "chore: pin CACHE_PREFIX and SESSION_COOKIE to prevent L13 delimiter change"
```

---

### Task 5: Rename VerifyCsrfToken to PreventRequestForgery

**Files:**
- Modify: `app/Providers/Filament/AdminPanelProvider.php:18,53`
- Modify: `tests/E2E/Pipeline/HardeningVerificationTest.php:13,60`
- Modify: `tests/Feature/Settings/ContextSwitchingTest.php` (import + all `withoutMiddleware` calls)
- Modify: `tests/Feature/Billing/PlanSwapTest.php:7,55,79`
- Modify: `tests/Feature/Billing/BillingAuthorizationTest.php:6,52,64,76,88,94`
- Modify: `tests/Feature/Billing/CheckoutTest.php:6,53,65,72`
- Modify: `tests/Feature/Auth/AuthPagesTest.php:5,154`
- Modify: `tests/Feature/Auth/EmailVerificationTest.php:5,77,81`
- Modify: `tests/Feature/Auth/UserTwoFactorTest.php:7,162`
- Modify: `tests/Feature/Auth/ImpersonationTest.php:7,39,53,73,142,150,176,203,210,223`

- [ ] **Step 1: Update AdminPanelProvider**

In `app/Providers/Filament/AdminPanelProvider.php`:

Replace import:
```php
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
```
with:
```php
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
```

Replace usage:
```php
VerifyCsrfToken::class,
```
with:
```php
PreventRequestForgery::class,
```

- [ ] **Step 2: Update all test files**

In each of the 9 test files, apply the same two replacements:

Import: `VerifyCsrfToken` → `PreventRequestForgery`
Usage: `VerifyCsrfToken::class` → `PreventRequestForgery::class`

Files:
1. `tests/E2E/Pipeline/HardeningVerificationTest.php`
2. `tests/Feature/Settings/ContextSwitchingTest.php`
3. `tests/Feature/Billing/PlanSwapTest.php`
4. `tests/Feature/Billing/BillingAuthorizationTest.php`
5. `tests/Feature/Billing/CheckoutTest.php`
6. `tests/Feature/Auth/AuthPagesTest.php`
7. `tests/Feature/Auth/EmailVerificationTest.php`
8. `tests/Feature/Auth/UserTwoFactorTest.php`
9. `tests/Feature/Auth/ImpersonationTest.php`

- [ ] **Step 3: Verify no remaining references**

Run: `grep -r "VerifyCsrfToken" app/ tests/ --include="*.php"`
Expected: No matches.

- [ ] **Step 4: Commit**

```bash
git add app/Providers/Filament/AdminPanelProvider.php tests/
git commit -m "chore: rename VerifyCsrfToken to PreventRequestForgery (L13)"
```

---

### Task 6: Run Test Suite

**Files:** None

- [ ] **Step 1: Clear all caches**

Run: `php artisan optimize:clear`
Expected: Configuration, route, view, and event caches cleared.

- [ ] **Step 2: Run full Pest test suite**

Run: `php artisan test`
Expected: All tests pass.

- [ ] **Step 3: If tests fail — diagnose and fix**

Check failure output. Common issues:
- Deprecated class references missed by the grep
- Cache serialization issues (review `serializable_classes` config if objects are cached)
- Queue event signature changes (`$exceptionOccurred` → `$exception`, `$connection` → `$connectionName`)

- [ ] **Step 4: Commit any fixes**

Stage only the specific files you changed, then commit:
```bash
git commit -m "fix: resolve test failures from L13 upgrade"
```

---

### Task 7: Install Laravel Boost

**Files:**
- Modify: `composer.json` (post-update-cmd)
- Modify: `.gitignore`

- [ ] **Step 1: Install Boost**

Run: `composer require laravel/boost --dev`
Expected: Package installs successfully.

- [ ] **Step 2: Run Boost installer**

Run: `php artisan boost:install`
Expected: Generates configuration files. Note the exact files created — the list below is based on documentation and may vary.

- [ ] **Step 3: Add generated files to .gitignore**

Check what `boost:install` created, then add generated/auto-regenerated files to `.gitignore`. Expected entries:
```
boost.json
```

Verify against actual output from Step 2.

- [ ] **Step 4: Add post-update-cmd hook**

In `composer.json`, add to the `post-update-cmd` array (after the existing `vendor:publish` entry):
```json
"@php artisan boost:update --ansi"
```

- [ ] **Step 5: Configure Claude Code MCP**

Run: `claude mcp add -s local -t stdio laravel-boost php artisan boost:mcp`
Expected: MCP server registered for Claude Code.

Verify against actual Boost documentation — command syntax may differ.

- [ ] **Step 6: Verify Boost works**

Run: `php artisan boost:mcp --help` (or equivalent)
Expected: Shows available MCP tools/commands.

- [ ] **Step 7: Commit**

```bash
git add composer.json composer.lock .gitignore
git commit -m "chore: install Laravel Boost for AI-assisted development"
```

---

### Task 8: Final Verification

**Files:** None

- [ ] **Step 1: Clear caches again**

Run: `php artisan optimize:clear`

- [ ] **Step 2: Run full test suite one more time**

Run: `php artisan test`
Expected: All tests pass.

- [ ] **Step 3: Verify key framework version**

Run: `php artisan --version`
Expected: `Laravel Framework 13.x.x`

- [ ] **Step 4: Smoke test checklist**

Manually verify (or via existing E2E tests):
- [ ] Filament admin panel loads at `/admin`
- [ ] Livewire/Volt pages render correctly
- [ ] Auth flows work (login, 2FA, email verification)
- [ ] Billing/checkout flows work
- [ ] Horizon dashboard accessible
- [ ] Pulse dashboard accessible
- [ ] Queue jobs dispatch and process
- [ ] Saloon API connectors function (especially if constraint was patched)

- [ ] **Step 5: Final commit if any smoke test fixes needed**

Stage only the specific files you changed, then commit:
```bash
git commit -m "fix: resolve smoke test issues from L13 upgrade"
```
