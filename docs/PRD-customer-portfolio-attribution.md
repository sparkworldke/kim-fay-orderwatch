# PRD: Customer Portfolio Attribution and Directional Sales Visibility

**Status:** Draft for review  
**Owner:** Commercial Technology  
**Date:** 2026-07-31  
**Source files:** `users (9).sql`, `MT Outlets by Rep.xlsx`

## 1. Purpose

Create one auditable customer-to-user portfolio model that consistently controls:

- which customers a user can see;
- which Sales Orders (SO), Credit Notes (CN), backorders, revenue, and volumes belong to a user;
- how a manager, HOD, and executive see the combined results of their reportees;
- how Acumatica rep identifiers map to OrderWatch users when the two systems are not aligned;
- how approved Excel assignments are previewed and seeded without silently overwriting manual decisions.

The first delivery covers the Modern Trade hierarchy:

```text
Vignesh Ramachandran (CCO)
└── Purity Nduku Kioko (Modern Trade HOD)
    └── Modern Trade reportees
```

All Modern Trade results must be visible to the assigned reportee, Purity, and Vignesh. This is a visibility rollup, not duplicate transaction ownership.

## 2. Problem Statement

Acumatica stores a rep code on customer and transaction data, but some rep codes do not align with `users.rep_code` in OrderWatch. OrderWatch also stores `users.employee_number`, which may be the identifier Acumatica is actually using. Relying on only one field causes valid customers and transactions to disappear from a user's portfolio or be attributed to the wrong person.

The attached workbook also contains business assignments that cannot be inferred from rep code alone:

- named main-account ownership regardless of outlet or region;
- regional ownership where a rep has no main account;
- mixed main-account and individual-outlet assignments;
- explicit exceptions such as Naivas outlets in Thika or Magunas outlets in Nairobi.

The current application already supports typed `owner` and `servicing` customer assignments and descendant-based visibility. The missing capability is a single deterministic attribution policy that combines these sources and applies it to every commercial dataset.

## 3. Goals

1. Resolve Acumatica rep identifiers against both `users.rep_code` and `users.employee_number`.
2. Support approved manual assignments by customer ID, main account, and region.
3. Give a reportee visibility to their own portfolio only.
4. Give an HOD visibility to their own portfolio plus all descendants.
5. Give Vignesh visibility to Purity and every descendant below Purity.
6. Apply the same customer scope to SO, CN, backorders, revenue, and volume.
7. Preserve one canonical attributed rep per transaction to prevent double counting.
8. Provide preview, conflict reporting, audit history, and idempotent seeding.

## 4. Non-Goals

- Replacing Acumatica as the customer or transaction system of record.
- Editing customer master records in Acumatica.
- Splitting a single transaction's value between multiple reps in phase 1.
- Inferring ownership from fuzzy customer-name matching without review.
- Giving a reportee access to a manager's personal customers or a sibling's customers.
- Treating visibility rollups as commission ownership.

## 5. Source Data Findings

### 5.1 Workbook

`MT Outlets by Rep.xlsx` contains:

| Sheet | Rows | Purpose |
|---|---:|---|
| Outlets | 420 | Explicit customer ID to rep assignments |
| Main Account By Rep | 40 | Main-account ownership rules |

The Outlets sheet has 420 distinct customer IDs assigned across 10 reps:

| Workbook rep | Outlet rows | Current user match |
|---|---:|---|
| Jane Kuria | 27 | Jane Kirigo Kuria |
| Lucy Wanjiru | 76 | Lucy Wanjiru Munene |
| Georgina Kiilu | 47 | Georgina Muthini Kiilu |
| Dennis Mutwiri | 38 | Dennis Mutwiri Kimathi |
| Zipporah Wangeci | 40 | Zipporah Wangeci Muiruri |
| Lilian Kimeu | 51 | Lilian Kalondu Kimeu |
| Kevin Werunga | 37 | Kevin Werunga Barasa |
| Lawrence Amukhono | 48 | Lawrence Amukhono Amukhono (`moderntrade.exec@kimfay.com`, `P272`) |
| George Amenya | 22 | George Amenya Morang'a |
| Beryl Muga | 34 | Beryl Akinyi Muga |

The Main Account sheet contains 40 rows assigned to eight reps. George and Beryl intentionally have no main-account rows because their assignments are regional.

### 5.2 User identity

The supplied users data confirms:

- Vignesh is the CCO and has employee/rep identifier `P320`.
- Purity is the Modern Trade HOD and has employee/rep identifier `P496`.
- the identified Modern Trade reportees have both `rep_code` and `employee_number`;
- all identified reportees currently point to Purity through `reports_to_user_id`;
- Purity points to Vignesh.

Lawrence must be created with the following approved identity and organization settings:

| Field | Value |
|---|---|
| Name | Lawrence Amukhono Amukhono |
| Email | `moderntrade.exec@kimfay.com` |
| Application role | Sales Consultant |
| Rep code | `P272` |
| Employee number | `P272` |
| Designation | Modern Trade Executive |
| Division | Consumer Sales |
| Department | Modern Trade (`mt_consumer_sales` / MT / Consumer Sales) |
| Department role | Member |
| Reports to | Purity Nduku Kioko, Modern Trade HOD |
| Active consultant | Yes |

The user creation and reporting relationship must be applied before Lawrence's 48 customer assignments.

## 6. Definitions

**Canonical servicing rep:** The single user responsible for a customer's day-to-day portfolio reporting.

**Owner:** An optional accountable user for the commercial relationship. Owner and servicing rep may be different.

**Direct portfolio:** Customers canonically assigned to the current user.

**Directional team portfolio:** The union of the current user's direct portfolio and the direct portfolios of all descendants in `reports_to_user_id`.

**Mapped-only consultant:** A user who has the canonical `Sales Consultant` role and at least one active servicing customer assignment. Their personal dashboard scope is limited to those exact assignments.

**Main account:** A parent account name used to assign all matching customer outlets.

**Attribution:** The process of resolving a transaction to one canonical user through its customer.

**Visibility:** Permission to view a transaction. Visibility does not change its canonical attribution.

## 7. Functional Rules

### 7.1 Identity resolution

Normalize every candidate identifier with `UPPER(TRIM(value))`.

For an incoming Acumatica rep identifier, resolve in this order:

1. exact active match on `users.employee_number`;
2. exact active match on `users.rep_code`;
3. exact active match on `user_acumatica_rep_mappings.acumatica_rep_code`;
4. unresolved.

The resolution order gives `employee_number` priority because it is the requested bridge for misaligned dashboard rep codes.

Rules:

- Both `rep_code` and `employee_number` are valid aliases for the same user.
- Duplicate matches at the same priority are an error, never an arbitrary selection.
- Inactive-only matches are reported separately from unknown codes.
- Cross-priority conflicts are blocked. Example: an identifier matching one user's employee number and another user's rep code requires admin resolution.
- A mapping record may supply additional historical or alternate codes.
- Identity aliases determine the rep; they do not override an approved customer assignment.

### 7.2 Customer assignment precedence

For each customer, resolve one servicing rep using the following precedence:

1. active manual customer override;
2. approved explicit workbook customer-ID assignment;
3. approved main-account rule;
4. approved region rule;
5. Acumatica customer rep alias;
6. most recent valid SO rep alias;
7. unresolved assignment queue.

Higher-precedence sources win. Lower-precedence sources remain visible in the audit explanation.

An explicit customer-ID assignment always beats a broad main-account or region rule. This supports exceptions without changing the parent rule.

Manual mapping requirements:

- `customer_acumatica_id` must exactly match an existing `acumatica_customers.acumatica_id` after trimming.
- Customer names are display and review aids only; names must never be used as the persisted mapping key.
- The preview must reject unknown customer IDs and show the closest available customer name only as an operator aid.
- Once applied, the same customer ID must control customer lists, SO, CN, revenue, volume, backorders, exports, and dashboard totals.
- A later Acumatica sync must preserve a valid manual mapping even when the transaction rep code differs.
- If Acumatica changes a customer ID, the old mapping remains unresolved until an administrator explicitly remaps it; the system must not guess from the name.

### 7.3 Mapped-only Sales Consultant gate

Add a backend policy/gate, implemented centrally before customer-scoped queries:

```text
isMappedOnlyConsultant(user) =
    user has canonical Sales Consultant role
    AND user has at least one active servicing customer assignment
```

Role evaluation requirements:

- Check the normalized many-to-many role relationship, for example `hasRole('Sales Consultant')` or the canonical role ID/slug `sales_consultant`.
- Do not rely only on the legacy `users.role` string because a user may have several roles.
- No other shared or secondary role grants access to another user's customer mappings.
- Adding another role to a Sales Consultant does not broaden the consultant's personal customer scope.
- If the user has the Sales Consultant role, the mapped-only restriction takes precedence over broad access implied by any ordinary secondary role.
- A true `is_super_admin` system override may bypass the gate only in an explicitly selected administrative/impersonation context, never silently in the user's normal dashboard.

Scope behavior:

| User state | Personal dashboard customer scope |
|---|---|
| Sales Consultant with one or more active outlet mappings | Exact active mapped `customer_acumatica_id` values only |
| Sales Consultant with no active mappings | Existing identity resolution may propose matches, but dashboard access remains empty until mappings are approved |
| User without Sales Consultant role | This gate does not grant or share consultant customer access |
| HOD/executive | Own permitted direct scope plus mapped portfolios of reportees through directional hierarchy |

For a mapped-only consultant:

- do not union mapped customers with historical SO rep-code matches;
- do not union mapped customers with customers discovered through `employee_number`;
- use `rep_code` and `employee_number` only to propose and reconcile mappings;
- treat an unmapped SO/CN carrying the consultant's rep alias as a reconciliation exception, not visible dashboard data;
- require an approved mapping before that customer's transactions appear.

### 7.4 Directional hierarchy

Visibility follows `reports_to_user_id` downward:

| User | Visible portfolio |
|---|---|
| User with no reportees | Own direct customers |
| Any user with one or more reportees | Own customers + all direct and indirect reportees |
| Purity | Own customers + all descendants |
| Vignesh | Own customers + all descendants, including Purity's subtree |
| Sibling reportee | Own customers only |
| Administrator with org-wide scope | All customers |

Directional behavior:

- team visibility is granted by the existence of active reportees, not only by an HOD, manager, or executive label;
- managers see downward;
- reportees do not see upward;
- reportees do not see sibling portfolios;
- a user with reportees can select a direct reportee, view that person's KPIs, and drill down to that reportee's mapped customers;
- if the selected reportee is also a manager, the viewer can continue drilling down through that reportee's subtree;
- each drill-down level must show direct customers separately from inherited descendant customers;
- the selected reportee must be a descendant of the authenticated viewer; a user ID supplied by the browser is never sufficient authorization;
- moving a user to a different manager changes inherited visibility immediately;
- disabling a user does not delete historical attribution, but removes that user from new automatic matching until reviewed.

The mapped-only gate applies at each node before the manager rollup is built. Purity and Vignesh receive the de-duplicated union of each reportee's mapped portfolio; they do not receive additional customers merely because a reportee's alias appears on an unmapped transaction.

### 7.5 GT and MT team separation

General Trade and Modern Trade are separate commercial teams:

| Team | Department slug | Customer portfolio |
|---|---|---|
| General Trade | `gt` | GT users and their mapped customers |
| Modern Trade | `mt_consumer_sales` | MT users and their mapped customers |

Team separation rules:

- a consultant has one primary commercial team at a time;
- primary team comes from the active primary department membership, not from designation text or a shared application role;
- a user may have secondary department memberships, but those memberships do not merge GT and MT customer portfolios;
- `Sales Consultant` is a functional role shared across teams and must never be used to determine GT versus MT;
- customer mappings remain attached to individual consultants;
- team totals are the de-duplicated union of mapped customers belonging to active members of that team;
- GT users cannot view MT reportees or customers unless hierarchy or an explicit org-wide permission grants that access;
- MT users cannot view GT reportees or customers under the same conditions;
- Vignesh may view GT and MT as separate team scopes and may also use an explicitly authorized organization-wide scope;
- dashboards, exports, SO, CN, revenue, and volume queries must accept a resolved team scope and must not combine GT and MT by default.

If the current reporting tree places GT and MT users under the same HOD, the migration preview must identify those cross-team relationships. They must be explicitly retained or reassigned; the system must not silently infer the intended HOD.

### 7.6 KP CRM access boundary

KP CRM is a restricted product area. Access is separate from GT/MT portfolio access and from general KP FOL permissions.

Initial authorized cohort:

| Access basis | Authorized users |
|---|---|
| KP team | Active users whose primary or approved secondary department is `kp` |
| Named administrator | Titus Kaleli Mutiso (`commercialtechlead@kimfay.com`) |
| Named commercial executive | Vignesh Ramachandran (`cco@kimfay.com`) |
| KP HOD | Susan Ngina Mwathi (`susan@kimfay.com`) |
| Named executive | Hartaj Singh Bains (`hbains@kimfay.com`) |
| Named C-Suite executive | Rajdeep Singh Bains (`rbains@kimfay.com`) |

Create a dedicated permission such as `kp.crm.access`. A request may enter KP CRM only when the user:

1. is active;
2. has `kp.crm.access`; and
3. belongs to the approved KP CRM cohort through KP department membership or an approved named leadership assignment.

Role and identity rules:

- use canonical role relationships and org-level fields; do not rely only on `users.role`;
- Administrator, Executive, or `c_suite` status may qualify a user for approval, but must not silently add every future holder of those roles to KP CRM;
- the launch leadership assignments are Titus, Vignesh, Susan, Hartaj, and Raj;
- Susan's access comes from her approved KP HOD assignment, even though her functional role is Sales Consultant;
- a shared Sales Consultant, Customer Service, Sales Operations, GT, or MT role does not grant KP CRM access;
- secondary roles do not bypass the cohort gate;
- removing a user from the KP team or leadership assignment revokes KP CRM access after cache invalidation;
- `is_super_admin` may perform audited support access, but normal navigation must still require an explicit administrative mode.

KP CRM surfaces covered by the gate include:

- KP Accounts and customer drill-down;
- Contract Cleaners;
- Dormant Customers;
- Items Not Ordered;
- KP Meetings and Calendar when displaying CRM customer data;
- KP CRM performance, revenue, volume, exports, downloads, scheduled reports, and AI summaries;
- any new route under the KP CRM product area.

Access and data scope are separate:

- KP consultants see their exact mapped KP customers;
- Susan sees her own mapped customers plus the mapped customers of her reportee subtree;
- Titus, Vignesh, Hartaj, and Raj see the de-duplicated KP team portfolio;
- leadership access to KP CRM does not grant ownership of KP customers;
- a customer is classified as KP when its Acumatica `Category` field starts with the prefix `KP`; no GT or MT customer appears in KP CRM unless it carries this approved KP classification and mapping (see §7.10);
- a user denied by the cohort gate receives no KP CRM counts, names, search suggestions, notifications, or cached data.

Enforce this through a central Laravel Gate/Policy and route middleware, for example `can:access-kp-crm`. Existing granular permissions such as dormant management remain additional action checks; they cannot replace the product-area access gate.

### 7.7 Modern Trade business rules

Initial assignment policy from the supplied brief:

- **Beryl Muga:** all 34 Coast outlets listed in the workbook; no main-account rule.
- **George Amenya:** all 22 Nyanza outlets listed in the workbook; no main-account rule.
- **Georgina Kiilu:** Quick Mart main account, regardless of outlet or region.
- **Lucy Wanjiru:** Naivas/Naivasha S.S.S main account, regardless of outlet or region.
- **Jane Kuria:** Majid main account, regardless of outlet or region.
- **Kevin Werunga:** Chandarana, China Village, and Onn the Way main accounts; remaining assigned outlets come from the Outlets sheet.
- **Zipporah Wangeci:** assignments come from the Main Account sheet, with explicit outlet rows retained as overrides where present.
- **Lilian Kimeu:** Magunas main account and all other Modern Trade outlets in Mountain, including Naivas outlets in Thika.
- **Lawrence Amukhono:** Khetias main account and all Modern Trade outlets in Rift. His assignments resolve through employee/rep code `P272`.
- **Dennis Mutwiri:** Kassmart, Leestar, Jaza, Eastleigh, Kamindi, and Kikuyu Selfridges main accounts; all Magunas in Nairobi; selected Powerstar and Cleanshelf outlets from the Outlets sheet.

The workbook is authoritative for phase-1 customer IDs. Narrative rules are stored as rule metadata and used to validate coverage and future customer imports.

### 7.8 Transaction attribution

Every commercial transaction is scoped by `customer_acumatica_id`, not only by the rep code stored on that transaction.

For each transaction:

1. resolve the customer;
2. resolve the customer's effective servicing assignment for the transaction date;
3. store or calculate the canonical attributed user;
4. expose the transaction to that user and their active ancestor chain;
5. count the transaction once in each requested rollup.

A mismatched transaction rep code must be shown as a data-quality warning but must not override a higher-precedence manual customer assignment.

For users subject to the mapped-only consultant gate, a transaction is visible only when its exact `customer_acumatica_id` is in the effective mapped customer set. Matching `rep_code` or `employee_number` alone is insufficient for dashboard visibility.

### 7.9 Metrics

All metrics must accept the same resolved customer set.

**Sales Orders**

- Order count: distinct SO documents.
- Ordered revenue: sum of `order_total` for SO document types only.
- Ordered volume: sum of line `order_qty`.
- Shipped volume: sum of shipped quantity where available.
- Backorder volume/value: existing backorder definitions, scoped by customer.

**Credit Notes**

- Credit-note count: distinct CN/RC documents.
- Credit-note revenue: absolute credit value displayed separately.
- Net revenue: SO revenue minus credit-note revenue.
- Credit-note volume: returned/credited line quantity displayed separately.
- Net volume: ordered or shipped volume minus credited volume, based on the selected dashboard measure.

Credit notes must never be counted as positive SO revenue. The UI must label gross sales, credits, and net revenue explicitly.

**Rollups**

- Reportee total = transactions for the reportee's canonical customer portfolio.
- Purity total = union of Purity's direct portfolio and all descendant portfolios.
- Vignesh total = union of Vignesh's direct portfolio and all descendant portfolios.
- A customer or transaction appearing through multiple visibility paths is de-duplicated by customer ID or transaction primary key before aggregation.

### 7.10 Sales channel classification

Each customer resolves to one canonical primary sales channel. The classifications below operationalize the channel catalogue in §8.2 and resolve Open Decision #3. Channel classification is derived from approved business data, not from a consultant's team membership or application role.

**Modern Trade Tier 1 (`MT1`)** — assigned by main account, regardless of outlet or region. Every outlet belonging to the main account inherits the MT1 classification unless an explicit override exists.

| MT1 main account | Servicing rep |
|---|---|
| Majid Al Futtaim Hypermarkets Ltd | Jane Kuria |
| Naivasha S.S.S Stores Limited (Naivas) | Lucy Wanjiru |
| Quick Mart Ltd | Georgina Kiilu |

**Modern Trade Tier 2 (`MT2`)** — explicit MT2 outlets and their servicing reps:

| Customer | Servicing rep |
|---|---|
| Leestar Supermarket - Githurai | Dennis Mutwiri |
| Kassmatt Supermarket Ltd | Dennis Mutwiri |
| Kamindi Selfridges | Dennis Mutwiri |
| Kikuyu Selfridges (S/Markets) Ltd | Dennis Mutwiri |
| Eastleigh Mattresses Limited | Dennis Mutwiri |
| Jazaribu Retail Limited | Dennis Mutwiri |
| Khetia Drapers Ltd | Lawrence Amukhono |
| Maguna's Super Stores (K) Ltd | Lilian Kimeu |
| Bidii Supermarket - Matuu | Lilian Kimeu |
| Chandarana Supermarket | Kevin Werunga |
| Onn The Way Ltd | Kevin Werunga |
| 99 Mart Limited | Kevin Werunga |
| Artcafe Coffee & Bakery Ltd - Market | Kevin Werunga |
| Om Shiv Impex Ltd | Kevin Werunga |
| Patel & Brothers Enterprise Limited | Kevin Werunga |
| Karen Provision Stores Ltd | Kevin Werunga |
| Viman Distributors Kenya Ltd | Kevin Werunga |
| Peekaboo Limited | Kevin Werunga |
| China Village Ltd | Kevin Werunga |
| Defence Forces Welfare Services (DEFWES) | Zipporah Wangeci |
| Powerstar Supermarket | Zipporah Wangeci |
| Powerstar Supermarket - Zimmerman | Zipporah Wangeci |
| Powerstar Supermarket - Mini | Zipporah Wangeci |
| Cleanshelf Supermarket | Zipporah Wangeci |
| Bidii Supermarket - Ruiru | Zipporah Wangeci |
| Powerstar Supermarket - Jambo | Zipporah Wangeci |
| Powerstar Supermarket Vasha Limited | Zipporah Wangeci |
| Powerstar Supermarket - Kikuyu | Zipporah Wangeci |
| Powerstar Supermarket Kinoo Limited | Zipporah Wangeci |
| Powerstar Supermarket - Kangari | Zipporah Wangeci |
| Powerstar Supermarket - Kitengela | Zipporah Wangeci |
| Dimple Supermarket Ltd | Zipporah Wangeci |
| Powerstar Supermarket - Hyper | Zipporah Wangeci |
| The Zoros Company | Zipporah Wangeci |
| Kiddie Kloset | Zipporah Wangeci |
| Powerstar Supermarket - Annex | Zipporah Wangeci |
| Powerstar Supermarket - Joska | Zipporah Wangeci |

MT2 explicit outlets total 37 rows across 5 reps: Dennis Mutwiri (6), Kevin Werunga (10), Zipporah Wangeci (18), Lawrence Amukhono (1), and Lilian Kimeu (2). George Amenya and Beryl Muga hold MT2 regional assignments (Nyanza and Coast respectively) that are resolved through their regional rules rather than explicit outlet rows, and therefore do not appear in the MT2 outlet table.

**Kim-Fay Professional (`KP`)** — a customer is classified as KP when its Acumatica `Category` field starts with the prefix `KP`. This category-prefix match is the authoritative KP classifier and is independent of GT/MT team membership or department. A customer matching the `KP` prefix is routed to KP CRM subject to the §7.6 access gate; it never appears in GT or MT channel results unless it also carries an explicit approved MT or GT classification as a secondary channel.

## 8. Proposed Data Model

### 8.1 Existing tables to retain

`users`

- `rep_code`
- `employee_number`
- `reports_to_user_id`
- `department_id`
- `department_role`
- `org_level`
- `data_scope_mode`

`user_acumatica_rep_mappings`

- alternate and historical Acumatica rep aliases.

`user_customer_assignments`

- typed `owner` and `servicing` assignments;
- source, source batch, notes, and assigning user.

`departments` and `department_user`

- `gt` and `mt_consumer_sales` are the canonical commercial teams;
- primary and secondary department memberships identify team membership;
- application roles do not identify a user's commercial team.

### 8.2 Required extensions

Extend `user_customer_assignments`:

| Field | Type | Purpose |
|---|---|---|
| `effective_from` | date nullable | Start of time-aware attribution |
| `effective_to` | date nullable | End of attribution |
| `priority` | unsigned small integer | Deterministic source precedence |
| `assignment_rule_id` | FK nullable | Rule that produced the assignment |
| `is_manual_override` | boolean | Protect admin decisions from automated replacement |

Create `customer_assignment_rules`:

| Field | Purpose |
|---|---|
| `id`, `uuid` | Stable identity |
| `user_id` | Target servicing rep |
| `rule_type` | `customer`, `main_account`, `region`, or `rep_alias` |
| `match_value` | Normalized customer ID, main-account name, region, or identifier |
| `secondary_match_json` | Optional qualifiers such as `region = Nairobi` |
| `priority` | Resolution precedence |
| `source` | `manual`, `excel`, `acumatica`, `seeder` |
| `source_batch_id` | Import/seeder trace |
| `effective_from`, `effective_to` | Validity window |
| `is_active` | Operational status |
| `created_by`, timestamps | Audit |

Create `customer_attribution_audits`:

- customer ID;
- prior and new user;
- winning source and rule;
- competing candidates;
- resolution reason;
- actor or job;
- timestamp.

Create `department_hod_assignments`:

| Field | Purpose |
|---|---|
| `department_id` | GT or MT team |
| `hod_user_id` | Assigned HOD |
| `effective_from`, `effective_to` | Leadership history |
| `is_active` | Current assignment |
| `assigned_by` | Administrator who approved the change |
| `change_reason` | Required migration reason |
| timestamps | Audit |

Only one active HOD assignment may exist per department. The assigned HOD must be active and must have that department as a primary or approved leadership membership.

Create `team_migration_batches`:

- source department and HOD;
- destination department when members change team;
- old and new HOD;
- selected member IDs;
- preview statistics and validation errors;
- effective date and reason;
- status: `preview`, `applied`, `failed`, or `rolled_back`;
- created/applied by and timestamps.

Add or derive customer master attributes:

- `main_account_name`;
- normalized region;
- canonical sales channel;
- optional secondary sales channels;
- source timestamps.

Create a controlled `sales_channels` catalogue:

| Channel | Suggested code | Notes |
|---|---|---|
| Modern Trade Tier 1 | `MT1` | Child of Modern Trade; main accounts Majid, Naivas, and Quick Mart (§7.10) |
| Modern Trade Tier 2 | `MT2` | Child of Modern Trade; explicit MT2 outlets (§7.10) |
| General Trade | `GT` | General Trade customers |
| Direct-to-Consumer / Direct-to-Business | `DTC_DTB` | Definition must be approved before launch |
| E-commerce | `ECOMMERCE` | Digital commerce customers |
| Kim-Fay Professional | `KP` | Acumatica `Category` starts with `KP`; protected by the KP CRM access gate (§7.10) |

Each customer must have one canonical primary channel. Secondary channel classifications are allowed only when explicitly approved and must not cause transaction duplication. Combined views de-duplicate by transaction primary key and customer ID.

### 8.3 Optional performance snapshot

If runtime joins become expensive, add a materialized `customer_effective_assignments` table:

- one row per customer and assignment type;
- resolved user;
- winning rule/source;
- resolution timestamp;
- source hash.

This table is a cache of the deterministic rules, not a second source of truth.

## 9. Seeder and Import Plan

Create `ModernTradeCustomerPortfolioSeeder`.

The seeder must:

1. use stable user lookup keys, preferably email, then employee number;
2. require Lawrence's `moderntrade.exec@kimfay.com` user and `P272` identity before applying his 48 rows;
3. store a versioned source batch such as `mt-outlets-by-rep-2026-07`;
4. upsert explicit customer assignments as `servicing`;
5. upsert main-account and region rules;
6. preserve active manual overrides;
7. be idempotent;
8. report created, updated, unchanged, conflicted, and unresolved counts;
9. run inside a transaction after validation passes;
10. write audit records.

Do not embed binary Excel parsing in the production seeder. Convert the reviewed workbook to a version-controlled JSON fixture containing:

- source workbook name and checksum;
- approved date and approver;
- normalized user lookup;
- customer ID;
- customer name;
- main account;
- region;
- assignment source;
- exception note.

Expected first preview:

- 420 distinct outlet assignments;
- 10 workbook reps;
- 10 resolvable reps after provisioning Lawrence;
- 40 main-account source rows;
- zero duplicate customer IDs with competing reps after normalization.

The seeder must provision or verify Lawrence before resolving the workbook rows. It must fail if `P272` or `moderntrade.exec@kimfay.com` belongs to a different user.

## 10. Admin Workflow

Add a **Customer Portfolio** tab to Team Member details.

Capabilities:

- view direct servicing and owner assignments;
- view inherited team customer count separately;
- search by customer ID, outlet, main account, and region;
- show assignment source and winning precedence;
- preview Acumatica SO/customer matches using all identity aliases;
- upload and preview workbook-derived assignment rows;
- display conflicts, inactive matches, and unresolved reps;
- apply a reviewed batch;
- add or remove manual overrides;
- view assignment history;
- export the resolved portfolio.

The UI must clearly distinguish:

- `Direct` customers assigned to the selected user;
- `Inherited` customers visible because they belong to reportees;
- `Owner` versus `Servicing` assignment;
- `Automatic` versus `Manual override`.

Add a **Teams & Departments** administration screen. It must include GT, MT, KP, and every other active department.

Each team view must show:

- current HOD;
- direct and indirect reportees;
- members whose primary department differs from their reporting HOD's team;
- direct and inherited mapped-customer counts;
- revenue and volume summary;
- users with no active mappings;
- pending hierarchy or assignment conflicts.

Add **Migrate Team / Members** with two supported operations:

1. **Replace team HOD:** keep members in their current department and move the team hierarchy to a new HOD.
2. **Transfer selected members:** move selected users from any department to another department, including GT, MT, KP, Customer Service, Marketing, Finance, Operations, or a subsequently configured department, and attach them to an approved destination manager/HOD.

Migration preview must show:

- old and new HOD;
- affected direct and indirect reportees;
- users whose `reports_to_user_id` will change;
- primary department memberships that will change;
- secondary department memberships that will be retained or removed;
- customer mappings preserved;
- old and new team customer, SO, CN, revenue, and volume totals;
- permissions and product areas gained or revoked, including KP CRM;
- hierarchy cycles, cross-team managers, inactive users, and unmapped users;
- access gained and lost by each affected manager.

Apply behavior:

- require an effective date and change reason;
- execute the hierarchy and membership changes in one database transaction;
- preserve every individual customer assignment;
- preserve historical team/HOD attribution before the effective date;
- close the old HOD assignment and create the new active assignment;
- for HOD replacement, reparent only the old HOD's direct team reportees to the new HOD; nested supervisor/reportee relationships remain intact;
- for selected-member transfer, update the selected users' primary department and reporting manager while leaving unselected users unchanged;
- when a selected user has reportees, require the administrator to choose explicitly between `Member only` and `Member with subtree`; never move descendants implicitly;
- require an explicit decision to retain or remove each affected secondary department membership;
- recalculate department-derived capabilities after commit;
- entering KP does not grant KP CRM until the user also satisfies the dedicated KP CRM permission and cohort rules;
- leaving KP revokes department-derived KP CRM cohort access, navigation, exports, jobs, notifications, and cached data;
- moving between GT, MT, KP, or another department does not automatically copy the source department's permissions;
- never change application roles automatically;
- never move customers directly between consultants;
- invalidate hierarchy, portfolio, dashboard, export, and report caches after commit;
- write a complete migration audit and support a reviewed compensating rollback.

The new HOD cannot be:

- inactive;
- the same user as the old HOD;
- a descendant whose appointment would create a reporting cycle;
- outside the destination team without an approved department leadership membership;
- missing permission to view commercial team dashboards.

Add a **KP CRM Access** administration panel that shows:

- active KP department members;
- the five approved launch leadership users;
- access basis for every authorized user;
- missing or conflicting KP department/HOD assignments;
- last access change and actor.

Changes to the named leadership cohort require explicit administrative approval and audit logging. Assigning an ordinary shared role must not edit this cohort.

## 11. Dashboard Requirements

### 11.1 Sales Intelligence navigation

Create **Sales Intelligence** as the main navigation item:

```text
Sales Intelligence
├── My Portfolio
├── My Team
├── Modern Trade
│   ├── MT1
│   └── MT2
├── General Trade
├── DTC / DTB
├── E-commerce
└── KP
```

Use the full label `Sales Intelligence` unless the available sidebar width requires the shorter `Sales Intel` label.

Recommended routes:

```text
/app/sales-intelligence/portfolio
/app/sales-intelligence/team
/app/sales-intelligence/modern-trade/mt1
/app/sales-intelligence/modern-trade/mt2
/app/sales-intelligence/general-trade
/app/sales-intelligence/dtc-dtb
/app/sales-intelligence/ecommerce
/app/sales-intelligence/kp
```

Navigation visibility:

| Viewer | Visible entries |
|---|---|
| Sales Consultant without reportees | My Portfolio |
| User with reportees | My Portfolio and My Team |
| Authorized MT management | Modern Trade, MT1, and MT2 |
| Authorized GT management | General Trade |
| Authorized DTC/DTB users | DTC / DTB |
| Authorized e-commerce users | E-commerce |
| KP CRM cohort | KP |
| Approved executives | Their authorized channel views |
| Vignesh | Separate authorized channel views plus explicit Organization mode |
| Titus/support administrators | Explicit audited administrative mode |

The backend capability response determines which entries are rendered. Hidden navigation is not an authorization control; every route, API, export, job, and cached response must independently enforce the same channel capability.

`My Portfolio` and `My Team` are user scopes, not sales channels:

- `My Portfolio` uses exact mapped customers for the authenticated user;
- `My Team` uses the de-duplicated mapped portfolios of permitted reportees;
- channel pages use customers assigned to the selected canonical channel;
- channel access does not broaden a mapped-only consultant's personal portfolio;
- KP continues to require both `kp.crm.access` and approved KP cohort membership.

### 11.2 Dashboard behavior

Sales dashboards must provide:

- scope selector: `My Portfolio` / `My Team` when permitted;
- separate `General Trade` and `Modern Trade` team options when the viewer is authorized for both;
- `My Team` for any user who has at least one active direct or indirect reportee;
- reportee drill-down for any user with reportees, regardless of role label;
- customer, main account, region, and date filters;
- SO count, gross revenue, credit notes, net revenue;
- ordered, shipped, credited, and net volumes;
- backorders and revenue at risk;
- attribution explanation on customer and transaction detail.

Team drill-down flow:

```text
My Team
→ Direct reportees
→ Selected reportee summary
→ Selected reportee's direct customers
→ Customer detail
→ SO / CN / revenue / volume / backorder detail
```

When a selected reportee also has reportees, the screen must provide:

- `Direct Portfolio`: customers mapped directly to the selected reportee;
- `Team Portfolio`: de-duplicated customers mapped to that reportee and all descendants;
- `Reportees`: the next permitted drill-down level.

The dashboard must preserve the selected date range and metric filters throughout the drill-down and provide breadcrumbs back to each manager level.

Purity's default is `My Team`. Vignesh's default is `My Team`. Any other user with reportees may use `My Team`; a user without reportees is limited to `My Portfolio`.

Purity's team scope defaults to Modern Trade. Vignesh sees separate GT and MT totals and must deliberately select `Organization` to combine them.

KP CRM navigation is rendered only after the backend capability response confirms `kp.crm.access`. Hiding the navigation is supplementary; direct routes and APIs must enforce the same gate.

All Sales Intelligence pages must use the same metric definitions and shared components for:

- SO count and gross revenue;
- Credit Notes and net revenue;
- ordered, shipped, credited, and net volumes;
- backorders and revenue at risk;
- customer and reportee drill-down.

Date range, metric, channel, reportee, and customer filters must remain stable during drill-down and back navigation.

## 12. API Requirements

Centralize customer resolution in one service used by every module.

Required interfaces:

```text
resolveIdentity(alias) -> user | conflict | unresolved
resolveCustomerAssignment(customerId, asOfDate) -> effective assignment
directCustomerIds(userId, asOfDate) -> customer IDs
visibleUserIds(viewerId) -> self + permitted descendants
visibleCustomerIds(viewerId, mode, asOfDate) -> de-duplicated customer IDs
reporteeTree(viewerId) -> permitted descendant hierarchy
reporteePortfolio(viewerId, reporteeId, mode, asOfDate) -> authorized direct or team portfolio
teamPortfolio(viewerId, departmentId, asOfDate) -> authorized department portfolio
previewTeamMigration(actorId, sourceDepartmentId, destinationDepartmentId, newHodId, memberIds, includeSubtrees, effectiveDate) -> validated impact
applyTeamMigration(actorId, batchId) -> atomic hierarchy and membership change
canAccessKpCrm(userId) -> authorized access basis or denial
kpCrmCustomerIds(userId, mode, asOfDate) -> authorized mapped KP customer IDs
visibleSalesIntelligenceChannels(userId) -> authorized channel capabilities
channelCustomerIds(userId, channelCode, asOfDate) -> authorized channel customer IDs
explainCustomerAccess(viewerId, customerId, asOfDate) -> reason path
isMappedOnlyConsultant(userId) -> boolean
```

Every SO, CN, revenue, and volume endpoint must call the shared visibility service. Module-specific rep-code filters are prohibited once the central resolver is available.

Recommended enforcement:

- a Laravel Gate/Policy determines whether the mapped-only consultant rule applies;
- middleware attaches the resolved scope mode and visible customer IDs to the authenticated request context;
- query scopes still enforce `WHERE customer_acumatica_id IN (...)` server-side;
- controllers must not accept client-supplied customer IDs as proof of access;
- reportee drill-down endpoints must verify that the requested reportee is in the authenticated viewer's descendant tree;
- team endpoints must verify department/team visibility independently from application roles;
- every KP CRM route group must run the central KP CRM access middleware before granular action authorization;
- every Sales Intelligence channel endpoint must validate the requested channel against backend capabilities;
- scheduled exports and background report jobs must call the same resolver as HTTP dashboards.

## 13. Security and Audit

- Enforce scope in backend queries; frontend filters are not security controls.
- Validate every manual mapping against the exact synchronized Acumatica customer ID before persistence.
- A mapped-only Sales Consultant can access only transactions whose customer ID is actively mapped to them.
- Other roles assigned to the same user do not share, expand, or bypass that mapped customer set.
- Only authorized admins may change assignments or hierarchy.
- HOD/executive access is read-only unless separately granted assignment permission.
- Never expose sibling or manager-only portfolios to reportees.
- Log previews, applies, manual changes, rule changes, and hierarchy changes.
- Preserve historical attribution when users are deactivated or moved.
- Restrict team/HOD migration to an explicit permission such as `team.manage_hierarchy`; HOD status alone is insufficient.
- Require preview confirmation, reason, effective date, and audit logging for every team migration.
- Deny KP CRM by default unless the user passes both the dedicated permission and approved cohort checks.
- Do not expose KP CRM aggregate counts, customer names, autocomplete results, notifications, or cached payloads to denied users.
- Revoke KP CRM sessions/caches immediately after department or leadership access changes.
- Include the winning rule and hierarchy path in access explanations.

## 14. Data Quality Controls

Block batch application when:

- a workbook rep is unresolved;
- an alias matches multiple active users;
- one customer resolves to multiple servicing reps at the same priority;
- a customer ID is missing from the synchronized Acumatica customer master;
- a main-account or region rule has an empty normalized match value.

Warn but allow reviewed application when:

- the transaction rep differs from the assigned servicing rep;
- the customer has no recent SO;
- narrative coverage and workbook rows differ;
- a main account has inconsistent spelling across outlets.

Provide reconciliation totals by:

- source row count;
- distinct customer count;
- rep;
- region;
- main account;
- resolved/unresolved/conflict status.

## 15. Acceptance Criteria

1. An Acumatica alias matching either `employee_number` or `rep_code` resolves to the correct active user.
2. An alias conflict never resolves silently.
3. Each of the 420 workbook customer IDs appears once in the preview.
4. Lawrence's 48 rows resolve to `moderntrade.exec@kimfay.com` through `P272`.
5. Beryl sees exactly her 34 explicit Coast customers in the initial seed.
6. George sees exactly his 22 explicit Nyanza customers in the initial seed.
7. A Quick Mart outlet is assigned to Georgina regardless of region unless an explicit override exists.
8. Purity can see the union of all customers assigned to her descendants.
9. Vignesh can see the same Modern Trade subtree through Purity.
10. A reportee cannot see a sibling's customers.
11. SO, CN, backorder, revenue, and volume endpoints return the same customer scope for the same user and date.
12. Purity's and Vignesh's rollups do not double-count transactions.
13. Credit notes reduce net revenue and net volume and are also shown separately.
14. Re-running the seeder creates no duplicate assignments or rules.
15. Manual overrides survive subsequent automated imports.
16. Every visible customer can explain whether access is direct or inherited and identify the winning assignment source.
17. A manual assignment cannot be applied when its customer ID does not exist in the Acumatica customer master.
18. A manually mapped customer appears consistently in the customer list and all SO, CN, revenue, volume, backorder, and export views.
19. A Sales Consultant with mapped outlets cannot see an unmapped customer even when an SO uses their `rep_code` or `employee_number`.
20. Giving that Sales Consultant an additional ordinary role does not broaden their mapped-only customer scope.
21. A user without the Sales Consultant role does not inherit customer access from another user merely because they share a secondary role.
22. Purity and Vignesh see the de-duplicated union of mapped reportee customers through hierarchy, without rep-code-based extras.
23. Any user with an active reportee receives `My Team` access even when the user is not labeled HOD, manager, or executive.
24. A manager can drill from a reportee summary to that reportee's exact mapped customers and then to customer transactions.
25. A manager can continue drilling through multiple reporting levels when a reportee has reportees.
26. A user cannot request a sibling, manager, or unrelated user's portfolio by changing a reportee ID in the URL or API request.
27. Direct and inherited customers are separately identified at every reportee drill-down level.
28. GT and MT dashboards return separate member, customer, revenue, and volume totals.
29. A shared Sales Consultant role never causes a GT user to inherit MT customers or an MT user to inherit GT customers.
30. Replacing a team HOD preserves consultant customer mappings and nested reporting relationships.
31. Transferring selected members between any departments changes only the explicitly selected member or approved subtree, primary department, approved secondary memberships, and reporting manager.
32. A team migration is rejected when it creates a hierarchy cycle or selects an ineligible HOD.
33. Historical dashboards retain the former HOD/team relationship before the migration effective date.
34. Current dashboards reflect the new HOD/team relationship after the migration effective date.
35. Failed team migrations roll back hierarchy, membership, HOD assignment, and audit status consistently.
36. Active KP department members can access KP CRM subject to their mapped customer scope.
37. Titus, Vignesh, Susan, Hartaj, and Raj can access KP CRM through their approved leadership assignments.
38. A GT or MT user with only a shared Sales Consultant or Customer Service role cannot access KP CRM.
39. A user with a granular KP CRM action permission but without cohort access is denied at the route gate.
40. Susan can drill into her KP reportees and their mapped KP customers without gaining ownership of those customers.
41. Titus, Vignesh, Hartaj, and Raj see a de-duplicated KP team portfolio.
42. Removing KP membership or leadership approval revokes navigation, API, export, job, notification, and cached KP CRM access.
43. Direct URL or modified API requests cannot bypass the KP CRM cohort gate.
44. An administrator can transfer selected members between GT, MT, KP, and any other active department.
45. Selecting a manager for transfer does not move that manager's reportees unless `Member with subtree` is explicitly confirmed.
46. Moving a user into KP recalculates but does not bypass the dedicated KP CRM permission and cohort gate.
47. Moving a user out of KP revokes department-derived KP CRM access and invalidates all affected caches and pending report deliveries.
48. A department transfer preserves the user's customer assignments and does not copy roles or permissions from the source department.
49. Sales Intelligence renders only the submenus returned by the authenticated backend capability response.
50. Direct access to a hidden or unauthorized Sales Intelligence route returns `403`.
51. A Sales Consultant without reportees sees My Portfolio and no unauthorized team or channel entries.
52. A user with reportees sees My Team and can drill into permitted reportee portfolios.
53. MT1 and MT2 customers appear under Modern Trade without being counted twice in a combined Modern Trade total.
54. GT, DTC/DTB, E-commerce, and KP channel pages contain only customers classified for the selected channel.
55. KP remains inaccessible unless both the KP CRM permission and cohort gate pass.
56. Secondary customer channel classifications do not duplicate SO, CN, revenue, volume, or backorder transactions.
57. The same transaction and metric definitions produce consistent totals across My Portfolio, My Team, and authorized channel pages.
58. Filters remain stable while navigating from channel to reportee to customer to transaction detail.

## 16. Test Plan

### Unit tests

- identifier normalization and precedence;
- employee-number/rep-code collision;
- customer rule precedence;
- main-account and region matching;
- effective-date resolution;
- hierarchy descendant calculation;
- rollup de-duplication;
- CN sign and net metric calculations.

### Feature tests

- reportee, Purity, and Vignesh visibility;
- team visibility for a user with reportees but no HOD/manager/executive role label;
- nested reportee-to-customer drill-down;
- sibling and upward-access denial;
- forged or unrelated reportee-ID denial;
- GT/MT scope isolation;
- HOD replacement with nested reportees;
- selected-member and subtree transfers between GT, MT, KP, and other departments;
- secondary-membership retain/remove choices;
- KP CRM grant/revocation recalculation after department transfer;
- Sales Intelligence capability-driven navigation;
- direct-route denial for every unauthorized channel;
- MT1/MT2 combined-view de-duplication;
- primary and secondary channel classification;
- cross-view metric consistency and filter persistence;
- cycle and ineligible-HOD rejection;
- transactional rollback and cache invalidation after team migration;
- KP cohort and named-leadership access;
- denial for shared roles and granular permission without cohort membership;
- direct-route, export, background-job, notification, and cache denial;
- assignment preview and apply;
- Lawrence provisioning and identity-conflict protection;
- manual override preservation;
- shared scope across SO, CN, revenue, and volume endpoints;
- historical attribution after hierarchy change.

### Seeder tests

- fixture checksum and row totals;
- 420 unique customers;
- expected per-rep counts;
- idempotent second run;
- transaction rollback on conflict.

## 17. Delivery Phases

### Phase 1: Reviewed explicit assignments

- Provision Lawrence and attach him to Purity.
- Separate GT and MT memberships and correct cross-team reporting relationships.
- Assign the approved current HOD for each team.
- Record Susan as the approved KP HOD and seed the KP CRM launch cohort.
- Convert workbook to an approved JSON fixture.
- Seed 420 direct assignments.
- Confirm Purity and Vignesh hierarchy.
- Apply shared customer scope to SO and existing sales portfolio metrics.

### Phase 2: Rules and reconciliation

- Add previewable HOD replacement and selected-member team migration.
- Add main-account and region rules.
- Add precedence, effective dates, conflict queue, and audit explanation.
- Reconcile workbook rules against Acumatica customer master.

### Phase 3: Full commercial attribution

- Apply scope to CN, revenue, volumes, backorders, and exports.
- Add the capability-driven Sales Intelligence menu and channel pages.
- Add team/reportee dashboard filters.
- Add attribution quality monitoring.

## 18. Open Decisions

1. Confirm the initial GT HOD and which current Purity reportees must move into the GT team.
2. Confirm whether the former HOD becomes a reportee of the new HOD, moves to another manager, or is removed from the team after migration.
3. ~~Approve the exact business definitions and customer classification rules for MT1 and MT2.~~ **Resolved:** MT1/MT2/KP classifications are defined in §7.10. MT1 = Majid, Naivas, and Quick Mart main accounts; MT2 = the 37 explicit outlets listed in §7.10; KP = any customer whose Acumatica `Category` starts with the prefix `KP`.
4. Confirm what DTB means operationally and whether DTC and DTB should remain one combined channel.
5. Confirm whether E-commerce may be a secondary channel for customers whose primary channel is MT, GT, or DTC/DTB.
6. Confirm whether `Naivasha S.S.S Stores Limited` is the Acumatica main-account representation of Naivas for Lucy's rule.
7. Confirm whether CN revenue should use document total or summed line extended price when both exist.
8. Confirm whether dashboard volume means ordered quantity, shipped quantity, or both by default.
9. Confirm whether owner and servicing rep may differ in the Modern Trade launch.
10. Confirm the effective start date for the workbook assignments and whether historical transactions should be re-attributed.
11. Confirm whether Vignesh's org-wide access should remain broader than Modern Trade when using the general dashboard.

## 19. Success Measures

- 100% of approved workbook customers resolve to one servicing rep.
- 0 unresolved active Acumatica aliases after remediation.
- 0 transaction duplication in HOD and executive rollups.
- 0 unauthorized KP CRM routes, exports, notifications, or cached payloads.
- One shared scope result across SO, CN, revenue, volume, backorder, and export modules.
- Every assignment and dashboard result can be explained from user to customer to source rule.
