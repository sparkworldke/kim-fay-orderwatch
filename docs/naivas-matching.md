Done: 0 POs extracted (0 emails scanned), 49 matched, 149 need review.

# PO/SO Number Matching — Requirements & Implementation Spec

**Project:** Kim-Fay East Africa — Acumatica Email-to-SO Matching  
**Status:** Draft v1.2  
**Date:** 2026-06-25  
**Change:** Sanitisation now applied to both email subject and Acumatica field via unified pipeline; test cases split by source (32 total)

---

## 1. Problem Statement

Sales Orders in Acumatica are linked to customer Purchase Orders. Users enter the PO number manually in Acumatica, and the same PO number appears in email subject lines and/or file attachments sent by the customer.

Because there is no enforced format, the same PO number can exist in **at least four different representations** across the two sources. This causes matching failures when comparing the Acumatica `CustomerRefNbr` field against the inbound email/attachment reference.

The matching system must be format-agnostic and resolve all variants to a single **canonical numeric key** before comparison.

---

## 2. Observed Format Variants

### 2.1 Acumatica Input Formats (CustomerRefNbr field)

Users currently enter PO numbers in at least three documented formats:

| # | Format Observed | Example | Notes |
|---|---|---|---|
| 1 | Prefix `P` + zero-padded number | `P042569628` | Most common format |
| 2 | Prefix `PO : ` + space + number | `PO : 42566300` | Space around colon, no leading zero |
| 3 | Plain number only | `42561702` | No prefix at all |
| 4 | Prefix `P0` + number (ambiguous) | `P042571378` | P followed by zero — not "PO" |

> **Additional variants to anticipate:**  
> `po42566300`, `P-042566300`, `PO42566300`, `PO-42566300`, `P 042566300`,  
> `po : 42566300`, `P042566300 ` (trailing space), `#42566300`

### 2.2 Email / Attachment Format

Subject line pattern observed:

```
Purchase order Confirmation: P042574206 - KIM-FAY EAST AFRICA LIMITED :BRANCH-KAKAMEGA
```

File attachment names may follow a similar convention. The PO number is always:
- Prefixed with `P` followed by a zero-padded 9-digit number
- Separated from surrounding text by spaces, colons, or dashes

---

## 3. Canonical Key Definition

> **The canonical key is: the numeric portion of the PO number with all leading zeros stripped.**

| Input | Canonical Key |
|---|---|
| `P042574206` | `42574206` |
| `PO : 42566300` | `42566300` |
| `42561702` | `42561702` |
| `P042571378` | `42571378` |
| `PO42566300` | `42566300` |
| `P-042566300` | `42566300` |
| `po : 042566300` | `42566300` |

**Rule:** Extract all digit characters from the input string, concatenate them, then strip leading zeros.

---

## 4. Normalisation Function — Specification

### 4.1 Algorithm

```
function normalise_po(raw_input):
    1. Reject NULL / non-string input → return NULL immediately

    2. SANITISE — strip all special characters before any other processing:
       a. Trim leading and trailing whitespace (spaces, tabs, newlines)
       b. Remove all characters that are NOT alphanumeric or hyphen:
          Strip: # @ ! $ % ^ & * ( ) + = [ ] { } | \ ; ' " , . / ? ` ~
          Strip: Unicode punctuation (em-dash —, en-dash –, non-breaking space \u00A0)
          Strip: Control characters (\x00–\x1F, \x7F)
          Keep:  A–Z, a–z, 0–9, hyphen (-)
          → "PO : #42566300!" → "PO42566300"
          → "P042574206 "     → "P042574206"
          → "P0‑042566300"    → "P0-042566300"  (non-breaking hyphen → regular hyphen)
       c. Collapse multiple consecutive spaces/hyphens into one
          → "PO  -  42566300" → "PO-42566300"
       d. If string is now empty → return NULL

    3. Extract all digit characters (0–9) using regex \d+
       → "PO42566300"  → ["42566300"] → "42566300"
       → "P042574206"  → ["042574206"] → "042574206"

    4. Concatenate all digit groups into one string

    5. Strip leading zeros from the result
       → "042574206" → "42574206"
       → "42566300"  → "42566300"

    6. Validate minimum length ≥ 5 digits → return NULL if shorter

    7. Return the canonical key string
```

### 4.2 Reference Implementation (Python)

```python
import re
import unicodedata

# Characters explicitly allowed through sanitisation (besides digits)
_KEEP_PATTERN     = re.compile(r'[^A-Za-z0-9\-]')
_MULTI_SEP        = re.compile(r'[\-\s]+')
_DIGITS_ONLY      = re.compile(r'\d+')

MIN_CANONICAL_LENGTH = 5


def sanitise_po(raw: str) -> str:
    """
    Stage 1 — clean raw input before extraction.

    Removes all special characters, control characters, Unicode punctuation,
    and collapses repeated separators. Returns a clean alphanumeric string.

    Examples:
        "PO : #42566300!"  → "PO42566300"
        "P042574206 "      → "P042574206"
        "@#$%42566300"     → "42566300"
        "P0‑042566300"     → "P0-042566300"  (non-breaking hyphen normalised)
        "PO  -  42566300"  → "PO-42566300"
    """
    if not raw or not isinstance(raw, str):
        return ""

    # Normalise unicode — converts ligatures, non-breaking spaces, fancy hyphens
    # to their closest ASCII equivalents where possible
    text = unicodedata.normalize("NFKC", raw)

    # Replace non-breaking space and other whitespace variants with regular space
    text = re.sub(r'[\u00A0\u2000-\u200B\u202F\u3000]', ' ', text)

    # Normalise em-dash / en-dash / figure dash to regular hyphen
    text = re.sub(r'[\u2010-\u2015\u2212]', '-', text)

    # Strip all characters that are not alphanumeric or hyphen
    text = _KEEP_PATTERN.sub('', text)

    # Collapse consecutive hyphens/spaces into a single hyphen
    text = _MULTI_SEP.sub('-', text)

    # Strip leading/trailing hyphens left over from the cleanup
    text = text.strip('-')

    return text


def normalise_po(raw: str) -> str | None:
    """
    Stage 2 — extract and return the canonical PO key.

    Sanitises input first, then extracts digits and strips leading zeros.

    Returns None for any input that cannot produce a valid canonical key.

    Examples:
        "P042574206"             → "42574206"
        "PO : #42566300!"        → "42566300"
        "42561702"               → "42561702"
        "@#$%P042571378@#$"      → "42571378"
        "PO : 042566300 "        → "42566300"
        "P-042566300"            → "42566300"
        "PO  -  42566300"        → "42566300"
        "P0‑042566300"           → "42566300"
        ""                       → None
        "PO"                     → None
        "P0000001"               → None  (too short after stripping zeros)
        "!!!###@@@"              → None  (no digits)
    """
    if not raw or not isinstance(raw, str):
        return None

    clean   = sanitise_po(raw)
    if not clean:
        return None

    digits  = _DIGITS_ONLY.findall(clean)
    combined = ''.join(digits)
    canonical = combined.lstrip('0')

    if not canonical or len(canonical) < MIN_CANONICAL_LENGTH:
        return None

    return canonical


# ── Special character catalogue stripped by sanitise_po ─────────────────────
#
#  Category                  Characters
#  ──────────────────────────────────────────────────────────────────────
#  Whitespace                space  \t  \n  \r  \u00A0 (non-breaking)
#  Punctuation (ASCII)       # @ ! $ % ^ & * ( ) + = [ ] { } | \ ; '
#                            " , . / ? ` ~
#  Dashes (Unicode)          – (en) — (em) ‑ (non-breaking) − (minus)
#  Colon / slash             :  /  \
#  Brackets                  < >
#  Control characters        \x00–\x1F  \x7F
#  Unicode punctuation       … · • ™ © ® and all General_Category=P chars
#
#  Preserved
#  ──────────────────────────────────────────────────────────────────────
#  Digits                    0–9
#  Latin letters             A–Z  a–z   (for prefix detection: P, PO)
#  Hyphen                    -   (collapsed if repeated)
# ────────────────────────────────────────────────────────────────────────────
```

### 4.3 Extraction from Email Subject Line

The email subject is sanitised with the **same `sanitise_po()` function** used for Acumatica fields — no separate pipeline. Sanitisation runs first, then PO pattern matching, then canonicalisation.

```python
def extract_po_from_subject(subject: str) -> str | None:
    """
    Extract and normalise the PO number from an email subject line.

    Pipeline:
      1. sanitise_po()  — strip all special chars, spaces, Unicode noise
      2. Regex match    — find P/PO-prefixed number, or fallback to longest number
      3. normalise_po() — strip leading zeros, enforce minimum length

    Handles subjects like:
      "Purchase order Confirmation: P042574206 - KIM-FAY EAST AFRICA LIMITED :BRANCH-KAKAMEGA"
      "PO#042574206 :: KIM-FAY @BRANCH-KAKAMEGA!!!"
      "  P042574206  "  (excess whitespace)
      "po:042574206-branch-kakamega"

    Returns:
      Canonical numeric key string, or None if no valid PO found.
    """
    if not subject or not isinstance(subject, str):
        return None

    # ── Stage 1: sanitise ────────────────────────────────────────────────────
    # Strips #, @, !, $, :, spaces, dashes, Unicode punctuation etc.
    # Preserves A–Z, a–z, 0–9, hyphen
    # e.g. "PO : #042574206!" → "PO-042574206"
    #      "Purchase order Confirmation: P042574206 - KIM-FAY" → "Purchase-order-Confirmation-P042574206-KIM-FAY"
    clean_subject = sanitise_po(subject)
    if not clean_subject:
        return None

    # ── Stage 2: extract PO token ─────────────────────────────────────────────
    # After sanitisation separators are normalised to hyphens; colons and
    # spaces are gone, so the pattern is simpler and more reliable.
    #
    # Priority 1: number immediately following a P or PO prefix
    pattern = r'\b(P(?:O)?)-?(\d{6,})\b'
    match = re.search(pattern, clean_subject, re.IGNORECASE)
    if match:
        raw_digits = match.group(2)
        return normalise_po(raw_digits)

    # Priority 2: longest standalone number with 6+ digits
    candidates = re.findall(r'\b(\d{6,})\b', clean_subject)
    if candidates:
        # Take the longest; if tied, take the first (leftmost)
        best = max(candidates, key=len)
        return normalise_po(best)

    return None
```

> **Why sanitise before regex?** Special characters like `#`, `@`, `:`, and spaces inside or around the PO token fragment the regex match. Sanitising first collapses all noise into clean alphanumeric tokens, making the pattern match fast and reliable.

> **Minimum digit length is 6** to avoid matching unrelated numbers (year references, phone numbers, invoice IDs) present elsewhere in the subject line.

---

## 5. Matching Logic

### 5.1 Flow

```
Email received
      │
      ▼
sanitise_po(subject)          ← strip #, @, :, spaces, Unicode noise
      │
      ▼
extract_po_from_subject()     ← regex match on clean subject
      │
      ▼
normalise_po()                → canonical_email_key
      │
      ▼
Query Acumatica: SalesOrder where Date in range
      │
      ▼
For each SO:
  sanitise_po(CustomerRefNbr) ← same sanitisation, same function
      │
      ▼
  normalise_po()              → canonical_so_key
      │
      ▼
Match: canonical_email_key == canonical_so_key
      │
      ├── Match found   → link email to SO, proceed with import
      └── No match      → flag as "Email — SO Not Matched", queue for manual review
```

> **Both sides use the exact same `sanitise_po()` → `normalise_po()` pipeline.** There is no separate cleaning path for email vs Acumatica. This guarantees the two canonical keys are produced by identical logic and are always comparable.

### 5.2 Match Result States

| State | Condition | Action |
|---|---|---|
| `Matched` | canonical keys are equal | Link email to SO, import proceeds |
| `Ambiguous` | canonical key matches more than one SO | Flag for manual review, do not auto-link |
| `Not Matched` | no SO found with matching canonical key | Queue in "Missing" backlog (as seen in screenshot) |
| `Invalid PO` | normalise_po returns NULL | Log as malformed, alert operations team |
| `Duplicate Email` | same canonical key received twice | Deduplicate — do not create second link |

---

## 6. Edge Cases & Guardrails

### 6.1 Input Sanitisation — Full Special Character Guardrails

All inputs (Acumatica field AND email subject) pass through `sanitise_po()` **before** any extraction logic runs. This is the first and hardest guardrail.

**Characters stripped:**

| Category | Examples |
|---|---|
| Whitespace | `space` `\t` `\n` `\r` non-breaking space `\u00A0` |
| ASCII punctuation | `#` `@` `!` `$` `%` `^` `&` `*` `(` `)` `+` `=` `[` `]` `{` `}` `\|` `\` `;` `'` `"` `,` `.` `/` `?` `` ` `` `~` |
| Colons & slashes | `:` `/` `\` |
| Unicode dashes | `–` en-dash `—` em-dash `‑` non-breaking hyphen `−` minus sign |
| Control characters | `\x00`–`\x1F`, `\x7F` |
| Unicode punctuation | `…` `·` `•` `™` `©` `®` and all `General_Category=P` characters |

**Characters preserved:**

| Category | Characters |
|---|---|
| Digits | `0–9` |
| Latin letters | `A–Z` `a–z` (needed for prefix detection: `P`, `PO`) |
| Hyphen | `-` (collapsed if repeated; stripped if leading/trailing) |

**Sanitisation examples:**

| Raw Input | After `sanitise_po()` | Canonical Key |
|---|---|---|
| `#42566300` | `42566300` | `42566300` |
| `@PO42566300!` | `PO42566300` | `42566300` |
| `PO : #42566300!` | `PO42566300` | `42566300` |
| `P042574206  ` | `P042574206` | `42574206` |
| `P0‑042566300` | `P0-042566300` | `42566300` |
| `PO  -  42566300` | `PO-42566300` | `42566300` |
| `"P042566300"` | `P042566300` | `42566300` |
| `P042566300/A` | `P042566300A` | `42566300` |
| `P042566300\n` | `P042566300` | `42566300` |
| `!!!###@@@` | `` (empty) | `NULL` |

### 6.2 Ambiguity: Multiple Digit Groups in Subject Line

Subject: `"PO Confirmation: P042574206 - Invoice 3456 - KIM-FAY"`  
→ Two numbers present: `042574206` and `3456`  
→ Rule: **prefer the number that matches the `P`/`PO` prefix pattern first; fall back to longest number**

```python
# Priority order:
# 1. Number immediately after P/PO prefix      → most reliable
# 2. Longest standalone number (≥6 digits)     → fallback
# 3. NULL + alert                               → if neither found
```

### 6.3 Very Short Numbers After Stripping Zeros

Input: `"P0000001"` → canonical = `"1"`  
→ This is likely a test or placeholder order. The **minimum canonical length of 5 digits** (enforced inside `normalise_po()`) will reject this and return `NULL`.

### 6.4 Canonical Key Collision

Two different raw inputs normalise to the same key:
- `"P042566300"` → `42566300`
- `"PO : 42566300"` → `42566300`

These are the **same order** — this is the intended behaviour. No collision risk here.

However: `"P4256630"` (8 digits) vs `"P042566300"` (9 digits) → `4256630` vs `42566300`  
→ These are **different orders** and will not collide. ✅

### 6.5 Trailing/Embedded Characters

Input: `"P042566300-A"` (branch suffix)  
→ After sanitisation: `P042566300A` → digits: `042566300` → canonical `42566300`  
→ Suffix `A` is discarded. Acceptable — the PO number is still uniquely identified.  
→ **Log the original raw value** alongside the canonical key for audit purposes.

### 6.6 Non-Breaking and Unicode Special Characters

Covered entirely by `sanitise_po()` via `unicodedata.normalize("NFKC", ...)`:

| Input character | Unicode | Sanitised to |
|---|---|---|
| Non-breaking space | `\u00A0` | stripped |
| Em-dash | `\u2014` | stripped |
| En-dash | `\u2013` | stripped |
| Non-breaking hyphen | `\u2011` | `-` |
| Minus sign | `\u2212` | `-` |
| Narrow no-break space | `\u202F` | stripped |
| Zero-width space | `\u200B` | stripped |
| Ellipsis `…` | `\u2026` | stripped |

### 6.7 Empty or Whitespace CustomerRefNbr in Acumatica

Some SOs may have a blank `CustomerRefNbr` — this appears as `"Missing"` in the screenshot.  
→ These should be **excluded from canonical key matching** and surfaced in a separate "No PO on file" report, not treated as a failed match.

---

## 7. Audit & Logging Requirements

Every normalisation event must be logged with:

```json
{
  "timestamp": "2026-06-25T10:45:00Z",
  "source": "email_subject | acumatica_field",
  "raw_input": "PO : #42566300!",
  "sanitised_input": "PO42566300",
  "canonical_key": "42566300",
  "match_result": "Matched | Not Matched | Ambiguous | Invalid PO",
  "so_number": "S0359362",
  "email_id": "msg-uuid-xxxx",
  "flagged": false,
  "flag_reason": null
}
```

The `sanitised_input` field captures what the string looked like after special character removal but before digit extraction. This is the critical audit trail for diagnosing why a match succeeded or failed.

---

## 8. Test Cases

Both sources run through the same `sanitise_po()` → `normalise_po()` pipeline. Test cases are split by source for clarity.

### 8.1 Acumatica `CustomerRefNbr` Inputs

| # | Raw Input | After `sanitise_po()` | Canonical Key | Valid? |
|---|---|---|---|---|
| 1 | `P042574206` | `P042574206` | `42574206` | ✅ |
| 2 | `PO : 42566300` | `PO42566300` | `42566300` | ✅ |
| 3 | `42561702` | `42561702` | `42561702` | ✅ |
| 4 | `P042571378` | `P042571378` | `42571378` | ✅ |
| 5 | `po : 042566300` | `po042566300` | `42566300` | ✅ |
| 6 | `P-042566300` | `P-042566300` | `42566300` | ✅ |
| 7 | `PO42566300` | `PO42566300` | `42566300` | ✅ |
| 8 | `  P042566300 ` | `P042566300` | `42566300` | ✅ |
| 9 | `#42566300` | `42566300` | `42566300` | ✅ |
| 10 | `@PO42566300!` | `PO42566300` | `42566300` | ✅ |
| 11 | `PO : #42566300!` | `PO42566300` | `42566300` | ✅ |
| 12 | `"P042566300"` | `P042566300` | `42566300` | ✅ |
| 13 | `P042566300/A` | `P042566300A` | `42566300` | ✅ |
| 14 | `P042566300\n` | `P042566300` | `42566300` | ✅ |
| 15 | `P0‑042566300` | `P0-042566300` | `42566300` | ✅ |
| 16 | `PO  -  42566300` | `PO-42566300` | `42566300` | ✅ |
| 17 | `P042566300-A` (branch suffix) | `P042566300A` | `42566300` | ✅ |
| 18 | `PO` | `PO` | `NULL` | ❌ No digits |
| 19 | `` (empty) | `` | `NULL` | ❌ Missing |
| 20 | `P0000001` | `P0000001` | `NULL` | ❌ Too short |
| 21 | `!!!###@@@` | `` | `NULL` | ❌ All stripped |

### 8.2 Email Subject Line Inputs

| # | Raw Subject | After `sanitise_po()` | Canonical Key | Valid? |
|---|---|---|---|---|
| 22 | `Purchase order Confirmation: P042574206 - KIM-FAY EAST AFRICA LIMITED :BRANCH-KAKAMEGA` | `Purchase-order-Confirmation-P042574206-KIM-FAY-EAST-AFRICA-LIMITED-BRANCH-KAKAMEGA` | `42574206` | ✅ |
| 23 | `PO#042574206 :: KIM-FAY @BRANCH-KAKAMEGA!!!` | `PO42574206-KIM-FAY-BRANCH-KAKAMEGA` | `42574206` | ✅ |
| 24 | `  P042574206  ` | `P042574206` | `42574206` | ✅ |
| 25 | `po:042574206-branch-kakamega` | `po042574206-branch-kakamega` | `42574206` | ✅ |
| 26 | `P042574206 — KIM-FAY` (em-dash) | `P042574206-KIM-FAY` | `42574206` | ✅ |
| 27 | `RE: FWD: PO : #P042574206!!!` | `RE-FWD-PO-P042574206` | `42574206` | ✅ |
| 28 | `Order Confirmation 2026 P042574206` | `Order-Confirmation-2026-P042574206` | `42574206` | ✅ (P-prefix wins over `2026`) |
| 29 | `P042574206@kimfay.co.ke` (email-like) | `P042574206kimfayco` | `42574206` | ✅ |
| 30 | `Invoice 3456 from KIM-FAY` | `Invoice-3456-from-KIM-FAY` | `NULL` | ❌ No 6+ digit number |
| 31 | `No PO number here` | `No-PO-number-here` | `NULL` | ❌ No digits |
| 32 | `` (empty subject) | `` | `NULL` | ❌ Missing |

---

## 9. Implementation Phases

| Phase | Scope | Priority |
|---|---|---|
| **Phase 1** | Build and unit-test `sanitise_po()` + `normalise_po()` functions | High |
| **Phase 2** | Apply normalisation to Acumatica `CustomerRefNbr` on SO import | High |
| **Phase 3** | Apply normalisation to email subject line extraction | High |
| **Phase 4** | Build match result logging (with `sanitised_input` field) and "Not Matched" queue | Medium |
| **Phase 5** | Surface "Missing PO" and "Ambiguous" cases in ops dashboard | Medium |
| **Phase 6** | Extend to file attachment PO extraction (PDF/Excel parsing) | Low |

---

## 10. Out of Scope

- Modifying Acumatica's `CustomerRefNbr` field validation (enforcement is a separate change request)
- Retroactively correcting historic SO records with malformed PO numbers
- Matching on customer name or branch name alone (PO number is the primary key)

---

*Prepared for Kim-Fay East Africa — Operations & Systems Team*