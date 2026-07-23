# PRD: Staff Identity Reconciliation & Customer Portfolio Import (July 2026 data drop)

**Status:** Draft, 2026-07-23. Grounded in (a) a direct inspection of the three source workbooks, and (b) a full audit of the existing staff-import/rep-mapping/customer-assignment pipeline — most of the plumbing this PRD needs already exists; this is an **extension**, not a new system.

**Owner:** [fill in]
**Requested by:** Titus (via forwarded email — "Beryl Muga and George Amenya do not have any main accounts...")
**Source files:**
1. `excels/Staff_Email_Match_July_2026.xlsx` — staff↔email↔employee-number reconciliation
2. `excels/MT Outlets by Rep.xlsx` — Modern Trade outlet-to-rep mapping
3. `excels/Customers 20260713.xlsx` — full Acumatica customer master (2,740 rows, all channels)

---

## 1. What's actually in these files (verified, not assumed)

### 1.1 `Staff_Email_Match_July_2026.xlsx` — 3 sheets

| Sheet | Rows | Columns |
|---|---|---|
| **Matched Staff** | 100 (title says "96 of 141 active email users matched") | Employee Name, Email Address, Employee Number, Department, Division, Designation, Source List |
| **Missing Employee Number** | 49 | User (Email Display Name), Email Address — active email users with no employee record |
| **Missing Email** | 434 | Employee Number, Employee Name, Department, Division, Designation, Source — employees with no active email account |

Employee Number values follow two prefixes: `P###` (permanent staff, e.g. `P245`) and `C####` (STC/casual staff, e.g. `C1076`) — with rare exceptions that don't follow either pattern (e.g. `YVON` in the Customers export, §1.3).

### 1.2 `MT Outlets by Rep.xlsx` — 2 sheets

| Sheet | Rows | Columns |
|---|---|---|
| **Outlets** | 421 | Customer ID, Customer Name, Main Account Name, **Sales Rep** (name, no code), Region |
| **Main Account By Rep** | 41 | Main Account Name, Channel, **Rep** (name, no code) |

Neither sheet carries a Rep Code or Employee Number — only rep display names. **Outlets** is the per-branch source of truth (421 individual customer IDs); **Main Account By Rep** is a coarser 41-row rollup, useful for cross-validation, not primary assignment.

### 1.3 `Customers 20260713.xlsx` — full Acumatica export

**Data** sheet, 2,740 rows, all customer classes/channels (KP, Consumer/CS, GT, MT — not MT-only). Relevant columns: `Customer ID`, `Customer Name`, `Customer Class`, **`Sales Rep`** (name), **`Rep Code`** (e.g. `P505`, `C967`, and the occasional non-conforming code like `YVON`), `Customer Region`, `Customer Zone`, `Main A/CC Owner`, `Parent Code`. This is the only one of the three files that carries **both** a rep name and a rep code on the same row — useful as a validation anchor and as the base layer for non-MT customers.

---

## 2. What already exists — do not rebuild this

| Piece | What it does | File |
|---|---|---|
| `StaffImportService` | Consumes an **already-matched** staff file (JSON or Excel), matches to `User` **by email only**, force-fills `name, employee_number, department_id, department_role, org_level, product_type_scope, data_scope_mode`. Skips overwrite if a prior manual org-config edit exists (`preserveManual`). | `backend/app/Services/Team/StaffImportService.php` |
| `StaffImportGap` + review UI | Unmatched/low-confidence rows land here (`gap_reason`: `no_staff_match`, `no_email_match`, `low_confidence`); reviewable/resolvable via the `StaffImportPanel` admin UI (`/app/team`), including "create user from gap." | `backend/app/Models/StaffImportGap.php`, `src/components/admin/StaffImportPanel.tsx` |
| `team:import-staff` | Artisan wrapper: `--path --dry-run --preserve-manual --min-confidence`. | `backend/app/Console/Commands/ImportTeamStaff.php` |
| `UserAcumaticaRepMapping` | A user can hold **multiple** `acumatica_rep_code`/`acumatica_consultant_id` values, one flagged `is_primary`. This is the existing "keep both if they don't match" mechanism. | `backend/app/Models/UserAcumaticaRepMapping.php` |
| `CustomerAssignmentService` | `syncAssignments()` already defaults to `assignment_type = 'servicing'` (`'primary'` is a legacy value only matched for cleanup). Three bulk-import paths — `previewFromSalesOrders`, `previewFromCustomerEndpoint`, `previewUpload` (CSV/XLS/XLSX of `rep_code` + `customer_id`) — **all** go through one preview→apply flow (`CustomerAssignmentBatch`/`CustomerAssignmentBatchRow`), resolving a rep code against `users.rep_code`, `users.employee_number`, **and** `UserAcumaticaRepMapping` in one union. | `backend/app/Services/Team/CustomerAssignmentService.php` |
| `customers:upload-assignments {file} --dry-run` | Artisan wrapper for `previewUpload`/`applyBatch`. | `backend/app/Console/Commands/UploadCustomerAssignments.php` |
| `agent-tools/match_staff_emails.py` | Offline fuzzy name-matcher (Python `difflib`, thresholds high≥0.9/medium≥0.75/low<0.75) that produces the "already-matched" file `StaffImportService` expects. | `agent-tools/match_staff_emails.py` |

### 2.1 Real gaps this PRD closes (everything else above is reuse)

| # | Gap | Why it matters here |
|---|---|---|
| GAP1 | **No `designation` or `division` column on `users`.** `StaffImportService` receives these fields in every row but silently drops them — they're never persisted. | The Matched Staff sheet has both; the user explicitly asked for designation to be updated. |
| GAP2 | **`StaffImportService`'s `loadRows()` header aliases don't cover "Employee Name" / "Email Address" literally** (confirm exact expected keys before running against this file — this needs a header-mapping check, not a rewrite). | Ensures `Staff_Email_Match_July_2026.xlsx` can be fed in with zero/minimal adapter code. |
| GAP3 | **rep_code vs employee_number conflicts are never detected or recorded.** `StaffImportService` doesn't touch `rep_code` at all today, so a mismatch between the Acumatica-synced `rep_code` and the HR file's `employee_number` is silently never surfaced. | This is the literal ask: *"if the repcode/employeeid/number dont match add both."* |
| GAP4 | **Bulk-imported rep_code changes bypass `UserRepCodeHistory`.** Only the manual `UserController::update()`/`restoreRepCode()` paths currently log to history. | Any auto-backfill of `rep_code` from this import must be audited like every other rep_code change. |
| GAP5 | **No name→user resolver for the MT Outlets / Main Account sheets.** These two sheets carry only display names (no rep code), and `CustomerAssignmentService`'s existing resolver works off rep code/employee_number, not names. | Required before `previewUpload()` can even be called against these two sheets. |
| GAP6 | **No encoding of the named business-rule exceptions** (region-wide blanket assignment, main-account-wide assignment irrespective of branch, named carve-outs that override a blanket rule). | This is the actual hard part of Part 2 — see §4. |
| GAP7 (informational, not in scope here) | `SalesConsultantScope` (order/customer visibility) reads only `users.rep_code` directly — it never consults `UserAcumaticaRepMapping`. So a secondary/alternate rep code recorded under GAP3's fix will **not** yet affect what a user actually sees in the app. | Already documented as its own gap in `docs/PRD-customer-visibility-fairness.md` (Gap 2). Cross-referenced, not re-solved here — don't duplicate that PRD's scope. |

---

## 3. Part A — Staff identity reconciliation

### 3.1 Goal

For every row in **Matched Staff** (100 rows), ensure the corresponding `User` record has an accurate `employee_number`, `department_id`, `designation`, and `division`, and that any discrepancy between the file's `Employee Number` and the user's existing `rep_code` is preserved (never silently overwritten) and flagged for review.

### 3.2 Functional requirements

| # | Requirement |
|---|---|
| FR-A1 | Add `designation` (string, nullable) and `division` (string, nullable) columns to `users`. Persist both in `StaffImportService`'s force-fill list (currently missing — GAP1). |
| FR-A2 | Verify/patch `StaffImportService::loadRows()`'s header-normalization so `Staff_Email_Match_July_2026.xlsx`'s literal headers (`Employee Name`, `Email Address`, `Employee Number`, `Department`, `Division`, `Designation`) map correctly without a manual pre-processing step. If the existing snake-case normalizer already produces `employee_name`/`email_address`/etc., add whichever aliases the service is missing (e.g. `employee_name` → `display_name`, `email_address` → `email`) rather than renaming the service's internal field names. |
| FR-A3 | Since every row in **Matched Staff** is, by the file's own definition, an already-confirmed match, treat rows from this sheet as `match_score = 1.0` / `match_confidence = 'high'` when the columns aren't present in the file — don't require `min_confidence` gymnastics for a sheet that's already curated. |
| FR-A4 | **Rep-code reconciliation, run once per matched user, after the existing force-fill:** <br>— If `user.rep_code` is null and `employee_number` (from file) looks like a valid Acumatica rep code (matches known patterns or is independently confirmed against the Customers export's `Rep Code` column for that person's name), backfill `user.rep_code` and log the change to `UserRepCodeHistory` (closes GAP4) with `change_reason = 'staff_import_backfill'`. <br>— If `user.rep_code` is already set and **equals** the file's `employee_number`, no action needed (already consistent). <br>— If `user.rep_code` is already set and **differs** from the file's `employee_number`, do **not** overwrite either value. Instead, upsert a `UserAcumaticaRepMapping` row for the user with `acumatica_rep_code = employee_number`, `is_primary = false`, and surface the conflict in a review list (reuse the `StaffImportGap` table/UI pattern with a new `gap_reason = 'rep_code_employee_number_mismatch'`, or a lightweight sibling table if gap semantics don't fit — implementer's call, but it must be reviewable in the same admin surface as other import gaps, not a silent log line). |
| FR-A5 | Rows in **Missing Employee Number** (49) and **Missing Email** (434) are informational only in this pass — no `User` record exists to update for the latter (no email = no app account), and the former needs a human decision per person, not an automated guess. List both in the PRD's rollout as a manual admin follow-up, not an automated write. |
| FR-A6 | Dry-run by default (`team:import-staff --path=... --dry-run`), reusing the exact existing command — no new flag needed for the base import. FR-A4's reconciliation step should itself respect the same dry-run flag (preview the mismatch list before writing any `UserAcumaticaRepMapping` row). |

### 3.3 Guardrails

| # | Guardrail |
|---|---|
| G-A1 | Never overwrite a non-null `rep_code` from a bulk import — conflicts are recorded alongside the existing value, never replacing it (FR-A4). |
| G-A2 | Every rep_code write (backfill or otherwise) from this import path is logged to `UserRepCodeHistory` — closing the audit gap this import would otherwise reopen. |
| G-A3 | `preserveManual` behavior (don't clobber a user whose org config was manually edited since the last import) carries over unchanged from the existing service — this PRD adds fields to the force-fill list, it doesn't change when force-fill applies. |
| G-A4 | Missing-email employees (434 rows) are never auto-provisioned a `User` account as a side effect of this import — that's a distinct, bigger decision (licensing, security) outside this PRD's scope. |
| G-A5 | **Strict field whitelist, always.** Every write path in this PRD (staff import, rep-code reconciliation, the production seeder in §5) touches only the explicitly named fields — `name`/`employee_number`/`department_id`/`designation`/`division`/`org_level`/`product_type_scope`/`data_scope_mode` for Part A, `acumatica_rep_code`/`is_primary` for the mapping table, `assignment_type`/`source`/`source_batch_id` for Part B. **`password` and any other column not explicitly named here is never written, under any circumstance.** No step may `forceFill($row)`/mass-assign a whole spreadsheet row onto a `User` — always an explicit field-by-field `fill([...])` with a named key list, never the raw row array. A user not present in the matched source list is not touched at all — not even a `save()` no-op. |

---

## 4. Part B — Customer portfolio import (the "no bleeding" problem)

### 4.1 The core difficulty

Two of the three data sources give rep **names**, not codes (Outlets, Main Account By Rep), and the human-written business rules in the forwarded email **override** what those sheets literally say in several documented cases. Applying the sheets naively — or applying the named rules in the wrong order — produces exactly the "bleeding" the request warns against: an outlet silently credited to the wrong rep. This section defines a strict precedence order so that never happens by construction.

### 4.2 Precedence order (highest wins)

1. **Named branch-level carve-out** — an explicit exception naming a specific sub-set of outlets that overrides a blanket rule below it. Two are documented today:
   - Naivas outlets **in Thika** → **Lilian Kimeu** (overrides rule 3's "all Naivas → Lucy Wanjiru").
   - Magunas outlets **in Nairobi** → **Dennis Mutwiri** (overrides rule 3's "Magunas main account → Lilian Kimeu").
2. **Named main-account-wide rule** — a rep owns every branch of a named main account, regardless of region: Georgina Kiilu (Quick Mart), Lucy Wanjiru (Naivas, minus the Thika carve-out), Jane Kuria (Majid Al Futtaim), Lilian Kimeu (Magunas, minus the Nairobi carve-out), Lawrence Amukhono (Khetias), plus Kevin Werunga's and Dennis's named main accounts (§4.3).
3. **Named region-wide rule** — a rep owns every MT outlet in a region regardless of main account: Beryl Muga (all 34 Coast outlets), George Amenya (all 22 Nyanza outlets), Lilian Kimeu (all MT outlets in "Mountain"), Lawrence Amukhono (all MT outlets in "Rift").
4. **Literal per-outlet value** in the **Outlets** sheet (the 421-row per-branch "Sales Rep" column) — the default/fallback source of truth for anything not covered by rules 1–3.
5. **Main Account By Rep** (41-row rollup) is used only as a **cross-check**, never as a primary assignment source — if it disagrees with what rule 1–4 resolved for any of its main account's branches, that's a data-quality flag, not an authority.

### 4.3 Named assignments, verbatim from the source email

| Rep | Assignment | Type |
|---|---|---|
| Beryl Muga | All 34 Coast outlets, no main account | Region-wide (rule 3) |
| George Amenya | All 22 Nyanza outlets, no main account | Region-wide (rule 3) |
| Georgina Kiilu | Quick Mart, all branches, any region | Main-account-wide (rule 2) |
| Lucy Wanjiru | Naivas, all branches, any region, **except** Thika | Main-account-wide (rule 2), carved out by rule 1 |
| Jane Kuria | Majid Al Futtaim, all branches, any region | Main-account-wide (rule 2) |
| Kevin Werunga | Chandarana, China Village, On the Way (named main accounts, multi-branch) **+** whatever the Outlets sheet already attributes to him elsewhere | Main-account-wide (rule 2) + literal fallback (rule 4) |
| Zipporah Wangeci | Whatever **Main Account By Rep** lists under her name, applied to every branch of those accounts via the Outlets sheet join | Main-account-wide (rule 2), sourced directly from the rollup sheet |
| Lilian Kimeu | Magunas (main account, **except** Nairobi branches) **+** all MT outlets in "Mountain" **+** Naivas outlets in Thika | Main-account-wide (rule 2) + region-wide (rule 3) + carve-out recipient (rule 1) |
| Lawrence Amukhono | Khetias (main account) **+** all MT outlets in "Rift" | Main-account-wide (rule 2) + region-wide (rule 3) |
| Dennis Mutwiri | Kassmart, Leestar, Jaza, Eastleigh, Kamindi, Kikuyu Selfridges (named main accounts) **+** all Magunas outlets in Nairobi **+** "some" Powerstar and Cleanshelf outlets | Main-account-wide (rule 2) + carve-out recipient (rule 1) + literal fallback (rule 4 — see §4.4) |

### 4.4 The one genuinely ambiguous case: "some Powerstar and Cleanshelf outlets" (Dennis)

The **Main Account By Rep** sheet already lists Powerstar Supermarket, Powerstar Supermarket - Zimmerman, Powerstar Supermarket - Mini, Powerstar Supermarket - Jambo, and Cleanshelf Supermarket all under **Zipporah Wangeci**. The email says Dennis has "some" of these — this is not resolvable from the rollup sheet at all. **Resolution: trust the Outlets sheet's literal per-branch value (rule 4) for these specific main accounts.** Wherever an individual Powerstar/Cleanshelf outlet row's own `Sales Rep` column already says "Dennis Mutwiri," that's authoritative for that branch, overriding the main-account-level Zipporah default — this is exactly rule 1's mechanism (a specific row overriding a coarser rule), just discovered from the data rather than named in the email. **This must be verified against the full 421-row sheet during implementation, not assumed from the ~15-row sample already reviewed.**

### 4.5 Functional requirements

| # | Requirement |
|---|---|
| FR-B1 | Build a name→user resolver: for every distinct `Sales Rep`/`Rep` name string across both new sheets, resolve to a `user_id` via exact match against `Matched Staff`'s `Employee Name` (after Part A's import), falling back to the existing fuzzy-match approach (`agent-tools/match_staff_emails.py`'s scoring logic) for near-misses (e.g. "Lawrence Amukhono" vs "Lawrence Amukhono Amukhono"). Anything below the match threshold goes to a review queue — **never** guessed. |
| FR-B2 | Expand the §4.2 precedence rules into concrete `(customer_id, resolved_user_id)` pairs — this expansion is a **new pre-processing step**, not a new assignment mechanism. Its output feeds directly into the existing `CustomerAssignmentService::previewUpload()`/`applyBatch()` pipeline (already accepts `customer_id` + `rep_code`; resolve each `user_id` back to that user's `rep_code`/employee_number for the file it hands to that pipeline, or extend `previewUpload()` to accept a resolved `user_id` column directly — implementer's call on which is less invasive). |
| FR-B3 | For the Customers 20260713.xlsx (2,740-row) file: this is the base/default layer for **non-MT** customers, and a cross-check for MT customers already covered by §4.2. Use its `Rep Code` column directly (already machine-resolvable, no name matching needed) as the row-level assignment for anything not already resolved by the MT-specific rules. Where the same customer ID appears in both this file and the MT Outlets sheet with a **different** resolved rep, the MT Outlets/business-rule resolution wins (it's the more current, curated source) and the Customers-export value is logged as a superseded/stale data point for visibility, not silently discarded. |
| FR-B4 | Every resulting assignment row must carry a `source` value identifying exactly which rule produced it (e.g. `mt_outlets_carveout_thika`, `mt_outlets_region_coast`, `mt_outlets_literal`, `customers_export_2026_07_13`) — reusing `UserCustomerAssignment`/`CustomerAssignmentBatch`'s existing `source`/`source_batch_id` columns, not inventing a parallel audit trail. |
| FR-B5 | Full dry-run preview mandatory before any commit — this is already how `previewUpload()`/`applyBatch()` works; this PRD does not weaken that. The preview output must be reviewable per the §4.6 acceptance criteria before a human approves the batch. |

### 4.6 Acceptance criteria (the two documented conflicts, as literal test cases)

- [ ] A Naivas outlet whose `Region` is Thika resolves to **Lilian Kimeu**, not Lucy Wanjiru, even though every other Naivas branch resolves to Lucy.
- [ ] A Magunas outlet whose `Region`/city is Nairobi resolves to **Dennis Mutwiri**, not Lilian Kimeu, even though every other Magunas branch resolves to Lilian.
- [ ] All 34 Coast-region MT outlets resolve to Beryl Muga regardless of what (if anything) the Outlets sheet's literal `Sales Rep` column says for those rows.
- [ ] All 22 Nyanza-region MT outlets resolve to George Amenya, same rule.
- [ ] Every Quick Mart / Naivas (non-Thika) / Majid Al Futtaim branch resolves to Georgina / Lucy / Jane respectively, regardless of region.
- [ ] No customer ID resolves to more than one `user_id` as a `servicing` assignment in the same batch — a resolver producing two different owners for one customer must fail the batch (or flag it), never write both silently.
- [ ] Every unresolved rep-name string (no confident match to a known user) appears in a reviewable list before commit — zero silent drops.
- [ ] Dry-run output for this import is reviewed and approved by a human before `applyBatch()` runs for real.

---

## 5. Production deployment — idempotent seeder

Everything above (§3–4) describes how the data gets **reviewed and resolved**. This section defines how the reviewed result actually **ships to production** safely — as a one-time, idempotent Laravel seeder, not a live re-run of the Excel-parsing/name-resolution logic against production data.

### 5.1 Why a seeder, and why frozen data

The precedence resolution (§4.2–4.4) and fuzzy name matching (FR-B1) are **review-time** operations — they should run once, in a staging/review pass against the actual workbooks, with a human approving the preview (per FR-B5/§4.6). The seeder does **not** re-run that resolution logic against live Excel files in production. Instead:

- The approved output of Part A (user field reconciliation + rep-code conflict list) and Part B (the expanded `(customer_id, user_id, source)` pairs from §4.2's precedence rules) is committed to the repo as a **versioned, frozen data file** — e.g. `backend/database/seeders/data/2026-07-staff-and-portfolio-import.json` — reviewed via a normal pull request, same as any other code change.
- The seeder reads **this frozen file**, never the raw `excels/*.xlsx` workbooks. Production never needs those workbooks present on disk, and a later edit to the source spreadsheet (or someone re-running the fuzzy matcher and getting a slightly different score) can't silently change what a production re-run does.
- If the underlying data needs a correction after review, the fix goes into a **new, separately-versioned** data file and a follow-up seeder — never edited in place after it's been reviewed and deployed once, so "what ran in production" stays a fixed, auditable artifact.

### 5.2 Idempotency contract

| # | Requirement |
|---|---|
| FR-S1 | The seeder is safe to run more than once (e.g. an accidental redeploy re-trigger) without creating duplicate rows anywhere it writes: `updateOrCreate`/upsert keyed on the natural key in each table (`user_id` for the Part A field reconciliation; `user_id + acumatica_rep_code` for `UserAcumaticaRepMapping`; `user_id + customer_acumatica_id + assignment_type` for `UserCustomerAssignment`). |
| FR-S2 | Every row the seeder writes is stamped with a fixed, versioned `source_batch_id` (e.g. `staff-portfolio-import-2026-07`, reusing the existing `source`/`source_batch_id` columns from FR-B4) so a re-run can check "did this batch already apply?" and report a no-op instead of blindly reapplying or silently skipping. |
| FR-S3 | `UserRepCodeHistory` writes (from FR-A4's rep_code backfill) are guarded against logging a no-op — if the "before" value in a rerun already matches what would be written, skip the history row rather than recording an identical change twice. |
| FR-S4 | The whole seeder runs inside a single DB transaction. If any guardrail from §4.6 is violated at seed time (e.g. a customer ID resolving to two different `user_id`s in the frozen data — which review should have already caught, but the seeder re-checks defensively), it throws and rolls back the **entire** batch — never leaves a half-applied state partway through 2,740+ rows. |
| FR-S5 | On completion (success or failure), the seeder logs a structured summary — users updated, rep-code conflicts recorded, assignments created/updated/skipped/failed — using the same structured-logging pattern already used for sync runs elsewhere in this codebase. This log is the production audit trail for "what did this deploy actually change," independent of anyone reading the database directly afterward. |

### 5.3 Deployment guardrails

| # | Guardrail |
|---|---|
| G-S1 | **Not part of the default seed set.** This is a one-time operational data load for this specific July 2026 data drop, not part of `DatabaseSeeder::run()`'s fresh-install path. It's invoked explicitly and once: `php artisan db:seed --class=StaffPortfolioImport2026JulySeeder`, documented as an explicit step in the deploy runbook — never wired into `migrate --seed` or any automatic pipeline. |
| G-S2 | **No runtime dependency on the Excel files.** Because it reads the frozen JSON (§5.1), it has zero dependency on `excels/*.xlsx` being present on the production filesystem or deploy artifact. |
| G-S3 | **Depends on migrations, not the other way around.** Only runs after the `designation`/`division` migration (FR-A1) has applied — the seeder should assert the columns exist (or let the write fail loudly) rather than silently no-op on an old schema. |
| G-S4 | **Dry-run against a production-like snapshot before the real run.** Run the seeder against a recent production DB copy (or the closest staging equivalent) first, diff the resulting assignment/user-field counts against the reviewed preview from §4.6, and only then run it against production for real. |
| G-S5 | **Re-run safety is a property of the seeder, not a promise in a runbook.** FR-S1–S3 must hold even if someone runs the command twice by mistake — this is enforced in code, not just documented as "don't do that." |

---

## 6. Non-goals

- Fixing `SalesConsultantScope` to consult `UserAcumaticaRepMapping` (GAP7) — tracked in `docs/PRD-customer-visibility-fairness.md`, not duplicated here.
- Automated account creation for the 434 "Missing Email" employees.
- Building a new bulk-assignment UI/mechanism — this PRD is explicitly an extension of `CustomerAssignmentService`'s existing preview→apply pipeline, not a replacement.
- Resolving "some Powerstar and Cleanshelf" by asking the business for a definitive list — §4.4 gives a data-driven resolution path first; only fall back to asking if the full-sheet scan (not yet done) can't disambiguate it.

## 7. Open questions

1. Does `StaffImportService::loadRows()`'s current header normalizer already accept "Employee Name"/"Email Address" as literal headers, or does FR-A2 need real code changes? (Needs a direct read of `loadRows()`, not assumed here.)
2. For FR-A4's rep_code/employee_number mismatch queue — reuse `StaffImportGap` with a new `gap_reason`, or a dedicated table? (Recommend reusing `StaffImportGap` for one review surface, unless its schema doesn't fit.)
3. Full-sheet verification of §4.4 (Powerstar/Cleanshelf split) and of whether Coast/Nyanza rows in the Outlets sheet already carry Beryl's/George's names or something else — needs a complete 421-row pass, not the ~15-row sample reviewed for this PRD.
4. Should the Customers-export-vs-MT-Outlets conflict (FR-B3) block the batch for human review, or auto-resolve in favor of MT Outlets with just a log entry? (Recommend log-and-proceed given MT Outlets is the more curated, recent source — but confirm.)
5. Is "Region" on the Outlets sheet reliable enough to key the region-wide rules (Beryl/George/Lilian's Mountain/Lawrence's Rift) off directly, or does it need cross-checking against `Customer Region`/`Customer Zone` in the Acumatica export for the same customer IDs?
6. Where should the frozen data file (§5.1) live, and who re-generates it if a correction is needed after the first production run — a repo path under `database/seeders/data/`, or a separate reviewed-artifacts location? Does "already applied" get checked via the `source_batch_id` marker alone, or does it need its own small `seeder_runs` ledger table for a clearer yes/no?

## 8. Rollout

| Phase | Deliverable |
|---|---|
| P0 | FR-A1/A2 (schema + header mapping), dry-run staff import, human review of Matched Staff results |
| P1 | FR-A4 rep_code/employee_number conflict detection + review queue |
| P2 | FR-B1 name resolver + full 421-row verification of §4.4/§4.2 edge cases |
| P3 | FR-B2/B3 precedence expansion → dry-run preview through existing `previewUpload()`/`applyBatch()` |
| P4 | Human sign-off on preview, commit batch in staging, freeze the reviewed output into the versioned data file (§5.1) |
| P5 | Build the idempotent seeder (§5.2–5.3), dry-run it against a production DB snapshot, then run it once against production as an explicit deploy step |

## 9. Document history

| Version | Date | Notes |
|---|---|---|
| 0.1 | 2026-07-23 | Initial PRD — grounded in direct inspection of all three workbooks plus a full audit of the existing StaffImportService/UserAcumaticaRepMapping/CustomerAssignmentService pipeline. |
| 0.2 | 2026-07-23 | Added §5 — production deployment as an idempotent, frozen-data seeder (not a live re-run of Excel parsing/name resolution in production). New FR-S1–S5, guardrails G-S1–S5, rollout phase P5, and an open question on where the frozen data file lives. |
