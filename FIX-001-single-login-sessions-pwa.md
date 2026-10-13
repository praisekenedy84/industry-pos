# FIX-001: Single login (no subdomains), session model, and PWA install

**Applies to:** Makerly Production & POS Platform PRD/SRS v1.0
**Status:** Proposed
**Supersedes:** MT-2 (subdomain resolution), MT-3 (tenant-scoped sessions), AU-4, AU-6, AU-8, AU-9, AU-13 (as originally written), the `domains` table, custom-domain support, and the wildcard DNS/TLS requirement.

---

## 1. Decision

1. **No subdomains and no custom domains.** The whole platform is served from one origin, for example `https://app.makerly.app`.
2. **One flexible login.** A user signs in once with their account. If the account exists and has an active membership in a workspace (tenant), they are sent to that workspace. If they belong to several, they pick one.
3. **Workspace is identified by a path prefix**, `/w/{workspace}/...`, so links, bookmarks, multiple browser tabs and PWA installs all stay workspace-specific.
4. **Sessions are split into two layers**: a *device session* that proves who the person is, and a *workspace session* that carries each workspace's own security policy.
5. **PWA install is per workspace**, so a POS terminal installs "Acme Bakery" as its own app that opens straight into that workspace.

---

## 2. Identity and workspace resolution

### 2.1 Why a central identity is needed

With schema-per-tenant, user rows live inside each tenant's schema, so a central login screen cannot know which schema to look in. The fix is a small **central directory** that holds the person's credentials and their memberships. Tenant schemas keep only workspace-specific data (role, branches, employee code, PIN, schedule).

Chosen model: **one identity, many memberships.** One email or phone number is one person, with one password and one 2FA setup, who may belong to several workspaces (for example an accountant serving several businesses).

*Alternative considered:* keep credentials inside each tenant and use the central directory only as an index. Rejected because the same email in two tenants could have two different passwords, making login ambiguous and forcing the server to try each one.

*Trade-off accepted:* one password now opens every workspace that person belongs to. This is mitigated by mandatory 2FA when any membership requires it, workspace-level unlock rules (section 4), and re-authentication for sensitive actions.

### 2.2 Central data model (central schema)

| Table | Key fields | Purpose |
|---|---|---|
| `identities` | `id`, `email` (nullable, unique, case-insensitive), `phone` (nullable, unique, E.164), `login_alias` (nullable, unique, for username-only staff), `password_hash` (Argon2id), `two_factor_secret`, `recovery_codes`, `status` (active, locked, disabled), `locale`, `theme`, `last_login_at` | The person and their credentials |
| `workspaces` | `tenant_id`, `slug` (unique, immutable, URL-safe), `display_name`, `logo_path`, `status` (active, suspended) | Public identity of a tenant; replaces `domains` |
| `memberships` | `id`, `identity_id`, `tenant_id`, `tenant_user_id`, `status` (invited, active, suspended, removed), `last_used_at` | Links a person to a workspace |
| `invitations` | `id`, `tenant_id`, `email`/`phone`, `role_id`, `branch_ids`, `token_hash`, `expires_at`, `accepted_at` | Pending invites |
| `device_sessions` / `workspace_sessions` | see section 4 | Session layers |

`users.theme` and `users.locale` (added in B7.2) move to `identities`, since preferences should follow the person across workspaces. Tenant `users` rows keep: role and branch assignments, employee code, PIN hash, job title, schedule, and a `membership_id` reference.

### 2.3 Login flow

1. **Sign-in form** at `/login`: one screen with *Email or phone* and *Password*. Both fields are submitted together so the response never reveals whether the account exists.
2. Server verifies the identity (throttled, constant-time, generic error: "Email or password is incorrect").
3. **2FA step** if enabled on the identity, or if any active membership's role or tenant policy requires it.
4. **Workspace resolution**:
   - **0 active memberships**: generic message ("This account has no active workspace. Contact your administrator."); no workspace names are shown.
   - **1 active membership**: go straight to `/w/{slug}/`.
   - **2 or more**: workspace picker showing logo, name and the user's role, with the last-used one preselected and a "Remember my choice" option.
5. A **workspace session** is created for the chosen workspace (section 4) and the user lands on their home screen for that role (cashier lands on POS).

**Direct workspace link.** `/w/{slug}/login` shows the same form, branded with that workspace's name and logo, and signs the user in only to that workspace. This is what POS terminals and staff bookmark. If the account has no membership in that workspace, the generic error is shown.

**Staff without email or phone.** The Owner can create an account with a username and temporary password. Those staff sign in through *Use workspace code* on the login form (`workspace code` + `username` + `password`) or directly on `/w/{slug}/login`. They get a workspace-bound identity (`login_alias` = `{slug}:{username}`). They are forced to change the temporary password at first login.

**Switching workspace.** The user menu lists the person's other workspaces. Choosing one opens `/w/{other}/` and creates that workspace's session without asking for the password again, unless the target workspace requires 2FA or unlock (section 4). Several workspaces can be open in separate tabs.

**Invitations.** An invite link opens `/invite/{token}`.
- *No identity yet*: the invitee sets a password, enrols 2FA, and the identity and membership are created.
- *Identity already exists*: the invitee signs in, sees the invitation, and accepts, which adds a membership. A password is never overwritten.

**Forgot password** is central, by email or SMS, and applies to the identity. A reset signs the person out of all device sessions.

### 2.4 Rules

- FX-1: login responses, timing and rate-limit behaviour must not reveal whether an email, phone or workspace exists.
- FX-2: workspace slugs are lowercase, 3 to 32 characters, unique, immutable once created, and exclude reserved words (`login`, `admin`, `api`, `w`, `invite`, `assets`, `app`, `support`, and similar).
- FX-3: a membership in status *suspended* or *removed*, or a workspace in status *suspended*, cannot open a workspace session, and existing workspace sessions are ended immediately.
- FX-4: removing a person from a workspace removes the membership only. The identity and their access to other workspaces are untouched. Historical records stay attributed to the tenant user.
- FX-5: the `next` redirect after login accepts relative paths inside `/w/{slug}/` only, preventing open redirects and cross-workspace jumps.
- FX-6: a user who belongs to a workspace can see only that workspace's data. The workspace is always taken from the URL and then authorised against an active membership on every request.

---

## 3. Tenant resolution without subdomains

- **Web routes:** `/w/{workspace}/...`. Use stancl/tenancy's path-based initialization, with the route parameter mapped to `workspaces.slug`. Remove the domain-based initializer and the `domains` table.
- **Middleware order per request:** (1) resolve identity from the device session, (2) resolve tenant from the path, (3) verify an active membership and an unexpired workspace session, (4) check tenant status and feature flags, (5) initialise tenancy, (6) run permission checks.
- **API routes:** `/api/v1/...` with the system-user token. The token is bound to exactly one tenant, so no workspace appears in the URL.
- **Platform admin console:** `/admin/...`, with its own guard and session, separate from tenant logins.
- **Caches, queues, storage, broadcasting channels** stay tenant-scoped through tenancy bootstrappers, with keys built from `tenant_id`, not from the host.
- **Impersonation (MT-8):** the platform admin signs in at `/admin`, picks a tenant and user, and receives a workspace session for `/w/{slug}/` flagged with `impersonated_by`. All MT-8 rules still apply.

---

## 4. Session model

### 4.1 Two layers

| Layer | Proves | Stored | Cookie | Lifetime |
|---|---|---|---|---|
| **Device session** | Who the person is on this browser or device | Redis, key `ds:{id}` | One host-only cookie, `__Host-ds` (`Secure`, `HttpOnly`, `SameSite=Lax`, `Path=/`) holding an opaque ID | Absolute 12 h by default; 30 days if *Remember this device* is ticked (rotating, hashed token bound to the device) |
| **Workspace session** | Active use of one workspace under that workspace's policy | Redis, key `ws:{tenant_id}:{device_session_id}`, mirrored in `workspace_sessions` for listing and audit | None. It is looked up from the device session plus the workspace in the URL | Idle timeout and absolute lifetime set by the workspace's policy |

Only one cookie is issued. Workspace sessions are server-side records, so a stolen workspace cookie cannot exist, and two workspaces open in two tabs never share state beyond the device session.

### 4.2 Requirements

- FX-7: the device session ID is regenerated on login, password change, 2FA change, and when a new workspace session is created.
- FX-8: each workspace sets its own policy: idle timeout (default 30 min, range 5 min to 8 h), absolute lifetime (default 12 h), unlock method (*PIN*, *password*, or *password + 2FA*), required 2FA by role, and concurrent-session limit per role. The workspace session enforces these, not the device session.
- FX-9: when a workspace session expires from idleness, the user sees a **lock screen** at `/w/{slug}/unlock` (still inside the PWA scope) and unlocks using the workspace's unlock method. Unsaved work (cart, production run draft) is preserved. When the absolute lifetime ends, the user passes through full login.
- FX-10: expiry of one workspace session does not end another workspace session or the device session.
- FX-11: suspending a membership or workspace ends its workspace sessions at once. Changing a role or branch assignment regenerates the workspace session so access updates on the next request.
- FX-12: **Password reset, password change, 2FA reset or identity lock ends all device sessions and all workspace sessions** for that identity.
- FX-13: **Sign out** has two choices: *Sign out of this workspace* (ends that workspace session) and *Sign out of this device* (ends the device session and all its workspace sessions). *Sign out everywhere* ends every session for the identity.
- FX-14: the **active sessions** screen lists device, browser, approximate location, last activity and which workspaces each session has open, with per-session revoke. Owners can list and revoke sessions of their own workspace's members, but only at the workspace-session level; they cannot end the person's device session or other workspaces.
- FX-15: concurrent-session limits apply per workspace and role (for example one active device for a cashier). Hitting the limit offers *Sign out the other device and continue*.
- FX-16: **POS PIN quick-switch** applies inside an open workspace session on a registered terminal. A terminal is registered by a manager and gets a device-bound token. The PIN is tenant-specific, rate-limited, and never works for remote login or on an unregistered device.
- FX-17: sensitive actions still require re-authentication (password, PIN or 2FA per policy), and the identity's 2FA is required before any workspace session whose policy demands it.
- FX-18: redis keys, session audit events and logs always include `tenant_id` where relevant and `identity_id`.
- FX-19: CSRF, security headers (CSP, HSTS, Referrer-Policy, X-Frame-Options), login throttling by identifier plus IP, and breach-list password checks remain as originally specified.

---

## 5. PWA install

### 5.1 Approach

One origin hosts one service worker, but **each workspace can be installed as its own app** through a per-workspace web app manifest. Several installs for different workspaces can coexist on one device because each has a different manifest `id`.

### 5.2 Manifest (served dynamically at `/w/{slug}/manifest.webmanifest`)

| Field | Value |
|---|---|
| `id` | `/w/{slug}/` |
| `start_url` | `/w/{slug}/?source=pwa` (opens the user's home screen for that workspace, going through unlock or login if needed) |
| `scope` | `/w/{slug}/` (login, unlock, invite-accept for that workspace are served inside this scope, so the installed app never drops into a browser bar) |
| `name` / `short_name` | Workspace display name, truncated for the short name |
| `display` | `standalone` (managers may enable `fullscreen` per terminal for POS kiosks) |
| `orientation` | `any` |
| `theme_color` / `background_color` | Canvas tone, light and dark variants via media-aware `theme-color` meta tags |
| `icons` | 192, 512 and maskable 512, generated from the tenant logo with a neutral fallback |
| `categories` | `business` |
| `shortcuts` | New sale, New production order, Stock (permission checks happen on arrival; the route redirects if the user cannot access it) |
| `lang` | The workspace default language |

### 5.3 Service worker

- FX-20: a single service worker registered at the site root, with caches **namespaced by tenant and version** (`app-v{build}`, `ws-{tenant_id}-data`) so one workspace's cached data is never served to another.
- FX-21: static, hashed assets are cache-first; navigation requests use network-first with an offline fallback page; API `GET`s are network-first with a short stale fallback only for POS-critical reads (catalog, prices, customers).
- FX-22: **authenticated HTML and sensitive API responses are not cached** beyond the app shell and the POS data needed for offline selling.
- FX-23: **offline POS** (feature flag `pos.offline_mode`): sales queue in IndexedDB keyed by tenant, user and terminal, with per-terminal receipt numbering, retried on reconnect with idempotency keys. The queue and cached data are wiped on sign-out, on membership removal, and after the maximum offline period configured by the workspace.
- FX-24: offline unlock is allowed only on a **registered terminal**, only with the PIN, and only within the maximum offline duration. Offline never allows a first-time login or any permission change.
- FX-25: updates: the new worker installs in the background and the user sees *"New version available. Refresh"*. The app never refreshes mid-sale, and a pending POS cart blocks the update prompt until the cart is completed or held.

### 5.4 Install experience

- FX-26: an **Install app** item appears in the user menu and on the first successful login when the browser supports installing (Chrome, Edge, Android). It uses a custom prompt, never repeats after a dismissal for 30 days, and states which workspace is being installed.
- FX-27: iOS and Safari show short instructions (*Share, then Add to Home Screen*) because there is no install prompt API.
- FX-28: once installed (detected through standalone display mode), the install item is hidden.
- FX-29: managers get a **Set up a terminal** wizard: install the app, register the terminal, choose paper width (80 mm or 57 mm), connect and test the printer, choose theme and language, optionally enable fullscreen.
- FX-30: the app must pass an installability audit: HTTPS, valid manifest, icons, a registered worker, an offline fallback, a responsive viewport, and a working lock/login inside the manifest scope.
- FX-31: removing a user from a workspace, or suspending the workspace, shows the lock screen inside the installed app with a clear explanation, never a blank page.

---

## 6. Changes to apply to the PRD/SRS

| Section | Change |
|---|---|
| A5 J4 | Staff invitation uses the central invite flow (section 2.3) |
| B1 Architecture | Single origin; remove wildcard DNS and TLS; add central `identities`, `memberships`, `workspaces`; path-based tenancy |
| B2 MT-2 | Replace with: "Tenant resolved from the `/w/{workspace}` path; no subdomains or custom domains" |
| B2 MT-3 | Replace with: "Cache keys, queues, storage and workspace sessions are tenant-scoped; the device session is central" |
| B2 MT-6 | Provisioning also creates the workspace slug and the Owner's identity and membership |
| B3.1 US-1 | Invite by email or phone, or create a username account for staff without either |
| B3.1 US-4 | Suspending a user suspends the membership and ends that workspace's sessions |
| B4 AU-1, AU-2 | Password and 2FA belong to the identity |
| B4 AU-4 to AU-9, AU-13 | Replaced by the two-layer model in section 4 |
| B7.3 | Shell gains a *Workspace switcher* (people with several memberships); the branch switcher stays inside a workspace |
| B7.5 | Add login, workspace picker, lock screen and install/terminal setup screens |
| B7.6 | Language and theme preferences stored on the identity |
| B8 | Central: add `identities`, `workspaces`, `memberships`, `invitations`, `device_sessions`, `workspace_sessions`; remove `domains`. Tenant: `users` references `membership_id` |
| B10 Compatibility | Add PWA installability on Chrome, Edge, Android and iOS Safari |
| B13 Risks | Add: shared-credential blast radius; mitigated by 2FA, unlock policy and re-authentication |
| B14 | Record this decision |

---

## 7. Acceptance criteria

- *Given* an identity with one active membership, *when* it signs in at `/login`, *then* it lands on `/w/{slug}/` without seeing a picker.
- *Given* an identity with memberships in two workspaces, *when* it signs in, *then* it sees a picker, and after choosing one it can open the other in a second tab with no extra password prompt (unless that workspace requires 2FA or unlock).
- *Given* an unknown email and a wrong password for a known email, *when* each logs in, *then* both receive the same message and similar response time.
- *Given* a workspace with a 15-minute idle policy, *when* a cashier is idle for 15 minutes, *then* the lock screen appears, the cart is preserved, and a PIN unlocks it, while the same person's other workspace stays active.
- *Given* a suspended membership, *when* its holder makes the next request, *then* the workspace session is gone and they see the lock screen with a clear message, while their other workspaces still work.
- *Given* a password reset, *when* it completes, *then* every device and workspace session for that identity is invalid.
- *Given* tenant A's cached data, *when* the same browser opens tenant B, *then* no tenant A data is served or visible.
- *Given* a workspace page, *when* the user installs it, *then* the installed app opens at `/w/{slug}/`, shows the workspace name and icon, and never leaves its scope for login or unlock.
- *Given* a registered terminal that is offline within the allowed period, *when* a cashier enters a valid PIN, *then* they can sell and the sales sync with no duplicates once online.
- *Given* an unregistered device offline, *when* someone tries to sign in, *then* sign-in is refused with an explanation.

---

## 8. Implementation notes (Laravel, stancl/tenancy)

- Use path identification with the route parameter mapped to `workspaces.slug`; keep central routes (`/login`, `/invite/*`, `/admin/*`, `/api/*`) outside the tenant route group.
- Keep sessions in a central Redis store with a `ds:`/`ws:` key scheme; do not use the tenant database for sessions.
- Build the authenticated user for tenant requests from `identity` plus the tenant `users` row found through `membership_id`, so policies and the permission matrix work unchanged.
- Cache the membership and role lookup per request in Redis with invalidation on any change (the permission-matrix cache already follows this rule).
- Rate-limit by identifier plus IP at the central login, and add alerts for repeated failures across many identifiers from one IP.
- Remove custom-domain code paths and certificate automation from the roadmap and from the infrastructure checklist.
