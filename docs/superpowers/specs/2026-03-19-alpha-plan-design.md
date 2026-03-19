# Alpha Plan Auto-Assignment

## Purpose

Auto-assign a hidden, unlimited "Alpha" plan to alpha testers so they have no plan limits during the alpha period. Alpha users skip the plan selection step during onboarding.

## Data

Add an `alpha` plan (via seeder or migration):

| Field | Value |
|-------|-------|
| name | Alpha |
| slug | alpha |
| max_workspaces | 999 |
| max_users | 999 |
| max_integrations_per_workspace | 999 |
| is_visible | false |
| sort_order | -1 |

The plan is hidden from public plan selection (`is_visible: false`) and the billing/checkout flow.

## Alpha User Detection

A user is an alpha user if their email exists in `alpha_invites` where `registered_at IS NOT NULL`. This is checked via `AlphaInvite::where('email', $user->email)->whereNotNull('registered_at')->exists()`.

## Onboarding Change

In `resources/views/pages/onboarding/create-organization.blade.php`:

- On `mount()`, check if the current user is an alpha user (via the detection above).
- If alpha user: skip step 2 (plan selection). The step counter goes from step 1 (company name/timezone) directly to step 3 (workspace name). The `planSlug` is not set from user input.
- If not alpha user: existing behavior unchanged.

## OrganizationSetupService Change

In `app/Services/OrganizationSetupService.php`, method `setup()`:

- Before the existing plan resolution logic, check if the user's email is in `alpha_invites` (registered).
- If yes: force `plan_slug = 'alpha'`, overriding whatever was passed in `$data['plan_slug']`.
- If no: existing behavior unchanged.

This is the single enforcement point — even if the onboarding wizard somehow passes a different plan, the service overrides it.

## No Changes to Plan Enforcement

The existing `UserLimitService`, `WorkspaceLimitService`, and `IntegrationLimitService` all read limits from `plan->max_*` fields. With limits set to 999, alpha users are effectively unlimited. No enforcement code changes needed.

## New Files

None — this modifies existing files only.

## Modified Files

- `database/seeders/PlanSeeder.php` — add alpha plan
- `resources/views/pages/onboarding/create-organization.blade.php` — skip step 2 for alpha users
- `app/Services/OrganizationSetupService.php` — force alpha plan for alpha users

## Removal Plan

When alpha ends:

1. Migrate alpha users' organizations to a real plan (via artisan command or admin action).
2. Delete the alpha plan row from the `plans` table.
3. Remove the alpha check from `OrganizationSetupService::setup()`.
4. Remove the step-skip logic from the onboarding wizard.
