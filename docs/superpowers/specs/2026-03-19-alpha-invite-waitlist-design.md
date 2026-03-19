# Alpha Invite & Waitlist System

## Purpose

Gate registration behind invite-only access for the alpha release. Users without an invite can join a waitlist with double opt-in email verification. Admins manage both invites and the waitlist through the Filament admin panel.

## Data Model

### `alpha_invites`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| email | varchar(254), unique | |
| token_hash | varchar(64), unique | SHA-256 of plaintext token |
| last_sent_at | timestamp | Set on create and updated on resend |
| registered_at | timestamp, nullable | Set when invitee completes registration |
| revoked_at | timestamp, nullable | Set when admin revokes the invite |
| timestamps | created_at, updated_at | |

### `waitlist_entries`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| email | varchar(254), unique | |
| verification_token_hash | varchar(64), unique, nullable | SHA-256 of verification token; null after verified |
| verified_at | timestamp, nullable | Set when email confirmed |
| timestamps | created_at, updated_at | |

### `alpha_agreements`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| email | varchar(254) | Email of the person who agreed (matches alpha_invite email) |
| agreement_version | varchar(20) | Version string, e.g. "1.0" |
| accepted_at | timestamp | When the user checked the box |
| ip_address | varchar(45) | IPv4 or IPv6 address at time of acceptance |
| user_agent | text | Browser user-agent string for audit trail |
| timestamps | created_at, updated_at | |

Index on `(email, agreement_version)` — unique pair, one acceptance per version per email.

## Registration Route Behavior

The `/register` Volt component checks for a `token` query parameter:

- **No token** → render waitlist signup form
- **Valid token** → render registration form with email pre-filled and locked
- **Invalid/used token** → render waitlist form with error message ("This invite link is no longer valid")

Token validation: hash the provided plaintext token with SHA-256, look up in `alpha_invites` where `registered_at IS NULL` and `revoked_at IS NULL`.

## Alpha Invite Flow

1. Admin opens `AlphaInviteResource` in Filament, clicks "New Alpha Invite", enters email.
2. `AlphaInvite` model created: generate 64-char random token via `Str::random(64)`, store `hash('sha256', $token)` in `token_hash`, set `last_sent_at` to now.
3. Send `AlphaInviteMail` to the email with link: `/register?token={plaintext}`.
4. User clicks link → registration form renders with email pre-filled from the invite record, email field is read-only.
5. Below the password fields, a clickwrap agreement: unchecked checkbox + "I agree to the [Alpha Testing Agreement](/alpha-agreement)" link. The checkbox must be checked to submit.
6. On registration submit: validate token again, then:
   - Record agreement acceptance in `alpha_agreements` (email, version, timestamp, IP, user-agent) — this happens before user creation so the audit record exists regardless of what follows.
   - If a User with that email already exists: mark invite as `registered_at = now`, redirect to `/login` with flash message "You already have an account. Please log in."
   - Otherwise: create user, set `registered_at` on the `AlphaInvite`, dispatch `Registered` event (triggers email verification), log user in, redirect to dashboard.
7. Normal onboarding flow continues (create organization, etc.).

### Resend

Admin can trigger "Resend" action on a pending invite. This regenerates the token (new random value, new hash), updates `last_sent_at`, and re-sends the email. Old token becomes invalid.

### Revoke

Admin can trigger "Revoke" action on a pending invite. This sets `revoked_at` — the token becomes invalid and the invite shows as revoked in the table.

## Waitlist Flow

1. Visitor hits `/register` with no token → sees waitlist form (email field + "Join the Waitlist" button).
2. Submit:
   - If email not in `waitlist_entries`: create row, generate 64-char verification token, store SHA-256 hash, send `WaitlistVerificationMail` with link `/waitlist/verify?token={plaintext}`.
   - If email exists but not verified: resend only if `updated_at` is older than 5 minutes (per-email cooldown to prevent abuse). Otherwise, show generic success without sending.
   - If email exists and verified: show same generic success message (no information leak).
3. On-screen message: "Check your email to confirm your spot on the waitlist."
4. User clicks verification link → `GET /waitlist/verify?token={plaintext}`:
   - Hash token, look up in `waitlist_entries` where `verified_at IS NULL`.
   - If valid: set `verified_at`, clear `verification_token_hash` (nullable, no longer needed).
   - Send `WaitlistConfirmedMail` ("You're on the Revat waitlist, we'll be in touch").
   - Render success page: "You're confirmed! We'll let you know when it's your turn."
   - If invalid: render error page ("This link is no longer valid").
5. No expiry on verification tokens.

## Filament Admin Resources

### AlphaInviteResource

- **Table columns:** email, last_sent_at, status badge (pending/registered/revoked), agreement badge (signed/unsigned), registered_at, created_at.
- **Create form:** email field (single field).
- **Actions:** resend invite (pending only), revoke invite (pending only).
- **Filters:** status (pending vs. registered vs. revoked).
- **Search:** by email.
- **Relationship:** agreement status derived from `alpha_agreements` table (joined on email + current version).

### WaitlistEntryResource (view-only)

- **Table columns:** email, status badge (unverified/verified), verified_at, created_at.
- **No create/edit pages** — read-only list.
- **Filters:** status (unverified vs. verified).
- **Search:** by email.

## Emails

| Mailable | Trigger | Content |
|----------|---------|---------|
| `AlphaInviteMail` | Admin creates/resends invite | "You've been invited to try Revat" + registration link |
| `WaitlistVerificationMail` | User submits waitlist form | "Confirm your email" + verification link |
| `WaitlistConfirmedMail` | User verifies waitlist email | "You're on the waitlist, we'll be in touch" |

## Alpha Agreement

### Approach: Clickwrap with audit trail

Compliant with eIDAS (EU electronic signature regulation) as a "simple electronic signature" and French civil code (Articles 1366-1367). Sufficient for alpha/beta testing agreements.

### Requirements

- **Unchecked by default** — GDPR requires affirmative action, no pre-ticked boxes.
- **Terms visible before acceptance** — link to full agreement text opens in new tab.
- **Audit record** — email, agreement version, timestamp, IP address, user-agent stored in `alpha_agreements`.
- **Agreement recorded before user creation** — ensures the audit trail exists even if subsequent steps fail.
- **Versioned** — agreement version stored as a config value (e.g., `config('alpha.agreement_version')`). When the agreement text changes, bump the version. Existing users are not re-prompted (alpha is short-lived).

### Agreement content

The agreement text itself lives at a public route `GET /alpha-agreement` — a simple Blade page rendering the terms. The admin manages the content by updating the Blade view. No CMS needed for alpha.

## Routes

- `GET /waitlist/verify` — public route (guest middleware), no auth required. Handles waitlist email verification.
- `GET /alpha-agreement` — public route, no auth required. Renders the alpha testing agreement text.
- The `/register` route retains its existing middleware (`guest`).

## Rate Limiting

- Waitlist form submission: `throttle:3,1` (3 requests per minute per IP) — prevents email bombing.
- `GET /waitlist/verify`: `throttle:10,1` (10 requests per minute per IP).
- Per-email cooldown on waitlist resend: 5 minutes between verification emails for the same address.

## Security

- Tokens are 64-char random strings; only SHA-256 hashes stored in the database.
- Registration form validates token server-side before rendering the form.
- Email field is locked (read-only) when registering via invite token — prevents using someone else's token for a different email.
- Waitlist form shows generic success message regardless of email state (prevents enumeration).
- Account enumeration prevention on existing registration logic is preserved.
- Rate limiting on all email-triggering endpoints (see above).

## New Files

- `app/Models/AlphaInvite.php`
- `app/Models/AlphaAgreement.php`
- `app/Models/WaitlistEntry.php`
- `database/migrations/xxxx_create_alpha_invites_table.php`
- `database/migrations/xxxx_create_alpha_agreements_table.php`
- `database/migrations/xxxx_create_waitlist_entries_table.php`
- `app/Filament/Resources/AlphaInviteResource.php` (+ List/Create pages)
- `app/Filament/Resources/WaitlistEntryResource.php` (+ List page)
- `app/Mail/AlphaInviteMail.php` + blade view
- `app/Mail/WaitlistVerificationMail.php` + blade view
- `app/Mail/WaitlistConfirmedMail.php` + blade view
- `resources/views/pages/alpha-agreement.blade.php` (agreement text page)
- Modify: `resources/views/pages/auth/register.blade.php` (add token check + waitlist form + agreement checkbox)
- New routes: `GET /waitlist/verify`, `GET /alpha-agreement`

## Removal Plan

When opening registration to the public:

1. Remove token check, waitlist form, and agreement checkbox from the registration Volt component (restore original registration form).
2. Remove the `GET /waitlist/verify` and `GET /alpha-agreement` routes.
3. Keep `alpha_agreements` table as a permanent audit record. Optionally drop `alpha_invites` and `waitlist_entries` tables and remove Filament resources.
4. No changes to User model, organization invitations, or any core system.
