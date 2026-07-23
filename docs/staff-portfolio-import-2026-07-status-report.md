# Staff Identity & Customer Portfolio Import — Status Report

**Date:** 2026-07-23
**Source files:** `Staff_Email_Match_July_2026.xlsx`, `MT Outlets by Rep.xlsx`, `Customers 20260713.xlsx`
**Related docs:** [PRD-staff-identity-and-customer-portfolio-import.md](./PRD-staff-identity-and-customer-portfolio-import.md), [staff-portfolio-import-2026-07-runbook.md](./staff-portfolio-import-2026-07-runbook.md)

---

## What this covers

1. **Staff identity reconciliation** — matching active users to their employee number, department, designation, and division from the HR staff sheet, and safely reconciling rep codes without ever overwriting an existing one.
2. **Customer portfolio import** — assigning every MT outlet and Acumatica customer to the correct sales rep, honoring the named business-rule exceptions (Beryl Muga, George Amenya, Georgina Kiilu, Lucy Wanjiru, Jane Kuria, Kevin Werunga, Zipporah Wangeci, Lilian Kimeu, Lawrence Amukhono, Dennis Mutwiri).

## Current status: code complete, dry-run verified, **not yet applied to any database**

Nothing in this batch has been written to a live database. Everything below was run in **preview/dry-run mode only**, against a local test environment, to confirm the logic is correct before anyone reviews real production data.

## Bugs found and fixed

Running the pipeline against the actual three files (rather than assuming the code was correct) surfaced six real bugs. All are fixed and covered by an automated test that re-runs the real precedence logic against the real files.

| # | Bug | Risk if left unfixed |
|---|---|---|
| 1 | Two sheets have title/subtitle rows before the real header row | Nearly every staff row would have been treated as unmatched |
| 2 | The "Outlets" sheet tab name has a trailing space | Portfolio import would crash outright |
| 3 | **Naivas/Magunas "Thika" exception was checking the wrong column** | The two real Naivas-Thika outlets would have been assigned to Lucy Wanjiru instead of Lilian Kimeu — a genuine misassignment |
| 4 | Apostrophe in "Maguna's" broke name matching | Magunas-specific rules silently never fired (correct result by coincidence, wrong audit trail) |
| 5 | Three account-name typos in the rule list | Same as above — rules never fired, correct result by coincidence |
| 6 | **Name-matching threshold was mathematically unreachable** for short-name-vs-full-legal-name comparisons | All 9 resolvable reps would have failed to match, dumping the entire import into manual review |

**Bug #3 is the one that mattered most** — it's the exact "no bleeding" scenario this whole exercise was meant to prevent, and it's now fixed and verified.

## Verified with a real test run

A test seeded 9 real staff accounts (using their actual emails from the HR sheet) and ran the actual precedence engine against the actual three files. Result: **401 assertions pass**, including:

- Both documented carve-outs resolve to the correct person.
- A control case (a non-Thika Naivas branch) still correctly resolves to Lucy Wanjiru — confirming the exception doesn't swallow the whole rule.
- Zero customers resolve to more than one owner anywhere in the batch.

## One real data gap (not a bug)

**Lawrence Amukhono** (Khetias + all Rift outlets) has no active email account in the HR data — he appears in the "no active email" list, not the matched-staff list. His portion of the portfolio will correctly be held for manual review rather than guessed at. He'll need an account set up before his outlets can be assigned.

## What happens next

1. Someone reviews the real dry-run output (customer-by-customer, rep-by-rep) against production data.
2. Once approved, the reviewed result is frozen into a versioned data file and reviewed like any other code change.
3. A one-time, explicit seeder applies that frozen result to production — it is not part of the normal deploy process and only runs when someone deliberately triggers it.
4. Lawrence's account gets created/activated separately, and his portfolio is added in a follow-up pass.

No customer or staff record changes until step 3 happens, and step 3 requires a human to have reviewed step 1 first.
