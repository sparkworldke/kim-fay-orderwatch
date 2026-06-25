# Carrefour PO Number Matching — Requirements & Implementation Spec

**Project:** Kim-Fay East Africa — Acumatica Email-to-SO Matching (Carrefour)  
**Status:** Draft v1.0  
**Date:** 2026-06-25  
**Scope:** Carrefour-specific PO format handling only

---

## 1. Problem Statement

Carrefour PO numbers arrive in email subject lines and are also entered manually into the Acumatica `CustomerRefNbr` field. The format is structurally different from other customers:

```
C4 KEV 2600050
C4 KEI MALL 26016501
```

The `4` in the brand code `C4` is **not part of the PO number**. Naive full-string digit extraction merges the `4` with the actual PO digits and produces a wrong canonical key:

| Raw Input | Naive extraction | Correct canonical key |
|---|---|---|
| `C4 KEV 2600050` | `42600050` ❌ | `2600050` ✅ |
| `C4 KEI MALL 26016501` | `426016501` ❌ | `26016501` ✅ |

A customer-specific extractor is required that:
1. Detects the `C4` brand prefix
2. Skips all following alphabetic branch tokens (`KEV`, `KEI`, `MALL`, etc.)
3. Extracts only the **final standalone numeric segment** as the PO number

---

## 2. Observed Carrefour PO Formats

### 2.1 Email Subject Line Formats

| # | Format | Example | Canonical Key |
|---|---|---|---|
| 1 | `C4 {BRANCH} {PO}` | `C4 KEV 2600050` | `2600050` |
| 2 | `C4 {BRANCH} {BRANCH} {PO}` | `C4 KEI MALL 26016501` | `26016501` |
| 3 | `C4 {PO}` (no branch) | `C4 2600050` | `2600050` |

### 2.2 Acumatica `CustomerRefNbr` Input Formats

Users may enter the same reference in varied formats:

| # | Format | Example | Canonical Key |
|---|---|---|---|
| 1 | Space-separated | `C4 KEV 2600050` | `2600050` |
| 2 | Hyphenated | `C4-KEV-2600050` | `2600050` |
| 3 | Mixed separators | `C4 KEV-2600050` | `2600050` |
| 4 | Multi-word branch, spaces | `C4 KEI MALL 26016501` | `26016501` |
| 5 | Multi-word branch, hyphens | `C4-KEI-MALL-26016501` | `26016501` |
| 6 | Extra whitespace | `C4  KEV  2600050` | `2600050` |
| 7 | Special chars injected | `C4 #KEV@ 2600050!` | `2600050` |
| 8 | Lowercase | `c4 kev 2600050` | `2600050` |
| 9 | Mixed case | `C4 Kev 2600050` | `2600050` |
| 10 | No branch token | `C4 2600050` | `2600050` |

> **All of the above must resolve to the same canonical key as the email subject line for the same order.**

---

## 3. Canonical Key Definition

> **The canonical key is: the trailing numeric segment of the Carrefour reference, with leading zeros stripped.**

```
C4  [BRANCH TOKENS...]  {PO_NUMBER}
 ↑         ↑                 ↑
brand    skipped          canonical key
prefix   entirely
```

**Examples:**

| Raw | Tokens after C4 | PO token | Canonical Key |
|---|---|---|---|
| `C4 KEV 2600050` | `KEV`, `2600050` | `2600050` | `2600050` |
| `C4 KEI MALL 26016501` | `KEI`, `MALL`, `26016501` | `26016501` | `26016501` |
| `C4-KEV-2600050` | `KEV`, `2600050` | `2600050` | `2600050` |
| `C4 2600050` | `2600050` | `2600050` | `2600050` |
| `C4 KEV 02600050` | `KEV`, `02600050` | `02600050` | `2600050` *(leading zero stripped)* |

---

## 4. Implementation

### 4.1 Stage 1 — Sanitise Input

Before any extraction, run `sanitise_po()` on **both** the Acumatica field and the email subject. This strips `#`, `@`, `!`, `:`, spaces, Unicode dashes, control characters, and collapses repeated separators.

```python
import re
import unicodedata

_KEEP_PATTERN = re.compile(r'[^A-Za-z0-9\-]')
_MULTI_SEP    = re.compile(r'[\-\s]+')

def sanitise_po(raw: str) -> str:
    """
    Clean raw input — strip all special characters and normalise separators.

    Strips:  # @ ! $ % ^ & * ( ) + = [ ] { } | \\ ; ' " , . / ? ` ~
             Unicode dashes (em, en, non-breaking), control chars,
             non-breaking spaces, zero-width spaces
    Keeps:   A–Z  a–z  0–9  hyphen (-)

    Examples:
        "C4 #KEV@ 2600050!"  → "C4-KEV-2600050"
        "C4  KEV  2600050"   → "C4-KEV-2600050"
        "C4-KEI-MALL-26016501" → "C4-KEI-MALL-26016501"
        "c4 kev 2600050"     → "c4-kev-2600050"
    """
    if not raw or not isinstance(raw, str):
        return ""

    text = unicodedata.normalize("NFKC", raw)
    text = re.sub(r'[\u00A0\u2000-\u200B\u202F\u3000]', ' ', text)
    text = re.sub(r'[\u2010-\u2015\u2212]', '-', text)
    text = _KEEP_PATTERN.sub('', text)
    text = _MULTI_SEP.sub('-', text)
    text = text.strip('-')
    return text
```

### 4.2 Stage 2 — Carrefour Extraction

```python
MIN_CANONICAL_LENGTH = 5

# Known Carrefour branch tokens — extend as new branches are onboarded
CARREFOUR_BRANCH_TOKENS = {
    "KEV", "KEI", "MALL", "THIKA", "KAREN", "WESTGATE",
    "JUNCTION", "HIGHWAY", "SARIT", "GARDEN", "CITY",
    "PRESTIGE", "NEXTGEN", "MOMBASA", "KISUMU",
    # Add new branch codes here; case-insensitive matching applies
}


def extract_carrefour_po(raw: str) -> str | None:
    """
    Extract the canonical PO number from a Carrefour-format reference.

    Algorithm:
        1. Sanitise — strip special characters, collapse separators
        2. Confirm the C4 prefix is present; return None if not
        3. Tokenise on hyphens (after sanitisation all separators are hyphens)
        4. Skip the C4 token and all known branch-name tokens (case-insensitive)
        5. The first all-digit token encountered is the PO number
        6. Strip leading zeros; enforce minimum length of 5 digits
        7. Return canonical key, or None if no valid numeric token found

    Examples:
        "C4 KEV 2600050"         → "2600050"
        "C4 KEI MALL 26016501"   → "26016501"
        "C4-KEV-2600050"         → "2600050"
        "C4 2600050"             → "2600050"
        "C4 #KEV@ 2600050!"      → "2600050"
        "c4 kev 2600050"         → "2600050"
        "C4 KEV 02600050"        → "2600050"  (leading zero stripped)
        "C4 KEV"                 → None       (no PO number present)
        "C4 KEV ABC"             → None       (no numeric token)
        ""                       → None
    """
    if not raw or not isinstance(raw, str):
        return None

    # Stage 1: sanitise
    clean = sanitise_po(raw)
    if not clean:
        return None

    # Stage 2: tokenise (all separators are now hyphens after sanitisation)
    tokens = clean.upper().split('-')

    # Must begin with C4
    if not tokens or tokens[0] != 'C4':
        return None

    # Stage 3: walk tokens after C4
    for token in tokens[1:]:
        if not token:
            continue                          # skip empty tokens from double separators

        if token in CARREFOUR_BRANCH_TOKENS:
            continue                          # skip known branch abbreviations

        if re.fullmatch(r'\d+', token):
            # Found the PO numeric token
            canonical = token.lstrip('0')
            if not canonical:
                return None                   # was all zeros
            if len(canonical) < MIN_CANONICAL_LENGTH:
                return None                   # too short — likely not a real PO
            return canonical

        # Unknown alphabetic token that is not in branch list
        # Could be a new branch code not yet registered
        # Log it and keep scanning — do not fail hard
        _log_unknown_branch_token(token, raw)
        continue

    return None   # No valid numeric token found after C4 and branch codes


def _log_unknown_branch_token(token: str, raw_input: str) -> None:
    """
    Log an unrecognised token encountered during Carrefour PO extraction.
    This helps grow the CARREFOUR_BRANCH_TOKENS set over time.
    """
    # Replace with your actual logging framework
    print(f"[WARN] Unknown Carrefour branch token '{token}' in input: '{raw_input}'. "
          f"Add to CARREFOUR_BRANCH_TOKENS if this is a valid branch code.")
```

### 4.3 Stage 3 — Normalise (shared with general pipeline)

```python
def normalise_po(raw: str) -> str | None:
    """
    General-purpose normalisation — sanitise, extract all digits, strip leading zeros.
    Used as a fallback when customer-specific extraction is not applicable.
    """
    if not raw or not isinstance(raw, str):
        return None
    clean = sanitise_po(raw)
    if not clean:
        return None
    digits = re.findall(r'\d+', clean)
    combined = ''.join(digits)
    canonical = combined.lstrip('0')
    if not canonical or len(canonical) < MIN_CANONICAL_LENGTH:
        return None
    return canonical
```

---

## 5. Guardrails

### 5.1 C4 Prefix Validation

```python
# If the Acumatica field or subject does not start with C4 (after sanitisation),
# do not route to the Carrefour extractor.
# Prevents misrouting of generic PO numbers that happen to contain "C4".

def is_carrefour_format(raw: str) -> bool:
    clean = sanitise_po(raw)
    return clean.upper().startswith('C4-') or clean.upper() == 'C4'
```

### 5.2 Missing Branch Token

If a `C4`-prefixed input has no recognised branch token and no numeric segment, flag it:

```
C4 KEV       → None  — missing PO number, flag as "Incomplete Carrefour Ref"
C4           → None  — brand code only, flag as "Incomplete Carrefour Ref"
C4 ABC 1234  → None  — numeric segment too short (< 5 digits), flag as "Short PO"
```

### 5.3 Unknown Branch Token Alert

When `_log_unknown_branch_token()` fires, the operations team should review and add the new branch to `CARREFOUR_BRANCH_TOKENS`. Until added, the extractor skips the unknown token and continues scanning — it will still find the numeric PO if it appears later in the string.

### 5.4 Leading Zero Handling

Carrefour PO numbers may be entered with or without leading zeros in Acumatica:

| Acumatica entry | Email subject | After strip | Match? |
|---|---|---|---|
| `C4 KEV 02600050` | `C4 KEV 2600050` | Both → `2600050` | ✅ |
| `C4 KEV 2600050` | `C4 KEV 2600050` | Both → `2600050` | ✅ |

Leading zeros are always stripped — this is handled by `lstrip('0')` in the extractor.

### 5.5 Minimum PO Length

Canonical keys shorter than 5 digits after leading-zero stripping are rejected as likely test data or malformed entries. This prevents `C4 KEV 1` from matching anything.

### 5.6 Case Insensitivity

All comparisons use `.upper()`. `c4 kev 2600050`, `C4 KEV 2600050`, and `C4 Kev 2600050` all produce the same canonical key.

### 5.7 Fallback on Extractor Failure

If `extract_carrefour_po()` returns `None` for a `C4`-prefixed input, the system falls back to `normalise_po()` (general extraction) and flags the result as `"Carrefour fallback — verify manually"` rather than silently dropping the match.

```python
def extract_po_for_carrefour(raw: str) -> tuple[str | None, str]:
    """
    Returns (canonical_key, extraction_method).
    extraction_method is one of: "carrefour", "fallback_general", "failed"
    """
    result = extract_carrefour_po(raw)
    if result:
        return result, "carrefour"

    # Fallback: general digit extraction
    result = normalise_po(raw)
    if result:
        return result, "fallback_general"

    return None, "failed"
```

---

## 6. Matching Flow — Carrefour

```
Email received (CustomerID = CARREFOUR*)
        │
        ▼
sanitise_po(subject)
        │
        ▼
extract_carrefour_po()
        │
        ├── Success  → canonical_email_key
        └── None     → normalise_po() fallback → canonical_email_key (flagged)
        │
        ▼
Query Acumatica: SalesOrder where CustomerID = CARREFOUR* and Date in range
        │
        ▼
For each SO: sanitise_po(CustomerRefNbr)
        │
        ▼
extract_carrefour_po(CustomerRefNbr)
        │
        ├── Success  → canonical_so_key
        └── None     → normalise_po() fallback → canonical_so_key (flagged)
        │
        ▼
Match: canonical_email_key == canonical_so_key
        │
        ├── Match found   → link email to SO
        └── No match      → "Carrefour SO Not Matched" queue
```

---

## 7. Audit Log Fields — Carrefour

```json
{
  "timestamp": "2026-06-25T10:45:00Z",
  "customer_id": "CARREFOUR001",
  "source": "email_subject | acumatica_field",
  "raw_input": "C4 KEI MALL 26016501",
  "sanitised_input": "C4-KEI-MALL-26016501",
  "canonical_key": "26016501",
  "extraction_method": "carrefour | fallback_general | failed",
  "unknown_tokens_found": [],
  "match_result": "Matched | Not Matched | Ambiguous | Invalid PO",
  "so_number": "S0359362",
  "email_id": "msg-uuid-xxxx",
  "flagged": false,
  "flag_reason": null
}
```

---

## 8. Test Cases

### 8.1 Acumatica `CustomerRefNbr` Inputs — Carrefour

| # | Raw Input | After `sanitise_po()` | Canonical Key | Valid? |
|---|---|---|---|---|
| 1 | `C4 KEV 2600050` | `C4-KEV-2600050` | `2600050` | ✅ |
| 2 | `C4 KEI MALL 26016501` | `C4-KEI-MALL-26016501` | `26016501` | ✅ |
| 3 | `C4-KEV-2600050` | `C4-KEV-2600050` | `2600050` | ✅ |
| 4 | `C4-KEI-MALL-26016501` | `C4-KEI-MALL-26016501` | `26016501` | ✅ |
| 5 | `C4 KEV-2600050` | `C4-KEV-2600050` | `2600050` | ✅ |
| 6 | `C4  KEV  2600050` | `C4-KEV-2600050` | `2600050` | ✅ |
| 7 | `C4 #KEV@ 2600050!` | `C4-KEV-2600050` | `2600050` | ✅ |
| 8 | `c4 kev 2600050` | `c4-kev-2600050` | `2600050` | ✅ |
| 9 | `C4 Kev 2600050` | `C4-Kev-2600050` | `2600050` | ✅ |
| 10 | `C4 2600050` | `C4-2600050` | `2600050` | ✅ |
| 11 | `C4 KEV 02600050` | `C4-KEV-02600050` | `2600050` | ✅ (leading zero stripped) |
| 12 | `C4 THIKA 2600050` | `C4-THIKA-2600050` | `2600050` | ✅ |
| 13 | `C4 KAREN 26016501` | `C4-KAREN-26016501` | `26016501` | ✅ |
| 14 | `C4 KEV` | `C4-KEV` | `NULL` | ❌ No PO number |
| 15 | `C4` | `C4` | `NULL` | ❌ Brand code only |
| 16 | `C4 KEV 1234` | `C4-KEV-1234` | `NULL` | ❌ Too short (< 5 digits) |
| 17 | `C4 KEV ABC` | `C4-KEV-ABC` | `NULL` | ❌ No numeric token |
| 18 | `` (empty) | `` | `NULL` | ❌ Missing |
| 19 | `C4 NEWBRANCH 2600050` | `C4-NEWBRANCH-2600050` | `2600050` | ✅ (unknown token logged, scan continues) |

### 8.2 Email Subject Line Inputs — Carrefour

| # | Raw Subject | After `sanitise_po()` | Canonical Key | Valid? |
|---|---|---|---|---|
| 20 | `C4 KEV 2600050` | `C4-KEV-2600050` | `2600050` | ✅ |
| 21 | `C4 KEI MALL 26016501` | `C4-KEI-MALL-26016501` | `26016501` | ✅ |
| 22 | `  C4 KEV 2600050  ` | `C4-KEV-2600050` | `2600050` | ✅ |
| 23 | `C4 KEV 2600050 - KIM-FAY EAST AFRICA` | `C4-KEV-2600050-KIM-FAY-EAST-AFRICA` | `2600050` | ✅ (numeric PO found before text tokens) |
| 24 | `RE: C4 KEI MALL 26016501` | `RE-C4-KEI-MALL-26016501` | `NULL` → fallback | ⚠️ C4 not first token; fallback fires |
| 25 | `FWD: C4 KEV 2600050` | `FWD-C4-KEV-2600050` | `NULL` → fallback | ⚠️ C4 not first token; fallback fires |

> **Note on cases 24 & 25:** When email threads are replied to or forwarded, `RE:` and `FWD:` prepend to the subject. After sanitisation `RE-C4-KEV-2600050`, the first token is `RE`, not `C4`, so the Carrefour extractor returns `None`. The fallback (`normalise_po`) then extracts all digits: `4` + `2600050` = `42600050` — which is still wrong.
>
> **Recommended fix:** Before calling `extract_carrefour_po()`, strip common email thread prefixes:

```python
_THREAD_PREFIX = re.compile(
    r'^(RE|FWD|FW|RES|TR|AW|SV|VS|إعادة|إعادة توجيه)[\s:\-]+',
    re.IGNORECASE
)

def strip_thread_prefix(subject: str) -> str:
    """Remove RE:, FWD:, FW: etc. from subject line before PO extraction."""
    while True:
        cleaned = _THREAD_PREFIX.sub('', subject.strip())
        if cleaned == subject.strip():
            break
        subject = cleaned
    return subject
```

> Apply `strip_thread_prefix()` before `sanitise_po()` in the email extraction pipeline for Carrefour subjects.

---

## 9. Branch Token Registry — Maintenance

The `CARREFOUR_BRANCH_TOKENS` set must be kept current as Carrefour opens new branches. When `_log_unknown_branch_token()` fires in production:

1. Operations team receives an alert with the unknown token and raw input
2. Team confirms whether it is a valid branch code or a data entry error
3. If valid: add the token to `CARREFOUR_BRANCH_TOKENS` in the codebase
4. If error: correct the Acumatica `CustomerRefNbr` entry

**Current registered branch tokens:**

```
KEV  KEI  MALL  THIKA  KAREN  WESTGATE  JUNCTION  HIGHWAY
SARIT  GARDEN  CITY  PRESTIGE  NEXTGEN  MOMBASA  KISUMU
```

---

## 10. Out of Scope

- Matching on branch name alone (canonical key is always the numeric PO)
- Retroactively correcting historic Acumatica entries with wrong format
- Modifying Acumatica field validation to enforce `C4 BRANCH PO` format

---

*Prepared for Kim-Fay East Africa — Operations & Systems Team*  
*Carrefour-specific module — for general PO matching see: `po_so_matching_requirements.md`*