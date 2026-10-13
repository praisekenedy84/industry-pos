# Makerly: Production & POS Platform — PRD / SRS

*Working name. Version 1.0 draft. Combined Product Requirements (Part A) and Software Requirements (Part B).*

---

# Part A — Product Requirements (PRD)

## A1. Vision and problem

Small and mid-size producers (bakeries, soap makers, tailors, juice plants, furniture workshops) run production on paper, stock in spreadsheets and sales on a separate POS. Costs are guessed, stock never reconciles, and staff access is uncontrolled.

**Makerly** is an industry-agnostic, multi-tenant platform that models production as *inputs transformed into outputs*, and sells the results (plus third-party goods) through a built-in POS, with one stock ledger tying everything together.

## A2. Goals and non-goals

**Goals**

1. One platform for any producer: configurable vocabulary, stages, units and fields, not hard-coded industries.
2. Accurate cost per batch and margin per item.
3. POS that sells own produced goods and third-party trading goods, with offline tolerance.
4. Strong tenant isolation, per-tenant feature flags, and complete control over staff and system (API) users.
5. A fast, intuitive, shadcn-style interface that a new cashier or production lead learns in minutes.

**Non-goals (v1)**: full accounting/general ledger, payroll, billing/subscription automation (pricing is free-form and handled manually by the platform), advanced MRP/APS scheduling, marketplace/e-commerce storefront, hardware manufacturing (serial-number genealogy).

## A3. Personas

| Persona | Needs |
| --- | --- |
| **Platform Admin** (vendor staff) | Create tenants, set branch limits, toggle features, suspend tenants/branches, support impersonation (audited) |
| **Tenant Owner** | Full control of own business: branches, staff, roles, pricing, reports |
| **Branch Manager** | Run one branch: stock, production orders, staff schedules, till oversight |
| **Production Lead** | Plan and record production runs, manage recipes, record waste |
| **Cashier** | Fast sales, returns, till open/close |
| **Storekeeper** | Receive purchases, transfers, stock counts |
| **Accountant/Auditor** | Read-only reports, exports |
| **System User** (non-human) | Integrations via scoped API tokens (e.g. tax device, e-commerce sync, BI) |

## A4. Product scope (modules)

Each module is gated by a feature flag (see B5).

1. **Core**: tenants, branches, users, roles, audit, settings.
2. **Catalog**: items (raw, finished, trading), units, variants, categories, price lists.
3. **Inventory**: single append-only stock ledger, lots/expiry, transfers, counts, adjustments.
4. **Production**: recipes (BOM), production orders, runs, stages, costing, waste.
5. **Purchasing**: suppliers, purchase orders, goods receipts.
6. **POS**: sales, till sessions, payments, returns, receipts, offline mode.
7. **Customers**: profiles, credit accounts, wholesale terms.
8. **Reports**: production yield, stock valuation, sales, margin, staff activity.
9. **Platform Admin**: tenant lifecycle, feature flags, limits.

## A5. Key user journeys

**J1. Onboarding (Tenant Owner)**: sign up → choose industry template (pre-fills vocabulary and sample units) → create first branch → invite staff → add items → *done in under 15 minutes*. A persistent setup checklist guides first-run.

**J2. Produce and sell**: Production Lead opens *New production order*, picks a recipe and quantity → system shows material availability and expected cost → start run → record actual output and waste → finished stock appears → Cashier sells it at POS → margin visible on the dashboard.

**J3. Resell third-party goods**: Storekeeper receives a purchase order for a trading item → stock increases → Cashier sells it in the same POS cart as produced items.

**J4. Staff control**: Owner invites an employee by email → assigns role and branches → employee sets password and 2FA → Owner can see sessions, suspend, or revoke access instantly.

## A6. Success metrics

Time-to-first-sale under 30 min; POS checkout under 15 seconds for a 3-item cart; stock variance after counts under 2%; 99.9% monthly uptime; task-completion rate above 90% in usability tests.

## A7. Release phasing

| Phase | Scope |
| --- | --- |
| **MVP** | Core, Catalog, Inventory, Production (single-stage), POS, Purchasing, basic reports, RBAC, sessions, feature flags |
| **v1.1** | Multi-stage production, lots/expiry, customer credit, offline POS, system users/API |
| **v1.2** | Advanced reports, webhooks, industry templates marketplace, forecasting hints |

---

# Part B — Software Requirements (SRS)

## B1. Architecture

- **Application**: Laravel monolith with Inertia.js + React and Tailwind, using shadcn/ui components (default Laravel folder structure, no separate frontend/backend repos).
- **Multi-tenancy**: `stancl/tenancy`, **schema-per-tenant on PostgreSQL**; a central schema holds platform data.
- **Infrastructure**: Redis (cache, queues, sessions), Nginx, queue workers via Horizon/Supervisor, deployment via Laravel Forge with zero-downtime deploys.
- **Object storage**: S3-compatible, tenant-prefixed paths.
- **Realtime** (optional): Laravel Reverb/WebSockets for production and POS status updates.
- **Layering**: Domain modules (`Catalog`, `Inventory`, `Production`, `Pos`, ...) communicate through service classes and domain events; **only the Inventory module writes the stock ledger**.

## B2. Multi-tenancy requirements

| ID | Requirement |
| --- | --- |
| MT-1 | Each tenant has an isolated PostgreSQL schema; no cross-tenant queries from app code |
| MT-2 | Tenant resolved by subdomain (`acme.makerly.app`) and optional custom domain |
| MT-3 | Cache keys, queues, storage paths and sessions are tenant-scoped |
| MT-4 | Platform Admin sets each tenant's **max branch count** (free-form number, not fixed plans) |
| MT-5 | Platform Admin can **suspend a tenant** (all access blocked, data retained) or **suspend a single branch** |
| MT-6 | Tenant provisioning is automated and idempotent: create schema, run migrations, seed roles/permissions, create owner |
| MT-7 | Tenant data export and deletion on request (GDPR-style), with retention window |
| MT-8 | Platform admins can impersonate any staff user and perform actions on their behalf, with that user's role and branch scope. Requires a stated reason and re-authentication (password + 2FA); runs in a separate session so the user's own sessions are unaffected; time-limited (default 60 min, extendable); persistent banner with an Exit control; owner notified by email; every action audited with both identities (actor = platform admin, on behalf of = user). Blocked while impersonating: changing the user's password or 2FA, creating API tokens, viewing PINs. Tenant owners can view impersonation history |

**Branches**: multi-branch is a setting the platform admin enables per tenant; with a limit of 1 the UI hides the branch switcher. A tenant has N branches (up to its limit). Stock, tills, production orders and users are branch-aware; users are assigned to one or more branches and see only those.

## B3. Users, roles and access control

Two kinds of principals:

1. **Staff users** (humans: owner, managers, employees)
2. **System users** (non-human service accounts)

### B3.1 Staff / employee management

| ID | Requirement |
| --- | --- |
| US-1 | Invite by email or phone; invitee sets own password and enrols 2FA |
| US-2 | Assign one role and a set of branches per user (a user may hold different roles per branch) |
| US-3 | States: *Invited → Active → Suspended → Deactivated*; deactivation preserves history and attribution |
| US-4 | Suspend/deactivate **instantly terminates all active sessions** and revokes tokens |
| US-5 | Fields: name, contact, employee code, job title, branch(es), PIN (POS quick-login), working schedule (optional) |
| US-6 | Owner can view per-user: last login, active sessions/devices, activity log |
| US-7 | Bulk invite via CSV; bulk role/branch change with confirmation |
| US-8 | The last remaining Owner cannot be deactivated or demoted |

### B3.2 Roles and permissions (RBAC)

- Permissions are fine-grained strings: `module.resource.action` (e.g. `pos.sale.void`, `production.run.complete`, `inventory.adjustment.approve`).
- **System roles** (Owner, Branch Manager, Production Lead, Cashier, Storekeeper, Accountant) ship as defaults and can be cloned.
- Tenants can create **custom roles** by ticking permissions in a grouped matrix UI.
- Permissions are evaluated through Laravel policies/gates; every server action checks permission; UI hides what the user cannot do (but never relies on hiding).
- **Permission list is filtered by feature flags**: a disabled module's permissions do not appear.
- **Approval rules** (configurable thresholds): discounts above X%, voids, stock adjustments above Y, price overrides need a manager PIN or approval.
- Optional scoping: permissions can be limited to *own records*, *own branch*, or *all branches*.

### B3.2.1 Configurable permission matrix

The matrix is the single place where tenants decide who can do what. The permission catalog is defined in code by each module, while roles and grants are data owned by each tenant, so access can change without a release.

**Requirements (PM-1 to PM-14)**

- PM-1: every permission is a key `module.resource.action`; modules register their keys at boot, so a new feature adds its permissions automatically.
- PM-2: each grant has a **scope**: *none*, *own* (records the user created), *branch* (user's assigned branches), or *all*.
- PM-3: a grant can carry **constraints**, for example maximum discount %, maximum refund amount (TZS), maximum adjustment value, price-override range, or *requires approval above X*. Above the limit the action routes to an approver instead of failing.
- PM-4: roles are tenant data: create, rename, clone, archive. System roles ship as editable templates; the Owner role is locked at full access.
- PM-5: a user can hold **different roles per branch** (e.g. Manager at Branch A, Cashier at Branch B).
- PM-6: **per-user overrides** grant or deny single permissions on top of the role, with a reason and optional expiry (temporary cover). Deny beats allow; overrides are listed on the user page.
- PM-7: **no privilege escalation**: a user can only grant permissions they hold themselves, and only the Owner can edit the Owner role or create roles with `settings.roles.manage`.
- PM-8: permissions of disabled modules are hidden and ignored (feature flags apply first, then the matrix).
- PM-9: sensitive permissions (marked in the catalog) require re-authentication to use and are highlighted in the editor (e.g. export data, create API token, change roles, void after close).
- PM-10: changes take effect on the user's next request; affected sessions are regenerated, never left with stale access.
- PM-11: every change to a role, grant, constraint or override is audited with before and after values; roles keep a version history with one-click restore.
- PM-12: an **effective access view** per user shows each permission as allowed, denied or limited, and *why* (role, override, flag off, branch scope).
- PM-13: **role templates** can be published by the platform admin per industry (e.g. bakery, tailoring) and copied into new tenants at onboarding.
- PM-14: bulk operations: apply a role to many users, copy a role's grants to another, export and import a role as JSON.

**Matrix editor (UX)**

- Rows are permissions grouped by module and resource; columns are standard actions (View, Create, Edit, Delete, Approve, Export) plus a *Special* column for module-specific actions (void, override price, close till).
- Each cell cycles *none, own, branch, all* on click; a popover on the cell sets constraints. Row, column and group headers toggle in bulk.
- Search box, module filter, and *Show only differences from template*.
- **Compare roles** side by side with differences highlighted; **Preview as role** opens a read-only view of the app as that role would see it.
- Unsaved-changes bar with a summary ("12 changes, 2 sensitive") and a confirmation step before saving.

**Default matrix (editable template).** A = all, B = branch, O = own, — = none

| Permission area | Owner | Branch Manager | Production Lead | Cashier | Storekeeper | Accountant |
| --- | --- | --- | --- | --- | --- | --- |
| Catalog: view items | A | A | A | A | A | A |
| Catalog: create/edit items and prices | A | B | — | — | — | — |
| Recipes: view | A | A | A | — | — | A |
| Recipes: create/edit | A | B | B | — | — | — |
| Production orders: create/edit | A | B | B | — | — | — |
| Production runs: record/complete | A | B | B | — | — | — |
| Production: approve waste/variance | A | B | — | — | — | — |
| Inventory: view stock | A | B | B | B | B | A |
| Inventory: receive/transfer | A | B | — | — | B | — |
| Inventory: adjust (with limit) | A | B | — | — | O | — |
| Inventory: approve adjustments/counts | A | B | — | — | — | — |
| Purchasing: create PO | A | B | — | — | B | — |
| Purchasing: approve PO | A | B | — | — | — | — |
| POS: make sale | A | B | — | O | — | — |
| POS: discount (with limit) | A | B | — | O | — | — |
| POS: void / refund | A | B | — | approval | — | — |
| POS: open/close till | A | B | — | O | — | — |
| POS: review till variance | A | B | — | — | — | A |
| Customers: view/edit | A | B | — | B | — | A |
| Customers: set credit limit | A | B | — | — | — | A |
| Reports: sales | A | B | — | O | — | A |
| Reports: cost and margin | A | B | B | — | — | A |
| Reports: export | A | B | — | — | — | A |
| Users: invite/edit/suspend | A | B | — | — | — | — |
| Roles and matrix: manage | A | — | — | — | — | — |
| System users and API tokens | A | — | — | — | — | — |
| Audit log: view | A | B | — | — | — | A |
| Settings: tax, payments, receipts | A | — | — | — | — | — |

**Data model additions (tenant schema)**: `permissions` (catalog, synced from code), `roles` (with version), `role_permissions` (`role_id`, `permission_key`, `scope`, `constraints` JSON), `user_branch_roles`, `user_permission_overrides` (`effect`, `reason`, `expires_at`), `approval_requests`, `permission_audit`. Permission checks are cached per user in Redis and invalidated on any change to the user's role, overrides or branches.

### B3.3 System users (service accounts)

| ID | Requirement |
| --- | --- |
| SU-1 | Created by Owner or Platform Admin; not tied to a person; has name, purpose, owner-of-record |
| SU-2 | Authenticates with **scoped API tokens** (hashed at rest, shown once, prefix visible) |
| SU-3 | Tokens carry explicit permission scopes (least privilege) and optional branch restriction |
| SU-4 | Optional expiry date, IP allow-list and rate limit per token |
| SU-5 | One-click **rotate** and **revoke**; last-used time and IP recorded |
| SU-6 | Cannot log in through the UI; excluded from seat/branch user counts |
| SU-7 | All system-user actions are audit-logged with the token ID |

## B4. Authentication and session management (industry standard)

| ID | Requirement |
| --- | --- |
| AU-1 | Passwords hashed with **Argon2id** (or bcrypt with high cost); policy follows NIST SP 800-63B (length ≥ 10, breach-list check, no forced periodic rotation) |
| AU-2 | **2FA** via TOTP and recovery codes; Owner/Manager roles can be required to use it by tenant policy; passkeys (WebAuthn) in v1.2 |
| AU-3 | Login throttling per account + IP, progressive delay, temporary lockout, and notification of repeated failures |
| AU-4 | **Server-side sessions in Redis**, tenant-scoped; cookie is `HttpOnly`, `Secure`, `SameSite=Lax`, `__Host-` prefixed where possible; only an opaque session ID in the cookie |
| AU-5 | **Session ID regenerated** on login, on privilege/role change and on password change (prevents fixation) |
| AU-6 | **Idle timeout** (default 30 min, configurable per tenant; shorter for shared POS devices) and **absolute lifetime** (default 12 h); sliding renewal only within the absolute cap |
| AU-7 | "Remember this device" extends idle timeout using a rotating, hashed long-lived token bound to device |
| AU-8 | **Active sessions screen** per user: device, browser, IP/approx. location, last active; *Sign out this device* and *Sign out everywhere* |
| AU-9 | Optional **concurrent session limit** per role (e.g. cashier: 1 device) with clear "already signed in elsewhere" flow |
| AU-10 | **Immediate invalidation** on suspension, deactivation, password reset, 2FA reset |
| AU-11 | Re-authentication (password or PIN) required for sensitive actions (change role, create API token, large refunds, export data) |
| AU-12 | CSRF protection on all state-changing requests; security headers (CSP, HSTS, X-Frame-Options, Referrer-Policy) |
| AU-13 | **POS quick-switch**: on a shared terminal, a cashier unlocks with a 4–6 digit PIN (rate-limited, bound to the terminal, never used for remote login) |
| AU-14 | Security events (login, failure, 2FA change, token creation, session revoke) written to an immutable audit log and emailed to the user where appropriate |

*Note: the login session is separate from the **till session** (cash drawer), see POS-3.*

## B5. Feature flags

Three layers, evaluated together (a feature is on only if all applicable layers allow it):

1. **Platform layer**: global kill-switches and staged rollouts (e.g. beta features).
2. **Tenant layer**: Platform Admin toggles per tenant, free-form (no fixed packages), with optional **limits** (max branches, max staff users, max system users, max items, max monthly transactions).
3. **Branch layer** *(optional)*: tenant owner can disable specific modules per branch.

| ID | Requirement |
| --- | --- |
| FF-1 | Flags stored centrally: `feature_key`, `type` (boolean / limit / variant), `default`, description, module; per-tenant overrides with `set_by`, `set_at`, optional `expires_at` |
| FF-2 | Standard flags: `module.production`, `module.pos`, `module.purchasing`, `module.customers`, `pos.offline_mode`, `production.multi_stage`, `inventory.lots_expiry`, `customers.credit`, `api.system_users`, `reports.advanced`, `pricing.multi_channel`, `branches.multi_branch`, `api.webhooks` |
| FF-3 | Checked in backend (middleware + policies + `Feature::active()`), in navigation (menu hides disabled modules) and in the frontend via shared Inertia props |
| FF-4 | Disabling a module hides UI and blocks API but **never deletes data**; re-enabling restores access |
| FF-5 | Limits are enforced with friendly errors ("Your plan allows 3 branches. Contact support to increase") and warnings at 80% |
| FF-6 | Admin UI: searchable matrix of tenants × flags, bulk apply, history of changes, effect preview, and audit |
| FF-7 | Flag values cached in Redis per tenant with immediate invalidation on change |
| FF-8 | Expiring flags support trials (auto-revert at `expires_at`) |

## B6. Functional requirements by module

### B6.1 Catalog

- **CAT-1**: Item types: `raw_material`, `finished_good`, `trading_good`; optional `service`.
- **CAT-2**: Units of measure with conversion factors (kg↔g, dozen↔piece); each item has base unit, purchase unit, sales unit.
- **CAT-3**: Variants (size/flavour), barcodes (multiple), SKU auto-generation, images, categories, tags.
- **CAT-4**: **Price lists** per channel (retail, wholesale, custom) with effective dates; item price override per branch.
- **CAT-5**: Tax categories configurable per tenant/country; prices tax-inclusive or exclusive.
- **CAT-6**: Tenant-defined **custom fields** per item type (typed: text, number, select, date).
- **CAT-7**: Industry template pre-fills vocabulary ("batch" ↔ "bake"), units and sample items; labels are overridable per tenant.

### B6.2 Inventory (single ledger)

- **INV-1**: Append-only `stock_movements`; stock on hand = sum of movements by item, branch, lot. No direct quantity editing.
- **INV-2**: Movement types: `purchase_receipt`, `production_consume`, `production_output`, `sale`, `sale_return`, `transfer_out`, `transfer_in`, `adjustment`, `wastage`, `expiry`, `opening_balance`.
- **INV-3**: Every movement records user/system user, branch, reason, reference (document type and ID), unit cost.
- **INV-4**: **Valuation**: weighted-average cost (default), FIFO optional per tenant.
- **INV-5**: Lots/batches with expiry; FEFO picking suggestion (flag `inventory.lots_expiry`).
- **INV-6**: Inter-branch transfers with *draft → in transit → received* states and discrepancy handling.
- **INV-7**: Stock counts (full/cycle) with blind-count option, variance review and approval before posting adjustments.
- **INV-8**: Reorder levels and low-stock alerts; negative stock policy configurable (block / warn / allow).

### B6.3 Production

- **PRD-1 Recipes (BOM)**: output item + yield quantity, list of inputs (raw materials or other finished goods as sub-assemblies), wastage %, optional labor/overhead cost lines, notes, **versioning** (old runs keep the version used).
- **PRD-2 Production orders**: statuses `draft → planned → in_progress → completed | cancelled`; fields: recipe, planned quantity, branch, due date, assignee, priority.
- **PRD-3 Material check**: on planning, show required vs available, shortages highlighted with a *Create purchase request* shortcut; optional reservation of materials.
- **PRD-4 Production run**: records actual consumed quantities (prefilled from recipe, editable), actual output, **waste/by-products**, start/end time, labor hours; completing posts ledger movements atomically.
- **PRD-5 Stages (flag `production.multi_stage`)**: tenant-defined ordered stages per recipe (e.g. mix → proof → bake → pack) with per-stage status, timestamps, assignee, optional quality check.
- **PRD-6 Costing**: batch cost = materials (at ledger cost) + labor + overhead; unit cost = batch cost ÷ good output; stored on the output lot and used for margin.
- **PRD-7 Variance**: report planned vs actual usage and yield per recipe/over time.
- **PRD-8 Modes**: item setting *make-to-stock* or *make-to-order*; a POS sale of a make-to-order item can raise a production order.
- **PRD-9 Quality**: optional checklist per recipe/stage; fail → reject quantity to waste with reason.

### B6.4 Purchasing

- **PUR-1**: Suppliers with contacts, payment terms, price lists per item.
- **PUR-2**: Purchase orders (`draft → approved → sent → partially received → received → closed`), approval thresholds per role.
- **PUR-3**: Goods receipt against PO or ad hoc; updates cost via ledger; attach delivery note photo.
- **PUR-4**: Supplier returns and credit notes; supplier balance tracking.

### B6.5 POS

- **POS-1 Selling**: sells any `finished_good` or `trading_good`; search by name/SKU/barcode; category grid; variants and modifiers; line notes; hold/recall carts.
- **POS-2 Pricing**: price list by channel chosen per sale or per customer; discounts (line, cart, coupon) with approval limits; tax computed per line.
- **POS-3 Till sessions**: open with opening float; all cash movements (sales, pay-in, pay-out, drops) recorded; **close with blind count**, expected vs counted, variance reason; manager review. One open till per cashier/terminal.
- **POS-4 Payments**: cash, card (external terminal reference), mobile money, bank transfer, store credit, **split payments**, partial payment on account; configurable payment methods per tenant.
- **POS-5 Returns/voids**: void before payment; returns verified by scanning the receipt code or the product barcode against the original sale (items not sold by this tenant are rejected; the scan also shows lot, expiry and sale date), with reason, restock-or-waste choice, manager approval.
- **POS-6 Receipts**: thermal 80 mm or 57 mm showing net, VAT and gross per line and in total, A4 invoice, PDF/WhatsApp/email/SMS share; tenant branding; sequential numbering per branch; reprint tracked.
- **POS-7 Stock effect**: each sale posts a `sale` movement (finished/trading stock). Source of stock for each item: *finished stock* or *raise production order*.
- **POS-8 Offline mode** (flag): service worker + IndexedDB queue; local stock estimate, local receipt numbering with branch/terminal prefix; sync with conflict handling and clear "offline" indicator; max offline duration configurable.
- **POS-9 Customers**: attach customer, wholesale price list, credit limit, loyalty hook (future).
- **POS-10 Hardware**: barcode scanner (keyboard wedge), cash drawer, any connected receipt printer (ESC/POS over USB, network or Bluetooth; 80 mm or 57 mm paper chosen per terminal; browser-print fallback), customer display (v1.2).
- **POS-11 Fiscal/tax devices**: pluggable *fiscal provider* interface so a tax-authority integration can be added later as an adapter. At launch there is no fiscal integration, but every sale calculates tax (VAT) per line, prints it on the receipt, and stores net, tax rate, tax amount and gross with the sale for later submission.
- **POS-12 Speed**: add-item latency under 100 ms perceived; keyboard shortcuts for all frequent actions.

### B6.6 Customers

Profiles, groups, credit accounts with limits and ageing, statements, payments on account.

### B6.7 Reports and dashboards

**Dashboard**: today's sales, production today, low stock, expiring lots, open tills, pending approvals, receivables due, top sellers. Role-aware: each role sees only the widgets its permissions allow.

**Common report rules (RPT-1 to RPT-8)**

- RPT-1: every report has date range (presets: today, yesterday, this week, this month, custom), branch filter (all permitted branches), and period comparison (vs previous period or same period last year).
- RPT-2: all amounts in TZS; dates and day boundaries in GMT+3; tax shown as net, VAT and gross wherever money appears.
- RPT-3: each report has its own permission (`reports.<name>.view`, `reports.<name>.export`); cost and margin reports are separate from sales reports so cashiers never see costs.
- RPT-4: export to CSV, Excel and PDF; print-friendly layout; exports are audited.
- RPT-5: drill-down from totals to the underlying documents (sale, run, receipt, movement).
- RPT-6: scheduled delivery by email (daily, weekly, monthly) to chosen users, including an end-of-day summary.
- RPT-7: reports above a size threshold run as queued jobs with a notification when ready.
- RPT-8: saved filter views per user; reports always reconcile to the ledger and sales tables (no separate summary that can drift).

**Report catalog**

| Area | Report | What it answers |
| --- | --- | --- |
| Sales | Daily/period sales summary | Gross, discounts, net, VAT, returns, payments by method |
| Sales | Sales by item / category / variant | Best and worst sellers, units and revenue |
| Sales | Sales by channel / price list | Retail vs wholesale performance |
| Sales | Sales by branch / terminal / cashier | Who and where sales happen |
| Sales | Sales by hour and weekday | Peak times for staffing and production |
| Sales | Sales by customer / customer group | Top customers, repeat rate |
| Sales | Produced vs traded sales | Share of own products vs resold goods |
| Sales | Discounts and price overrides | Who gave which discount, approvals used |
| Sales | Voids, returns and refunds | Volume, reasons, items, cashier, approver |
| Sales | Receipt/invoice register | Every document by number, reprints, cancelled numbers |
| Profit | Gross margin by item / category / branch | Revenue minus cost; produced uses batch cost, traded uses purchase cost |
| Profit | Profitability by recipe | Margin per recipe after materials, labor and overhead |
| Profit | Price vs cost review | Items selling below target margin |
| Production | Production summary | Orders planned, completed, late, cancelled |
| Production | Yield and variance | Planned vs actual usage and output per recipe |
| Production | Waste and rejects | Quantity and cost by reason, product, stage, person |
| Production | Batch cost breakdown | Materials, labor, overhead, unit cost per run |
| Production | Recipe cost trend | How unit cost moves as ingredient prices change |
| Production | Output by person / shift / branch | Productivity and labor hours |
| Production | Stage duration and bottlenecks | Time per stage vs standard (multi-stage) |
| Production | Material requirements | Materials needed for planned orders vs available |
| Production | Make-to-order backlog | Open orders raised from sales |
| Inventory | Stock on hand | Quantity and value by item, lot, branch, as of a date |
| Inventory | Stock valuation | Total value by category and valuation method |
| Inventory | Stock movement ledger | Every movement with type, reference, user |
| Inventory | Stock card (item history) | One item's in/out/balance over time |
| Inventory | Low stock and reorder | Items under reorder level, suggested quantities |
| Inventory | Expiry and near-expiry | Lots expiring in N days, expired stock write-off |
| Inventory | Dead and slow stock | Items without movement for N days |
| Inventory | Stock ageing | Value by age bucket |
| Inventory | Stock turnover | Turns and days of cover per item |
| Inventory | Adjustments and wastage | Who adjusted what and why, value lost |
| Inventory | Count variance | Counted vs expected, per count and trend |
| Inventory | Transfers | In transit, received, discrepancies between branches |
| Purchasing | Purchases by supplier / item / period | Spend and volumes |
| Purchasing | Purchase order status | Open, late, partially received |
| Purchasing | Price history by supplier | Cost changes per item and supplier |
| Purchasing | Supplier payables and ageing | What is owed and to whom, by age |
| Purchasing | Goods receipt vs PO | Short, over and rejected deliveries |
| Customers | Receivables and ageing | Outstanding credit by customer and age bucket |
| Customers | Customer statement | Sales, payments and balance per customer |
| Customers | Credit limit exceptions | Customers over limit or overdue |
| Customers | Customer purchase history | Items bought, frequency, last visit |
| Cash and payments | Till session report | Opening float, sales, pay-ins/outs, expected vs counted, variance |
| Cash and payments | Payments by method | Cash, mobile money, card, transfer, store credit totals |
| Cash and payments | Cash movement (pay-in/out, drops) | Every non-sale cash movement with reason |
| Cash and payments | Payment reconciliation | System totals vs mobile money/bank statements (CSV import) |
| Tax | VAT / tax summary | Output tax by rate and period, from stored receipt tax data |
| Tax | Tax detail by document | Per-receipt net, rate, tax, gross for filing support |
| Tax | Exempt and zero-rated sales | Sales by tax category |
| Staff | Cashier / employee performance | Sales, voids, discounts, average basket per cashier |
| Staff | Attendance and shifts | Login, till and production activity by person (if scheduling is used) |
| Staff | User access review | Users, roles, branches, last login, inactive accounts |
| Staff | Session and security events | Logins, failures, 2FA changes, revoked sessions |
| Audit | Audit trail | Who changed what, before/after, with impersonation flag |
| Audit | Price and cost change log | Item price, recipe and cost edits over time |
| Audit | Impersonation log | Platform admin sessions, reason, duration, actions |
| Audit | API and system-user activity | Calls by token, failures, last used |
| Management | Branch comparison | Sales, margin, waste and stock side by side |
| Management | Business snapshot (owner) | Sales, margin, production, stock value, receivables in one page |
| Management | End-of-day report | Auto-emailed summary of sales, tills, production, low stock |
| Management | Target vs actual | Sales and production targets (optional) |
| Platform (admin only) | Tenant overview | Status, branches used/limit, users, last activity, enabled features |
| Platform (admin only) | Usage and limits | Transactions, items, users per tenant against configured limits |
| Platform (admin only) | Feature flag matrix | Which tenants have which features, change history |
| Platform (admin only) | System health | Queue depth, failed jobs, slow queries, backup status |

**Custom reports (v1.2)**: a report builder letting users pick a dataset (sales, stock movements, production runs, purchases), columns, filters and grouping, and save the result as a reusable, schedulable report.

### B6.8 Audit and notifications

- **AUD-1**: Immutable audit log for create/update/delete of key records, permission and role changes, session/security events, flag changes, impersonation; stores actor (user/system user), before/after, IP, timestamp.
- **NOT-1**: In-app, email and optional SMS/WhatsApp notifications (low stock, approvals, till variance, production overdue).

## B7. UX and UI requirements

### B7.1 Design principles

1. **Task first**: each screen has one primary action.
2. **Progressive disclosure**: simple defaults, advanced options folded away.
3. **Consistency**: same patterns for every list, form and detail screen.
4. **Forgiving**: undo where possible, confirm destructive actions, never lose in-progress work (autosave drafts).
5. **Fast**: keyboard-first on desktop, thumb-friendly on tablet/mobile.
6. **Accessible**: WCAG 2.1 AA.

### B7.2 Design system (shadcn/ui components, Notion-inspired look)

The look follows the Notion design language (from the supplied DESIGN-notion.md), adapted from a marketing site to a dense operations app. Components are shadcn/ui on Radix and Tailwind, with Notion's tokens mapped onto shadcn's CSS variables (`--background`, `--card`, `--border`, `--foreground`, `--muted-foreground`, `--primary`, `--radius`). The source defines a light theme only, so the dark theme and the status colours below are our own derivations.

**Anti-slop rules (DS-1 to DS-8)**

- DS-1 **Quiet chrome, one accent**: neutral surfaces and near-black ink; a single blue paints primary actions, links, focus and the active nav row. No second structural accent.
- DS-2 **Warm paper canvas** behind white cards and inputs; pure white is never the full-page background.
- DS-3 **Hairlines over heavy borders and shadows**; shadows only on popovers, modals and toasts, built from several faint layers, never a hard drop-shadow.
- DS-4 **Sticker palette is decoration and tagging only**: category dots, item-category chips, avatar fills and empty-state illustrations. Never a button or structural fill.
- DS-5 **One typeface** (Inter, tight tracking on headings, weight 400 body, tabular numerals for money and quantities).
- DS-6 **No filler**: no gradient blobs, glassmorphism, neon glows, stock-illustration clutter, emoji used as icons, "AI sparkle" decoration or generic marketing copy. Every element carries information or an action.
- DS-7 **Useful empty states**: plain words, one clear next action, a small illustration at most.
- DS-8 **Tokens only**: one icon set (Lucide, consistent stroke), one radius scale, one spacing scale; no one-off colours or sizes in components.

**Design tokens**

| Token | Light | Dark (derived) | Use |
| --- | --- | --- | --- |
| canvas | #f6f5f4 | #191918 | Page background |
| surface | #ffffff | #222221 | Cards, inputs, popovers |
| hairline | #e6e6e6 | #353533 | Borders, dividers |
| ink | #000000 (95% alpha) | #f4f3f1 | Primary text |
| ink-secondary | #31302e | #d4d1cc | Secondary text |
| ink-muted | #615d59 | #a39e98 | Supporting text |
| ink-faint | #a39e98 | #75716c | Placeholders only, never essential text |
| primary | #0075de | #4ba3ff | Actions, links, focus |
| primary-active | #005bab | #3a93ec | Pressed state |
| on-primary | #ffffff | #08121f | Text on primary |
| success / warning / destructive | #168a31 / #b45309 / #c93a2b | #4cc468 / #f0a94a / #ff7a6b | Status chips, validation, alerts (derived) |

Sticker colours (sky #62aef0, purple #d6b6f6, pink #ff64c8, orange #dd5b00, teal #2a9d99, green #1aae39, brown #523410) get adjusted dark variants and are used only as in DS-4. Every text and background pair must meet WCAG AA (4.5:1), checked automatically in both themes.

**Type, shape and spacing**

- Inter with fallback `-apple-system, system-ui, Segoe UI, Helvetica, Arial`; headings weight 700 with negative letter-spacing, buttons 500, body 400.
- In the app: page title 26/700, card title 22/700, form body 16, dense tables and nav 15, captions 14, small labels 12/600. The 54–64 px display sizes appear only on login and onboarding.
- Radius: 4 px inputs and chips, 5 px menu and list rows, 8 px buttons, 12 px cards, 16 px modals and large wells. Pill shape only for badges and the single onboarding CTA; inputs are never pill-shaped.
- Spacing scale 4 / 8 / 12 / 16 / 24 / 32; card padding 24 px; whitespace, not rules, separates sections.
- Tables: header on the canvas tone with 12 px semibold label style, 15 px body, hairline row borders, 12/16 px cell padding.
- Touch: minimum 44 px targets on POS and production-floor screens.

### B7.2.1 Light and dark mode

- TH-1: three choices, **Light, Dark, System**; default System.
- TH-2: stored per user (`users.theme`) and applied before first paint, so there is no flash of the wrong theme.
- TH-3: toggle in the user menu and top bar, and on the login screen (stored on the device there).
- TH-4: implemented with CSS variables and a `dark` class; components never use hard-coded colours, so every screen supports both themes automatically.
- TH-5: when set to System, the app follows the device live; the browser colour-scheme hint is set so native controls and scrollbars match.
- TH-6: tenant logos accept light and dark variants; charts, badges and status chips have dark variants.
- TH-7: receipts, invoices and PDFs always print in light styling regardless of theme.
- TH-8: managers can force a theme per POS terminal (for example high contrast in a bright shop).
- TH-9: tenants may set an accent colour; the system rejects choices that fail contrast and generates the dark variant. Default is the blue above.

### B7.2.2 Language toggle: English and Swahili

- LN-1: English (`en`) and Swahili (`sw`) at launch; adding a language means adding one language file.
- LN-2: a compact **EN | SW** toggle in the top bar, on login and onboarding screens, and in the user profile. Switching applies instantly without a full reload and without losing the open page or unsaved form data.
- LN-3: precedence is user preference, then tenant default (set by the Owner), then browser language, then English.
- LN-4: no hard-coded UI text; every string is a translation key. CI fails on missing or unused keys; a missing key falls back to English and is logged.
- LN-5: plurals, numbers and dates use ICU message format and `Intl`; dates in GMT+3, amounts in TZS.
- LN-6: layouts tolerate text expansion (Swahili is often longer): no fixed-width labels, buttons size to content, truncated text has a tooltip. QA runs every screen in both languages plus a pseudo-localisation mode.
- LN-7: translated surfaces: UI, validation and API error messages (by `Accept-Language`, with stable error codes), emails/SMS/notifications (in the recipient's language), report and CSV headers (in the exporting user's language), and receipts.
- LN-8: **receipt language** is a tenant setting: English, Swahili, or bilingual.
- LN-9: tenant data (item, category, recipe and customer names) is never machine-translated; items can carry an optional second-language display name used on POS and receipts when that language is active.
- LN-10: tenant vocabulary overrides (industry template terms such as "Batch" vs "Bake") apply on top of the language pack, per language; platform admins can override a term for one tenant without a deploy (v1.1).
- LN-11: translations live in shared JSON files read by both Laravel (`__()`) and the React client (react-i18next), a single source of truth.
- LN-12: a fixed glossary keeps terms consistent, and Swahili copy is reviewed by a fluent business-domain speaker before release.
- LN-13: POS login screens remember the terminal's last language.

**Starter glossary (to be reviewed by a native speaker)**

| English | Swahili |
| --- | --- |
| Dashboard | Dashibodi |
| Sales | Mauzo |
| Production | Uzalishaji |
| Inventory / Stock | Bidhaa / Stoo |
| Purchases | Manunuzi |
| Customers | Wateja |
| Suppliers | Wasambazaji |
| Reports | Ripoti |
| Settings | Mipangilio |
| Receipt | Risiti |
| Save | Hifadhi |
| Cancel | Ghairi |
| Search | Tafuta |
| Sign out | Toka |

**Data model additions**: `users.theme`, `users.locale`, `tenants.default_locale`, `tenants.receipt_language`, `items.name_alt` (second-language display name), `translation_overrides` (tenant, key, locale, value).

### B7.3 Application shell

- Collapsible **left sidebar** grouped by module (Dashboard, Sales, Production, Inventory, Purchasing, Customers, Reports, Settings); items hidden by feature flag and permission.
- **Top bar**: branch switcher, global search / **Command palette (Ctrl/Cmd+K)** for pages, items, orders and actions, notifications, user menu.
- **Breadcrumbs** and page header with primary action at top right.
- Mobile: bottom navigation for key sections; sidebar becomes a sheet.

### B7.4 Interaction patterns

- **Lists**: search, filters as removable chips, saved views, sortable columns, bulk actions, column chooser, empty states with a clear next step, CSV export.
- **Forms**: inline validation, helpful defaults, keyboard Tab order, autosave drafts for orders, sticky save bar.
- **Detail pages**: summary header with status badge, tabs (Overview, Lines, Activity, Documents), timeline of status changes.
- **Feedback**: toast for success, inline errors, skeleton loaders, optimistic updates where safe.
- **Destructive actions**: confirmation dialog stating consequences; soft delete with restore.
- **Onboarding**: setup checklist, contextual tips, sample data option, inline help links.

### B7.5 Key screens and flows

**POS screen (tablet/desktop)**: left, product search + category grid with big touch targets; right, cart with quantity steppers, line notes, totals; bottom, large **Charge** button. Shortcuts: `/` focus search, `F2` customer, `F4` discount, `F8` pay, `Esc` cancel. Payment sheet supports split payments and shows change due. Offline badge always visible. Till open/close wizard (float → count → variance → confirm).

**Production order screen**: step indicator *Plan → Prepare → Produce → Review*. Plan: pick recipe and quantity, see cost estimate. Prepare: material availability with shortage actions. Produce: editable actual quantities, timer, stage checklist. Review: yield vs expected, variance, waste reasons, confirm to post stock.

**Recipe editor**: ingredient table with inline quantity/unit editing, live cost per unit, yield and wastage fields, version history.

**Staff and roles**: users table with role/branch/status chips, *Invite* sheet, user detail with tabs (Profile, Roles & branches, Sessions, Activity). **Role editor** is a grouped permission matrix with search and "copy from role".

**API/system users**: create → choose scopes in the same matrix → token shown once with copy button → usage log.

**Platform admin console**: tenant list (status, branches used/limit, last active), tenant detail with *Features* tab (flag toggles and limits), *Branches*, *Suspend* actions with reason, *Impersonate* with audited reason.

### B7.6 Accessibility and localisation

Keyboard operable, visible focus, ARIA via Radix, contrast AA; i18n-ready (English and Swahili first, extensible), currency fixed to TZS (no multi-currency in v1); default time zone GMT+3 (Africa/Dar\_es\_Salaam) for display, day boundaries, till closing, reports and numbering, with timestamps stored in UTC; Tanzanian number and date formats; RTL-ready layout.

## B8. Data model (core tables)

**Central schema**: `tenants`, `domains`, `platform_admins`, `features`, `tenant_features` (override, limit, expires\_at), `tenant_limits`, `impersonation_logs`, `industry_templates`.

**Tenant schema**:

| Area | Tables |
| --- | --- |
| Org | `branches`, `settings`, `tax_rates`, `payment_methods`, `number_sequences` |
| Access | `users`, `roles`, `permissions`, `role_permissions`, `user_branch_roles`, `sessions`, `remember_tokens`, `api_tokens` (system users), `system_users`, `two_factor_secrets`, `audit_logs` |
| Catalog | `items`, `item_variants`, `item_barcodes`, `categories`, `units`, `unit_conversions`, `price_lists`, `price_list_items`, `custom_field_defs`, `custom_field_values` |
| Inventory | `stock_movements` (append-only), `stock_lots`, `stock_balances` (materialised for speed), `transfers`, `transfer_lines`, `stock_counts`, `stock_count_lines` |
| Production | `recipes`, `recipe_versions`, `recipe_lines`, `recipe_stages`, `production_orders`, `production_runs`, `run_inputs`, `run_outputs`, `run_waste`, `run_stage_logs` |
| Purchasing | `suppliers`, `purchase_orders`, `purchase_order_lines`, `goods_receipts`, `goods_receipt_lines` |
| Sales | `customers`, `till_sessions`, `till_movements`, `sales`, `sale_lines`, `sale_payments`, `returns`, `return_lines`, `receipts` |

**Rules**: UUID or ULID primary keys; `branch_id` on all operational records; money as `DECIMAL(18,4)` in TZS (single currency in v1); soft deletes on master data; `stock_movements` never updated or deleted (corrections are reversing entries); indexes on `(item_id, branch_id, created_at)` and `(reference_type, reference_id)`.

## B9. API and integrations

- Versioned REST API (`/api/v1`) with JSON:API-style errors, pagination, filtering, idempotency keys for sale/receipt creation, rate limits.
- Auth via system-user tokens (Bearer) with scopes; OpenAPI spec published.
- **Webhooks** (v1.2): `sale.completed`, `production.completed`, `stock.low`, HMAC-signed with retry and delivery log.
- Import/export: CSV import for items, stock opening balances, customers, suppliers with dry-run validation.
- Adapter interfaces: fiscal/tax provider, payment gateway, SMS/WhatsApp, accounting export.

## B10. Non-functional requirements

| Category | Requirement |
| --- | --- |
| **Performance** | p95 page response < 400 ms; POS add-to-cart < 100 ms; search < 200 ms; report jobs queued over 5 s |
| **Scalability** | Hundreds of tenants per server; horizontally scalable workers; read replica option; tenant schemas migratable in batches |
| **Availability** | 99.9% target; zero-downtime deploys; health checks; graceful degradation to offline POS |
| **Security** | OWASP ASVS L2; TLS everywhere; encryption at rest for secrets and tokens; least-privilege DB roles; dependency scanning; secrets in env/vault; regular pen tests |
| **Privacy** | Data minimisation, tenant data export/deletion, audit of personal-data access |
| **Backup/DR** | Daily full + point-in-time recovery (WAL), per-tenant restore procedure, RPO ≤ 15 min, RTO ≤ 4 h; restore drills quarterly |
| **Integrity** | Stock-affecting actions run in DB transactions; idempotent endpoints; reconciliation job comparing balances to ledger |
| **Observability** | Structured logs with tenant ID, error tracking, metrics (queue depth, slow queries), uptime alerts |
| **Compatibility** | Latest two versions of Chrome, Edge, Safari, Firefox; Android/iPadOS tablets; installable PWA |
| **Maintainability** | Module boundaries, automated tests (unit, feature, browser), CI gate, migrations reversible where possible |
| **Testing** | Tenant-isolation tests, permission matrix tests, ledger invariants (property tests), offline-sync tests |

## B11. Key business rules

1. Stock is changed only through ledger movements.
2. Completing a production run is atomic: consume inputs, create output lot, post costs.
3. A sale cannot complete if the item is blocked by negative-stock policy and no override permission.
4. Closing a till requires all sales to be paid or voided.
5. Role or branch changes take effect on the user's next request (sessions are regenerated).
6. Disabled features never delete data.
7. A suspended branch cannot transact; its historical data stays visible to owners.
8. Recipe edits create a new version; historical costs are never recalculated.

## B12. Acceptance criteria (MVP samples)

- *Given* a tenant with `module.production` off, *when* a user opens `/production`, *then* they get a 404/upgrade message and the sidebar shows no production entry.
- *Given* a deactivated employee with two open sessions, *when* the owner deactivates them, *then* both sessions fail on the next request.
- *Given* a recipe yielding 20 loaves, *when* a run completes with 18 good and 2 waste, *then* ledger shows raw consumption, 18 output and 2 wastage, and unit cost = batch cost ÷ 18.
- *Given* a cart with a produced and a traded item, *when* paid with split cash + mobile money, *then* one receipt is issued and both items' stock decreases.
- *Given* tenant A's token, *when* calling tenant B's resource, *then* the request is rejected with no data leaked.
- *Given* a cashier inactive past the idle timeout, *when* they act, *then* they are routed to PIN or login and the cart draft is preserved.

## B13. Risks and mitigations

| Risk | Mitigation |
| --- | --- |
| Over-generalisation makes the product shapeless | Strong core + industry templates; validate with two unlike producers early |
| Schema-per-tenant migrations at scale | Batched, resumable tenant migrations; versioned migration runner |
| Offline POS conflicts | Per-terminal numbering, append-only sales, server reconciliation rules |
| Permission sprawl | Role templates, matrix UI, permission tests, periodic access review report |
| Costing disputes | Versioned recipes, immutable ledger, transparent cost breakdown |

## B14. Decisions on earlier open questions

1. **Currency and time:** TZS only; default time zone GMT+3.
2. **Fiscal:** no fiscal integration at launch; tax is calculated, shown on the receipt and stored with each sale.
3. **Branches and pricing:** multi-branch is configured per tenant by the platform admin; pricing is free-form and handled manually by the platform.
4. **Hardware:** any connected printer on 80 mm or 57 mm paper; barcode scanning verifies returned goods and product validity.
5. **Restaurant/bar service:** out of scope for this release and possibly beyond.
6. **Impersonation:** platform admins can act on behalf of users (see MT-8).
