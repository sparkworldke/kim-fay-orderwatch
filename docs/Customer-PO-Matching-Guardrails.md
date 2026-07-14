# Customer PO Matching Guardrails — Naivas & Carrefour (C4)

**Version:** 1.0.0  
**Date:** 2026-06-24

## Problem

Kim-Fay receives POs from key accounts in formats that do **not** match Acumatica `CustomerOrder` verbatim. Auto-matching must translate email PO tokens into the Acumatica lookup key and apply customer-specific guardrails so we never link the wrong SO.

---

## 1. Naivas

### What we see in email

| Source | Example |
|--------|---------|
| Subject | `Purchase order Confirmation: P042562296 - KIM-FAY…` |
| Attachment filename | `P042562296.pdf` (starts with `P0` + digits) |

Sender: `notification@naivas.net`  
Trusted folder: **Naivas POs**

### What Acumatica stores

| Field | Value |
|-------|-------|
| `CustomerOrder` | `42562296` (numeric — **no** `P0` prefix) |

### Translation rule

```
P042562296  →  remove "P0" prefix  →  42562296  →  compare to CustomerOrder
```

Only the **numeric CustomerOrder ID** is used for matching — not the prefixed email token.

`canonical_po` in CRM stores `42562296`.

### Guardrails

- Sender is Naivas **or** folder customer name contains "Naivas"
- Extracted PO must match `P0` + 7–10 digits
- Evidence must come from **subject** or **attachment filename** (not body-only / AI-only)
- Match only `OrderType = SO` sales orders (existing SO guardrail)
- Multiple PO candidates → `needs_review` (unchanged)

---

## 2. Carrefour (C4)

### What we see in email

| Source | Example |
|--------|---------|
| Subject | `C4 GCM XGCM     26021220` |

Sender: `KENCarrefourOrders@maf.ae`  
Trusted folder: **Carrefour POs**

### What Acumatica stores

| Field | Value |
|-------|-------|
| `CustomerOrder` | `26021220` (8-digit numeric ID from subject) |

### Translation rule

Parse **digits from the subject line** and compare directly to Acumatica `CustomerOrder`:

```
"C4 GCM XGCM     26021220"  →  26021220  →  compare to CustomerOrder
```

Subject pattern: `C4 {SITE} {CHANNEL} {digits}`  
Regex: `/\bC4\b\s+\S+\s+\S+\s+(\d{7,9})\b/i`

### Guardrails

- Sender is Carrefour **or** folder customer name contains "Carrefour"
- PO must be 7–9 digits
- Subject must contain parseable C4 digits; extracted PO must match those subject digits
- Match only `OrderType = SO`
- Multiple candidates → `needs_review`

---

## Implementation

| File | Role |
|------|------|
| `CustomerPoMatchResolver.php` | Lookup-key translation + evidence validation |
| `PoNumberExtractorService.php` | Naivas attachment filename pattern `^P0\d{7,10}` |
| `OrderMatchingService.php` | Multi-key `customer_order` lookup + guardrail review |
| `OrderMatchAiMatchingService.php` | Exact match uses same lookup keys |
| `OrderMatchPipelineService.php` | `canonical_po` stores Acumatica-facing key |

---

## Examples

| Customer | Email PO | Acumatica CustomerOrder | Match? |
|----------|----------|-------------------------|--------|
| Naivas | `P042562296` | `42562296` | ✅ Yes |
| Carrefour | `26021220` (from `C4 GCM XGCM     26021220`) | `26021220` | ✅ Yes |
| Carrefour | `26021220` (body only, no C4 subject) | `26021220` | ❌ Review — `carrefour_subject_digits_missing` |
| Naivas | `P042562296` (AI guess, no subject/file) | `42562296` | ❌ Review — `naivas_requires_subject_or_attachment` |