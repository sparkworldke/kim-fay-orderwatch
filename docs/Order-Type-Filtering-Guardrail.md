# Order Type Filtering — Guardrail Spec
### Order Match: Sales Order Scope Restriction

**Version:** 1.0.0 | **Status:** Draft | **Date:** 2026-06-23 | **Parent doc:** PRD — Order Match

---

## 1. Purpose

Acumatica order documents in this environment use three prefixes:

| Prefix | Document Type | In Scope (v1.0)? |
|---|---|---|
| `SO` | Sales Order | ✅ Yes |
| `RC` | Credit Note | ❌ No — future phase |
| `QO` | Quote | ❌ No — future phase |

Order Match v1.0 must operate **exclusively on Sales Orders**. Credit Notes and Quotes will be addressed in a later phase once a dedicated design exists for how they should be matched, displayed, and actioned. Until then, they must be invisible to the feature end-to-end — not filtered out cosmetically after being fetched, but excluded at the source.

---

## 2. Scope

This restriction applies everywhere Order Match touches Acumatica order data:

- Candidate fetch for AI matching (§7a of the PRD)
- Exact `CustomerPONbr` lookups
- Fuzzy/recent-open-orders fallback queries
- Duplicate detection cross-checks against `match_log`
- Any future reporting, export, or audit view built on top of matched orders

It does **not** apply to PO *extraction* from emails — a customer's email may legitimately reference any document type, and extraction should stay type-agnostic. The restriction is enforced only at the point where Order Match queries or returns Acumatica order documents.

---

## 3. Guardrails

| # | Rule |
|---|---|
| 1 | Order Match v1.0 handles **Sales Orders only** (Acumatica `OrderType = SO`, document prefix `SO`). Credit Notes (`RC`) and Quotes (`QO`) are out of scope. |
| 2 | All Acumatica queries for candidate documents must filter `OrderType = 'SO'` (or equivalent prefix filter on `OrderNbr LIKE 'SO%'`) **at the query level** — never filter client-side after a broader fetch. |
| 3 | If a `canonicalPO` matches an existing `RC` or `QO` document but no `SO`, the result is treated as `no_match`. Do not surface `RC`/`QO` documents in the queue, even as a fuzzy or low-confidence candidate. |
| 4 | PO extraction (subject/body/OCR) may still detect any PO-like string; the type restriction applies at the **Acumatica lookup** stage, not extraction. Do not suppress extraction based on prefix. |
| 5 | Log any case where the only candidate found is an `RC` or `QO` document as `extraction_status = "not_found"` with an internal note (`excluded_order_type: RC|QO`) for future-phase visibility — but never expose `RC`/`QO` to the admin queue. |
| 6 | Future support for Credit Notes and Quotes is a **separate phase** and requires its own design pass — do not silently extend matching to these types without a spec update. |

---

## 4. Enforcement Points

| Layer | Where the filter must be applied |
|---|---|
| Exact match query | `WHERE OrderType = 'SO' AND CustomerPONbr = :po` |
| Fuzzy candidate fetch | `WHERE OrderType = 'SO' AND CustomerID = :id AND Status IN (...)` — apply before the 20-record cap |
| Duplicate cross-check vs `match_log` | `match_log` rows are already SO-only by construction (Rule 1), so no extra filter needed here — but any new query path added later must confirm this invariant |
| AI prompt context | Candidate list passed to the AI must already be SO-only by the time it reaches the prompt; never rely on the AI to self-filter by prefix |

---

## 5. Why query-level, not client-side

Filtering after the fetch (e.g. pulling all order types then discarding `RC`/`QO` in code) has two failure modes this spec is meant to prevent:

- **Silent leakage** — a missed filter step anywhere downstream re-introduces RC/QO into the queue.
- **Wasted candidate budget** — the AI match engine caps input at 20 SOs per prompt (PRD §15, AI Match Rule 2); if RC/QO records count against that cap before being discarded, true SO candidates can get crowded out.

Filtering at the query keeps the exclusion structural rather than a step someone can forget to repeat in a new code path.

---

## 6. Open Question

| # | Question | Owner | Target |
|---|---|---|---|
| 1 | Credit Note (RC) and Quote (QO) matching — separate phase scope, timeline, and design owner? | Product | TBD |

---

*Order Type Filtering Guardrail Spec v1.0.0 — Order Match | Acumatica Integration Platform | June 2026*
