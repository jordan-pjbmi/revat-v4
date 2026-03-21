# Workspace Management UX Improvements

## Overview

A holistic UX pass on workspace management to improve discoverability, reduce friction, add automation (implicit access for elevated roles), and increase convenience for common workspace/user assignment operations.

## Goals

- Make workspace management discoverable from multiple entry points
- Eliminate manual steps for owner/admin workspace access
- Support both workspace-centric and user-centric member assignment
- Add bulk operations and convenience features (search, pin, recent, copy members)
- Keep workspace creation simple (name only)

## Non-Goals

- Workspace-level permissions (roles remain org-scoped)
- Workspace templates or presets beyond "copy members from"
- Nested or hierarchical workspaces

---

## 1. Implicit Access for Owner/Admin Roles

### Behavior

`accessibleWorkspaceIds()` checks the user's organization role first. If the user holds `owner` or `admin`, return all workspace IDs for the org without querying `workspace_user`.

### Rules

- Owner/admin users appear in a distinct "Admins" section on the workspace users page with explanation text: "These users have access to all workspaces via their organization role"
- Owner/admin users are included in workspace member counts
- Owner/admin users cannot be removed from a workspace — there is no remove action in the Admins section
- **Clean demotion:** if an admin is demoted to a non-admin role (editor or viewer), they retain only workspaces where they have explicit `workspace_user` pivot entries. No automatic pivot rows are created for implicit access.

### Affected Components

- `WorkspaceContext::accessibleWorkspaceIds()` — add role check before pivot query. `User::accessibleWorkspaceIds()` delegates to this method, so it inherits the behavior automatically.
- `EnsureWorkspace` middleware — owner/admins never hit the "no workspace" state
- Workspace users page — split into Admins and Members sections
- User-centric workspace dropdown — disabled checkboxes for owner/admin users

---

## 2. Navigation Entry Points

### 2a. Sidebar Link

- New "Workspaces" item in the main sidebar navigation
- Position: below "Integrations", above "Settings"
- Visibility: owner and admin roles only (checked via `can('manage')` to stay consistent with route middleware)
- Links to: `/settings/workspaces`

### 2b. Workspace Switcher "Manage Workspaces" Link

- Added at the top of the workspace switcher dropdown
- Visibility: owner and admin roles only
- Links to: `/settings/workspaces`

### 2c. Settings Tab

- Existing "Workspaces" tab in settings tabs remains unchanged
- All three entry points lead to the same page

---

## 3. Workspace Switcher Enhancements

### Structure (top to bottom)

1. **"Manage Workspaces" link** — owner/admin only
2. **Search input** — rendered when user has 5+ accessible workspaces; always functional
3. **Pinned section** — workspaces the user has pinned; hidden if no pins
4. **Recent section** — last 3 workspaces switched to (excluding current)
5. **All Workspaces section** — full list with current workspace indicated (checkmark)

### Interactions

- Each workspace row shows: name, pin/unpin icon on hover
- Active workspace highlighted
- Search filters across all sections (pinned, recent, all)
- Pin toggle is immediate (no save button)

### Pin Storage

- Add `is_pinned` boolean column to `workspace_user` pivot table
- For owner/admin users with implicit access (no existing pivot row): create a lightweight pivot entry when they pin a workspace. This entry is for preference storage only — it does not affect access control. **Implementation order dependency:** the role check in `WorkspaceContext::accessibleWorkspaceIds()` must be implemented before or alongside the pin feature to ensure pivot rows created for pins don't become the source of truth for access.
- Default: `false`

### Recent Workspace Storage

- Persisted across sessions (not session-based)
- Storage mechanism: `workspace_recent` table with columns:
  - `user_id` (FK)
  - `organization_id` (FK)
  - `workspace_id` (FK)
  - `switched_at` (timestamp)
  - Unique constraint on `(user_id, organization_id, workspace_id)` — upsert on switch
- Populated via new `RecordRecentWorkspace` listener on the `WorkspaceSwitched` event. The listener must resolve `organization_id` from the workspace model since the event does not currently carry it.
- Query: last 3 by `switched_at` DESC, excluding current workspace
- Pruning: keep only last 10 distinct workspaces per user+org pair (cleanup in listener)

---

## 4. Workspace Settings Page (Hub)

### Current State

Flux table with columns: Name, Members, Default badge, Actions dropdown. Inline create form at top. Plan limit indicator.

### Changes

- **Workspace name** — clickable link to `/settings/workspaces/{workspace}/users`
- **Member count** — clickable link to same
- No other structural changes to this page

---

## 5. Workspace Users Page (Improved)

### URL

`/settings/workspaces/{workspace}/users` (existing route)

### Page Header

- Breadcrumb: Settings → Workspaces → {Workspace Name}
- Workspace name as page title
- Summary line: "{N} members · Default workspace" (if applicable)

### Admins Section

- Heading: "Admins" with subtle badge "Access via role"
- Explanation text: "These users have access to all workspaces via their organization role"
- Table columns: Name, Email, Role badge
- No actions — implicit access cannot be revoked from this page
- Shows all org users with owner or admin role

### Members Section

- Heading: "Members"
- **Action buttons (top right):**
  - "Copy from..." — see Copy From flow below
  - "+ Add Members" — opens searchable multi-select dropdown of org members not yet explicitly assigned. Select multiple, confirm, all added at once.

- **Search/filter bar** — filter visible members by name or email

- **Bulk action bar** — appears when any checkboxes are selected. Shows: "{N} members selected" and "Remove Selected" button.

- **Table columns:** Checkbox, Name, Email, Role badge (org role), Date Added, Remove action
- Individual remove action per row

### Copy From Flow

1. Click "Copy from..." button
2. Dropdown appears with searchable list of other workspaces in the org
3. Select a workspace
4. Second step appears: checkboxes to filter source workspace members by their organization role (editor, viewer). Owner/admin users are excluded since they already have implicit access.
5. Preview shows which members would be added (excluding those already assigned)
6. Confirm to apply

---

## 6. User-Centric Workspace Assignment

### Location

Settings → Users page. Each user row gets a "Manage Workspaces" action in its actions dropdown/column.

### Interaction

1. Click "Manage Workspaces" on a user row
2. Searchable dropdown appears anchored to the action trigger
3. Dropdown lists all workspaces in the org, each with a checkbox
4. Checked = user is assigned to that workspace
5. Toggle is immediate — no separate save button
6. Subtle toast confirmation on each toggle: "Added to {workspace}" / "Removed from {workspace}"

### Special Cases

- **Owner/admin users:** All checkboxes checked and disabled. Note at top of dropdown: "Has access to all workspaces via organization role"
- **Removing last workspace:** Warning shown: "This user will lose access to all workspaces. They'll be prompted to select a workspace on next login." Allow but require confirmation.

---

## 7. Data Model Changes

### Migration: Add `is_pinned` to `workspace_user`

```
ALTER TABLE workspace_user ADD COLUMN is_pinned BOOLEAN NOT NULL DEFAULT FALSE;
```

### Migration: Create `workspace_recent` table

```
CREATE TABLE workspace_recent (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    workspace_id BIGINT UNSIGNED NOT NULL,
    switched_at TIMESTAMP NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    UNIQUE KEY (user_id, organization_id, workspace_id)
);
```

### Model Changes

- `WorkspaceContext::accessibleWorkspaceIds()` — prepend role check (User model delegates to this, no separate change needed)
- `Workspace` model — no changes needed
- New `WorkspaceRecent` model for recent tracking

---

## 8. Component Summary

| Component | Type | Change |
|-----------|------|--------|
| `accessibleWorkspaceIds()` | Service/Model | Add implicit access for owner/admin |
| Sidebar nav | Blade partial | Add conditional "Workspaces" link |
| Workspace switcher | Blade/Livewire | Add search, pins, recents, manage link |
| Workspace settings page | Volt | Make name/count clickable links |
| Workspace users page | Volt | Redesign with Admins/Members sections, bulk ops |
| Users settings page | Volt | Add "Manage Workspaces" dropdown per user |
| `workspace_user` pivot | Migration | Add `is_pinned` column |
| `workspace_recent` table | Migration | New table for recent tracking |
| `RecordRecentWorkspace` listener | Event listener | New listener — create to persist recent workspace entries to `workspace_recent` table |

---

## 9. Edge Cases

- **Org with one workspace:** Switcher still works, just shows one item. Pin/recent sections hidden if only one workspace.
- **User with no workspaces:** Existing `workspace.none` redirect still applies. Only affects regular members (owner/admin always have access).
- **Deleted workspace:** Soft-deleted workspaces excluded from all lists, pins, and recents. Cascade cleanup not needed — existing FK constraints handle hard deletes.
- **Admin pins workspace, then gets demoted:** Pivot entry with `is_pinned=true` already exists, so they retain both access and pin. If they're later removed from the workspace, the pivot entry is deleted and pin is lost.
- **Copy from workspace with no matching roles:** Show "No members match the selected roles" message, disable confirm button.
- **Deactivated users:** Deactivated users (those with `deactivated_at` set) are excluded from the "Add Members" and "Copy from" member lists. They are shown with a visual indicator (e.g., muted text, "Deactivated" badge) in the existing members table but cannot be added to new workspaces.
