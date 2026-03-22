# Onboarding Wizard Spec

## Overview

Replace revat-v4's single-step organization creation (`/onboarding/create-organization`) with a multi-step onboarding wizard matching the revat-v2 flow. The wizard guides new users through company setup, plan selection, and workspace naming in a clean, step-by-step experience.

**Keep:** Email verification step between registration and onboarding (v4's existing flow).

## Screenshots

Reference screenshots from revat-v2 are in `tasks/screenshots/v2-*.png`.

## Current Flow (v4)

```
Register → Email Verify → Create Organization (name + timezone) → Dashboard
```

- Single onboarding page at `/onboarding/create-organization`
- Workspace auto-created as "{org name} Workspace"
- No plan selection during onboarding (defaults to no plan)
- Uses `onboarding` layout (centered, header with logo)

## Target Flow

```
Register → Email Verify → Step 1: Company → Step 2: Plan → Step 3: Workspace → Dashboard
```

All three steps live on a single route (`/onboarding`) as a Livewire wizard component with client-side step navigation. No page reloads between steps. The form submits once on the final step.

## Detailed Step Design

### Step 1: Create Your Company

- **Heading:** "Create your company"
- **Subtitle:** "Tell us about your business to get started."
- **Fields:**
  - Company name (text input, required, placeholder "Acme Inc.")
  - Timezone (searchable select, default UTC)
- **Buttons:** Continue (validates step 1 fields before advancing)
- **Step indicator:** 3 dots at top, dot 1 filled

### Step 2: Choose Your Plan

- **Heading:** "Choose your plan"
- **Subtitle:** "You can change this anytime. All plans include a 14-day free trial."
- **Fields:**
  - Radio card list showing visible plans from the `plans` table
  - Each card shows: plan name (left), summary like "X users · Y workspaces" (right)
  - First visible plan pre-selected
- **Buttons:** Back (returns to step 1), Continue (advances to step 3)
- **Step indicator:** dot 2 filled

### Step 3: Name Your Workspace

- **Heading:** "Name your workspace"
- **Subtitle:** "Workspaces help you organize your projects and clients."
- **Fields:**
  - Workspace name (text input, required, pre-filled with company name from step 1)
- **Buttons:** Back (returns to step 2), "Create company" (submits entire wizard)
- **Step indicator:** dot 3 filled

## Implementation Plan

### 1. Create new Volt component

**File:** `resources/views/pages/onboarding/wizard.blade.php`

Replace `create-organization.blade.php` (or rename it). Single Volt component with:

```php
public int $step = 1;
public string $companyName = '';
public string $timezone = 'UTC';
public string $planSlug = '';  // first visible plan slug
public string $workspaceName = '';

// Step navigation with per-step validation
public function nextStep(): void;  // validate current step, advance
public function previousStep(): void;  // go back, no validation
public function submit(): void;  // validate step 3, call setup service
```

**Validation rules per step:**
- Step 1: `companyName` required|string|max:255|unique:organizations,name, `timezone` required|timezone:all
- Step 2: `planSlug` required|exists:plans,slug
- Step 3: `workspaceName` required|string|max:255

### 2. Update OrganizationSetupService

Add `plan_id` and custom workspace name to the setup method:

```php
public function setup(User $user, array $data): Organization
```

**Changes to `$data` array:**
- Add `plan_slug` (optional) — looks up Plan by slug, sets `organization.plan_id`
- Add `workspace_name` (optional) — uses this instead of auto-generating "{org} Workspace"
- Keep backward compatibility: if keys absent, behave as before

### 3. Update route

In `routes/web.php`, change the onboarding route:

```php
// Before:
Route::get('/onboarding/create-organization', ...)->name('onboarding.create-organization');

// After:
Route::get('/onboarding', ...)->name('onboarding');
```

Update `EnsureOnboarded` middleware to redirect to the new route name.

### 4. Step indicator component

Create a simple Blade component or inline partial for the 3-dot step indicator:

```html
<div class="flex justify-center gap-2 mb-6">
    @for ($i = 1; $i <= 3; $i++)
        <div class="w-2.5 h-2.5 rounded-full {{ $i <= $currentStep ? 'bg-zinc-900 dark:bg-white' : 'bg-zinc-300 dark:bg-zinc-600' }}"></div>
    @endfor
</div>
```

### 5. Plan card radio group

Use Flux radio inputs styled as cards. Each card is a `<label>` wrapping a hidden radio with visual styling:

```html
<div class="space-y-3">
    @foreach ($plans as $plan)
        <label class="flex items-center justify-between p-4 border rounded-lg cursor-pointer ..."
               wire:key="plan-{{ $plan->slug }}">
            <input type="radio" wire:model.live="planSlug" value="{{ $plan->slug }}" class="sr-only">
            <span class="font-medium">{{ $plan->name }}</span>
            <span class="text-sm text-zinc-500">{{ $plan->max_users }} users · {{ $plan->max_workspaces }} workspaces</span>
        </label>
    @endforeach
</div>
```

Selected state: darker border + ring (matching v2's visual treatment).

### 6. Layout

Reuse the existing `onboarding` layout (`components/layouts/onboarding.blade.php`). No changes needed — it already provides the centered card with logo header.

### 7. Button styling

- **Continue / Create company:** `flux:button variant="primary" class="w-full"` (dark button, full width)
- **Back:** `flux:button variant="ghost"` (text-only, no background)
- When both present, use a flex row: Back on left, primary button on right

### 8. Workspace name auto-fill

When the user completes step 1 and advances, pre-fill `workspaceName` with the value of `companyName` (only if `workspaceName` is still empty). This mirrors v2's behavior.

## Files to Change

| File | Change |
|------|--------|
| `resources/views/pages/onboarding/create-organization.blade.php` | Replace with multi-step wizard (or create new file and delete old) |
| `app/Services/OrganizationSetupService.php` | Accept `plan_slug` and `workspace_name` |
| `routes/web.php` | Update onboarding route path and name |
| `app/Http/Middleware/EnsureOnboarded.php` | Update redirect route name |
| `tests/Feature/Onboarding/` | Add/update tests for wizard flow |

## Files NOT Changed

| File | Reason |
|------|--------|
| `pages/auth/register.blade.php` | Registration form stays the same |
| `pages/auth/verify-email.blade.php` | Email verification stays the same |
| `components/layouts/onboarding.blade.php` | Layout already works for wizard |
| `components/layouts/auth-split.blade.php` | Register page layout unchanged |
| `app/Models/Plan.php` | No model changes needed |
| `database/seeders/PlanSeeder.php` | Existing plans are fine (Free/Starter/Growth/Agency) |

## Edge Cases

- **No visible plans in DB:** Skip step 2, go directly from step 1 to step 3. Set `plan_id` to null.
- **Browser back button:** URL doesn't change between steps (single route), so browser back leaves onboarding entirely. This is acceptable — the middleware will redirect them back.
- **Company name uniqueness failure on submit:** Show validation error on step 1 (navigate back to step 1 with error).
- **Already onboarded user visits /onboarding:** Redirect to dashboard (existing middleware handles this).

## Acceptance Criteria

- [ ] New user sees 3-step wizard after email verification
- [ ] Step 1 validates company name uniqueness and timezone before advancing
- [ ] Step 2 shows all visible plans from DB with radio selection
- [ ] Step 3 pre-fills workspace name with company name
- [ ] Back buttons navigate between steps without losing data
- [ ] "Create company" button creates organization with selected plan and custom workspace name
- [ ] Step indicator dots reflect current step
- [ ] Existing tests pass (update onboarding route references)
- [ ] Wizard uses Flux UI components consistent with rest of v4
