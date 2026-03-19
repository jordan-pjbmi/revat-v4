# CI/CD Pipeline & Production Safeguards

## Purpose

Establish a CI/CD pipeline and production safeguards for Revat, deployed on a dedicated OVH server with staging and production environments. The workflow is heavily agent-driven (Claude Code agents), so guardrails must protect production while enabling autonomous agent workflows.

## Environments

| Environment | Domain | Trigger | Purpose |
|-------------|--------|---------|---------|
| Development | localhost | — | Local Ubuntu desktop |
| Staging | `staging.revat.io` | Auto-deploy on merge to `main` | Verify deploys before production |
| Production | `app.revat.io` | Manual trigger (git tag or workflow dispatch) | Live application |

`revat.io` is reserved for marketing/blog/SEO — not the application.

## Server Environment Layout

The OVH dedicated server (Intel Xeon-E 2386G, 32GB ECC RAM, 2×512GB NVMe RAID 1) runs both staging and production as isolated deployments.

```
/var/www/
├── staging.revat.io/
│   ├── releases/              # timestamped release dirs (keep last 5)
│   │   ├── 20260319120000/
│   │   └── 20260319140000/
│   ├── current -> releases/20260319140000   # symlink to active release
│   ├── shared/
│   │   ├── storage/           # persistent Laravel storage (logs, cache, framework)
│   │   └── .env               # staging environment variables
│   └── .env.schema            # for varlock validation
├── app.revat.io/
│   ├── releases/
│   ├── current -> releases/...
│   ├── shared/
│   │   ├── storage/
│   │   └── .env
│   └── .env.schema
```

### Isolation

- **MySQL databases:** `revat_v4_staging` and `revat_v4_production` (local dev uses `revat-v4` per `.env.schema` — server databases use underscores for MySQL compatibility)
- **Redis databases:** DB 0 for production, DB 1 for staging (queues, cache, sessions isolated)
- **Nginx vhosts:** separate configs for `staging.revat.io` and `app.revat.io`
- **Horizon processes:** separate supervisor per environment, each reading its own `.env`

### Server `.env` Provisioning

The shared `.env` files for each environment are manually created during initial server setup. They are not deployed from the repo — they persist in `shared/` across releases. The `.env.schema` file is copied into each deployment root for reference. `npx varlock load` is not run on the server; the `.env` files are maintained manually and must contain all required variables from `.env.schema` with environment-appropriate values (production database, Redis DB, app URL, etc.).

## CI Pipeline (GitHub Actions)

Triggered on every push to a PR branch and on merge to `main`.

```
PR opened/pushed → Pint (format check)
                 → Larastan (static analysis)
                 → Pest (tests + architecture tests)
                 → Migration safety scan

All pass → agent can merge to main
Any fail → merge blocked
```

### Jobs

1. **Pint** — `vendor/bin/pint --test` (check-only mode, fails if code isn't formatted)
2. **Larastan** — `vendor/bin/phpstan analyse` (catches type errors, undefined methods, wrong argument counts). Start at **level 5** with a generated `phpstan-baseline.neon` to grandfather existing issues. Raise the level incrementally over time.
3. **Pest** — `vendor/bin/pest` (unit, feature, architecture tests against a MySQL test database on the GitHub runner). E2E/browser tests are excluded from CI — they require a running app server and are run locally or in staging.
4. **Frontend build check** — `npm ci && npm run build` (verifies Vite config and frontend assets compile successfully).
5. **Migration safety scan** — scan files added or modified in the PR diff under `database/migrations/` for `dropColumn`, `dropTable`, `renameColumn`, `change()`. If found, add a `destructive-migration` label and require review from a designated code owner (configured via `CODEOWNERS` file). Comments in migration files are excluded from the scan.

### Nightly Scheduled Run

- Mutation testing via `vendor/bin/pest --mutate`
- Results posted to a dedicated Slack channel

### Runner

GitHub-hosted Ubuntu runner. Free tier provides 2,000 minutes/month for private repos.

### CI Caching

Use `actions/cache` for `vendor/` (keyed on `composer.lock`) and `node_modules/` (keyed on `package-lock.json`) to avoid reinstalling dependencies on every run.

### Deploy Concurrency

GitHub Actions workflows for staging and production deploys use `concurrency` groups (one per environment) to prevent simultaneous deploys if multiple merges happen in quick succession.

## CD Pipeline (Laravel Envoy)

### Staging — Auto-deploy on merge to `main`

```
merge to main → GitHub Action SSHs to server
             → envoy run deploy --on=staging
             → health check (HTTP 200 on staging.revat.io)
             → success: Slack notification
             → failure: auto-rollback + Slack alert
```

### Production — Manual trigger only

```
git tag v1.x.x → GitHub Action triggers (or manual workflow_dispatch)
              → envoy run deploy --on=production
              → health check (HTTP 200 on app.revat.io)
              → success: Slack notification
              → failure: auto-rollback + Slack alert
```

### Envoy Deploy Task Steps

1. `git clone` the release into `releases/{timestamp}/`
2. `composer install --no-dev --optimize-autoloader`
3. `npm ci && npm run build`
4. Link shared dirs (`storage/`, `.env`) into the release
5. `php artisan migrate --force`
6. `php artisan horizon:terminate` (graceful queue drain before symlink swap)
7. `php artisan optimize` (caches config, routes, views, events)
8. Swap `current` symlink to new release
9. Reload PHP-FPM (picks up new code via updated symlink)
10. Health check — `curl -sf https://{domain}/up` (Laravel's built-in health endpoint, verifies app boots and database connects)
11. If health check fails: swap symlink back, reload PHP-FPM, restart Horizon, alert via Slack

Note: Horizon is terminated before the symlink swap (step 6) to avoid a race condition where old workers process new jobs. After PHP-FPM reload, Horizon's supervisor (systemd) automatically restarts workers against the new release.

### Release Retention

Keep last 5 releases per environment, prune older ones after successful deploy.

## Restore Methods

### Release Rollback (instant, for bad deploys)

- Envoy `rollback` task swaps the `current` symlink to the previous release
- Restarts PHP-FPM and Horizon
- Does **not** rollback migrations (handle manually if a migration caused the problem)
- Triggered manually: `envoy run rollback --on=production`

### Database Restore (for data-level recovery)

- Daily automated MySQL dumps to OVH Object Storage (compressed, timestamped)
- Restore script downloads a specific backup and imports it
- Triggered manually: `envoy run db:restore --backup=2026-03-19-020000 --on=production`
- Before restoring, automatically takes a snapshot of the current database (so you can undo the restore)

### Full Restore (release + database, catastrophic recovery)

- Combines both: restore a specific database backup, then rollback to a matching release
- Manual process, not automated — requires choosing which backup matches which release

### Restore Safeguards

- Restore commands require explicit environment flag (`--on=production`) — no default
- Database restore takes a pre-restore snapshot automatically
- Slack notification sent on any restore action

### SOP

A Standard Operating Procedure document will be created covering step-by-step instructions for each restore scenario (release rollback, database restore, full restore).

## Backups

### Automated Daily MySQL Dumps

- Run at 02:00 UTC via cron on the server
- Dump both `revat_v4_production` and `revat_v4_staging` databases
- Compress with gzip, timestamp the filename (e.g., `production-2026-03-19-020000.sql.gz`)
- Upload to OVH Object Storage bucket
- Retain last 30 days, auto-delete older backups

### Pre-deploy Snapshot

- Before every production deploy, take a quick MySQL dump
- Stored locally in `/var/backups/revat/pre-deploy/`
- Keeps last 5 (matches release retention)
- Provides a matched pair: release + database state at deploy time
- Note: these are on the same server — if the disks fail, these are lost. The daily off-site backups to OVH Object Storage are the real safety net.

### Pre-restore Snapshot

- Before any database restore, automatically dump the current database
- Prevents "the restore made it worse and now I can't get back"

### What's NOT Backed Up

- **Redis** — cache/queue/sessions are ephemeral and rebuildable
- **Laravel storage/logs** — low value, logs rotate at 14 days

## Agent Safeguards

### Branch Protection on `main`

- PRs required (no direct pushes)
- CI status checks must pass before merge
- No force pushes

### Claude Code Hooks (`.claude/settings.json`)

Create `.claude/settings.json` at the repo root (committed and shared) with deny rules:

- Block `git push --force` and `git push -f`
- Block `git reset --hard`
- Block direct pushes to `main`
- Block commits containing `.env` files
- Block `git tag` and `git push --tags` (production deploys are human-only)

### Agent Workflow Rules

- Agents work in feature branches only
- Agents can create PRs and merge to `main` after CI passes
- Agents **cannot** trigger production deploys (tag creation is human-only)
- Agents **cannot** run restore commands

### Slack Notifications

- On merge to `main` — what was merged, by whom (agent or human)
- On staging deploy — success or failure with rollback status
- On production deploy — success or failure
- On any restore action
- Nightly mutation testing results

## Monitoring

### Laravel Pulse (already installed)

- Dashboard accessible at `/pulse` in production
- Monitors slow queries, slow requests, exceptions, queue health, server resources
- No additional setup needed beyond ensuring it runs in production

### Uptime Monitoring

- UptimeRobot (free tier) — HTTP ping `app.revat.io` and `staging.revat.io` every 5 minutes
- Alerts via Slack if either goes down
- Also monitors the health check endpoint (`/up` — Laravel's built-in)

### Horizon Dashboard

- Accessible at `/horizon` in production (behind admin auth)
- Monitors queue workers, job throughput, failed jobs, wait times

### Future Considerations

- **OpenTelemetry** — revisit when connector integrations are live and need tracing across external APIs
- **Sentry** — revisit when user count grows and error tracking at scale is needed

## New Files

| File | Purpose |
|------|---------|
| `.github/workflows/ci.yml` | CI pipeline (Pint, Larastan, Pest, migration scan) |
| `.github/workflows/deploy-staging.yml` | Auto-deploy staging on merge to main |
| `.github/workflows/deploy-production.yml` | Manual production deploy on tag/dispatch |
| `.github/workflows/nightly.yml` | Mutation testing schedule |
| `Envoy.blade.php` | Deploy, rollback, and restore task definitions |
| `phpstan.neon` | Larastan configuration |
| `phpstan-baseline.neon` | Grandfathered existing Larastan issues |
| `CODEOWNERS` | Require human review for destructive migrations |
| `docs/sop/restore-procedures.md` | Standard Operating Procedure for restore scenarios |

## Modified Files

| File | Change |
|------|--------|
| `.claude/settings.json` | Add hooks for agent safeguards |
| `composer.json` | Add `larastan/larastan` dev dependency |

## Dependencies

| Tool | Purpose | Cost |
|------|---------|------|
| GitHub Actions | CI/CD runner | Free (2,000 min/month private repos) |
| Laravel Envoy | SSH task runner for deploys | Free (composer package) |
| Larastan | Static analysis | Free (composer package) |
| OVH Object Storage | Backup storage | ~€0.01/GB/month |
| UptimeRobot | Availability monitoring | Free tier |
| Slack webhook | Notifications (URL stored as GitHub Actions secret and in server `.env`) | Free |
| Cloudflare | SSL/DNS | Free tier |
