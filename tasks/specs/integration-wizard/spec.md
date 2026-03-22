# Integration Wizard: Upgrade Modal to 4-Step Wizard

## Summary

Replace the current single-modal integration creation flow with a full-page, 4-step wizard (Platform > Credentials > Test > Settings). Keep the existing v4 table-based index page (do not convert to cards). Style the wizard using v4's existing design language. The wizard UX flow is inspired by revat-v2 but the visual style should match v4.

## Motivation

The current v4 modal crams platform selection, credentials, data types, sync interval, and connection testing into a single form. The v2 wizard separates these into focused steps, which:

- Reduces cognitive load per step
- Provides clear progress indication
- Lets users test connections before committing settings
- Gives each platform a visual identity (card with abbreviation badge)
- Supports "back" navigation without losing state

## Current State (v4)

**Integration index** (`/integrations`): Table-based list with columns for name, platform, status, sync status, last synced, data types, actions.

**Add integration**: Modal (`flux:modal`) on the same page with:
- Platform dropdown (select)
- Integration name text input
- Dynamic credential fields
- Data type checkboxes
- Sync interval number input (minutes)
- Inline test connection button
- Single "Create Integration" submit

**Routes**: Single route `/integrations` serving `pages.integrations.index`.

**Files**: Everything in `resources/views/pages/integrations/index.blade.php` (single Volt component, ~406 lines).

## Target State (v2-style wizard)

### Screenshots (from v2 walkthrough)

| Step | Screenshot | Description |
|------|-----------|-------------|
| Index | [step4-integrations-page.png](step4-integrations-page.png) | Card grid with Edit/Mapping/Pause/Delete actions |
| 1. Platform | [step5-add-integration-step1.png](step5-add-integration-step1.png) | Platform selection cards in 2-col grid |
| 2. Credentials | [step6-credentials-step2.png](step6-credentials-step2.png) | Platform-specific credential form |
| 2. Credentials (filled) | [step6b-credentials-filled.png](step6b-credentials-filled.png) | Filled form before continuing |
| 3. Test | [step7-test-step3.png](step7-test-step3.png) | Test Connection button with prompt |
| 3. Test (result) | [step8-test-result.png](step8-test-result.png) | Green success banner with account info |
| 4. Settings | [step9-settings-step4.png](step9-settings-step4.png) | Data Types checkboxes + Sync Frequency dropdown |
| Complete | [step10-integration-created.png](step10-integration-created.png) | Back on index with new card + plan limit banner |

---

## Implementation Spec

### 1. Routes

Add new routes alongside the existing index route:

```php
Route::get('/integrations', fn () => view('pages.integrations.index'))
    ->name('integrations.index')
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
```

### 2. Integration Index Page (Minimal Changes)

**File**: `resources/views/pages/integrations/index.blade.php`

Keep the existing v4 table-based layout. Changes:

- Remove the modal (`flux:modal` and its form) — no longer needed
- Change "+ Add Integration" button from `wire:click="openCreateModal"` to a link to `/integrations/create`
- Add "Edit" action link to `/integrations/{id}/edit` in the actions column
- Add plan limit banner (yellow, above table): "You've used all {N} of your integration slots. Upgrade your plan to add more."
- Remove `openCreateModal()`, `createIntegration()`, `updatedPlatform()`, `testConnection()`, and related properties from the Volt component (move to create page)
- Keep: `syncNow()`, `deleteIntegration()`, `with()`, auto-polling, table rendering

### 3. Create Wizard (New Page)

**File**: `resources/views/pages/integrations/create.blade.php`

Full-page Volt component with 4-step wizard.

#### Component State

```php
public int $step = 1;
public string $platform = '';
public string $name = '';
public string $accountName = ''; // ActiveCampaign-specific
public array $credentials = [];
public array $selectedDataTypes = [];
public int $syncInterval = 60;
public ?array $connectionTestResult = null;
```

#### Step Navigation

- **Stepper bar** at top: `1 Platform > 2 Credentials > 3 Test > 4 Settings`
- Current step: Dark pill (zinc-900 bg, white text)
- Completed steps: Clickable, lighter style
- Future steps: Disabled, muted text
- `goToStep(int $step)`: Can go back to completed steps, cannot skip ahead
- Arrow separators (">") between steps
- Page header: `<- Back` link to `/integrations` + "Add Integration" title

#### Step 1: Platform Selection

- 2-column grid of platform cards
- Each card is a `<button>` that calls `selectPlatform($slug)`
- Card contents:
  - Left: 2-letter badge (rounded, colored background)
  - Middle: Platform name (bold) + description (muted)
  - Right: Chevron arrow
- Selecting a platform sets `$platform`, loads default `$selectedDataTypes` from config, advances to step 2

**Platform display data** (from `config/integrations.php` — extend with UI metadata):

| Platform | Badge | Description |
|----------|-------|-------------|
| ActiveCampaign | AC | Email marketing & CRM automation |
| Voluum | VL | Performance marketing tracker |
| ExpertSender | ES | Email marketing & automation platform |
| Maropost | MP | Email marketing & automation platform |

#### Step 2: Credentials

Platform-specific form fields. The form adapts based on `$platform`:

**ActiveCampaign:**
- Integration Name (text, placeholder: "e.g. My Mailchimp Account")
- Account Name (text, placeholder: "e.g. mycompany (from mycompany.activehosted.com)")
- API Key (password, placeholder: "Enter your API key")

**ExpertSender:**
- Integration Name
- API URL (text)
- API Key (password)

**Maropost:**
- Integration Name
- Account ID (text)
- Auth Token (password)

**Voluum:**
- Integration Name
- Access Key ID (text)
- Access Key Secret (password)

**Navigation:**
- "Back" button: returns to step 1
- "Continue" button: validates fields, advances to step 3

**Validation on Continue:**
- `name`: required, string, max:255
- Platform-specific credential fields: required, string

#### Step 3: Test Connection

- Prompt text: "Test your connection to verify the credentials are correct."
- "Test Connection" button (primary style, with connection icon)
- "Back to Credentials" link below

**On click:**
1. Build temporary (non-persisted) Integration model
2. Resolve connector via `ConnectorRegistry`
3. Call `$connector->testConnection()`
4. Display result

**Success state:**
- Green banner with checkmark icon
- "Connection successful"
- Sub-text: Account identifier (e.g., "Account: jordan@thejorni.com")
- Show "Retry" and "Continue" buttons

**Failure state:**
- Red banner with error icon
- Error message
- Show "Retry" and "Back to Credentials" buttons

**Continue** advances to step 4. User cannot proceed to step 4 without a successful test.

#### Step 4: Settings

**Data Types** section:
- Checkboxes for platform-supported data types
- Pre-checked based on platform defaults from config
- Labels: "Email Campaigns", "Click Tracking", "Conversion Sales" (humanized from snake_case)

**Sync Frequency** section:
- `<select>` dropdown (not a number input) with preset options:
  - Every 15 minutes (15)
  - Every 30 minutes (30)
  - Every hour (60) — default
  - Every 6 hours (360)
  - Every 12 hours (720)
  - Daily (1440)

**Navigation:**
- "Back" button: returns to step 3
- "Save & Connect" button (primary, with checkmark icon): creates integration and redirects

**On Save & Connect:**
1. Validate all fields
2. Check duplicate (workspace + platform + name)
3. Create Integration record
4. Encrypt and store credentials via `setCredentials()`
5. Mark sync started
6. Dispatch `ExtractIntegration` job
7. Redirect to `/integrations` (index page)

### 4. Edit Page (New)

**File**: `resources/views/pages/integrations/edit.blade.php`

Single-page form (not a wizard) for editing an existing integration:

- Integration Name
- Credential fields (pre-filled, password fields show dots)
- Data Types checkboxes
- Sync Frequency dropdown
- Test Connection button with result display
- "Save" and "Cancel" buttons
- Page header: `<- Back` link to `/integrations`

### 5. Mapping Page (New — Future)

**File**: `resources/views/pages/integrations/mapping.blade.php`

Field mapping interface (can be stubbed for now):

- Source field → Target field dropdown rows
- Auto-map button
- Preview panel
- Save button

**Note**: This is shown in the v2 UI as an action button on each integration card. The implementation can be deferred to a separate story, but the route and link should exist.

### 6. Config Changes

Extend `config/integrations.php` with UI metadata per platform:

```php
'platforms' => [
    'activecampaign' => [
        'connector' => ActiveCampaignConnector::class,
        'label' => 'ActiveCampaign',
        'short' => 'AC',
        'description' => 'Email marketing & CRM automation',
        'data_types' => ['campaign_emails', 'campaign_email_clicks'],
        'credential_fields' => [
            ['key' => 'account_name', 'label' => 'Account Name', 'type' => 'text', 'placeholder' => 'e.g. mycompany (from mycompany.activehosted.com)'],
            ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'placeholder' => 'Enter your API key'],
        ],
    ],
    // ... similar for other platforms
],

'sync_frequency_options' => [
    15 => 'Every 15 minutes',
    30 => 'Every 30 minutes',
    60 => 'Every hour',
    360 => 'Every 6 hours',
    720 => 'Every 12 hours',
    1440 => 'Daily',
],

'data_type_labels' => [
    'campaign_emails' => 'Email Campaigns',
    'campaign_email_clicks' => 'Click Tracking',
    'conversion_sales' => 'Conversions / Sales',
],
```

### 7. Integration Limit Enforcement

Add plan limit check to the index page and create wizard:

- **Index page**: Show yellow banner when at capacity, disable "+ Add Integration" button
- **Create wizard**: Check limit on mount, redirect back with error flash if at capacity
- Logic: Count active integrations for workspace vs plan limit (default: 2)

---

## File Changes Summary

| Action | File |
|--------|------|
| **Modify** | `resources/views/pages/integrations/index.blade.php` (remove modal, add edit link, keep table) |
| **Create** | `resources/views/pages/integrations/create.blade.php` (4-step wizard) |
| **Create** | `resources/views/pages/integrations/edit.blade.php` (edit form) |
| **Create** | `resources/views/pages/integrations/mapping.blade.php` (stub) |
| **Modify** | `routes/web.php` (add create/edit/mapping routes) |
| **Modify** | `config/integrations.php` (add UI metadata, sync frequency options, data type labels) |

## No Backend Changes Required

The existing backend is sufficient:
- `Integration` model, migration, and encryption already work
- `ConnectorRegistry` and `testConnection()` already work
- `ExtractIntegration` job dispatch already works
- `WorkspaceContext` already works
- All 4 platform connectors already work

This is purely a **frontend/UX upgrade** — replacing the modal with a multi-page wizard and upgrading the index layout.

## Acceptance Criteria

1. `/integrations` keeps the existing table layout, with modal removed and "+ Add Integration" linking to `/integrations/create`
2. "+ Add Integration" navigates to `/integrations/create`
3. Create wizard has 4 distinct steps with stepper navigation
4. Step 1 shows platform cards in a 2-col grid
5. Step 2 shows platform-specific credential fields
6. Step 3 tests connection and shows success/failure with account info
7. Step 4 offers data type checkboxes and sync frequency dropdown (not number input)
8. "Save & Connect" creates integration and redirects to index
9. Plan limit banner appears when at capacity
10. Users can navigate back to previous steps without losing form data
11. Users cannot skip ahead past untouched steps
12. `/integrations/{id}/edit` shows a single-page edit form
13. `/integrations/{id}/mapping` route exists (can be stub)
