"""Back Order Reason — controlled list + aging-exception rule (new-prd.md FR11-FR14).

Acumatica's raw `ReasonCode` on Details (when present) is free-ish text from
the tenant's configured reason codes. We map it onto a fixed, reportable list
rather than exposing free text (guardrail: "Back Order Reason is a controlled
list, not free text").
"""

from __future__ import annotations

CONTROLLED_REASONS = [
    "Vendor/Supplier Delay",
    "Production Capacity",
    "Demand Spike",
    "Component Shortage",
    "Quality Hold",
    "Other",
]

# Best-effort keyword mapping from whatever Acumatica's raw ReasonCode contains
# onto the controlled list. Extend as real-world reason codes are observed.
_KEYWORD_MAP: dict[str, str] = {
    "vendor": "Vendor/Supplier Delay",
    "supplier": "Vendor/Supplier Delay",
    "po delay": "Vendor/Supplier Delay",
    "production": "Production Capacity",
    "capacity": "Production Capacity",
    "demand": "Demand Spike",
    "spike": "Demand Spike",
    "component": "Component Shortage",
    "shortage": "Component Shortage",
    "quality": "Quality Hold",
    "hold": "Quality Hold",
}

DEFAULT_AGING_EXCEPTION_DAYS = 15


def normalize_reason(raw_reason_code: str | None) -> str | None:
    """Map a raw Acumatica ReasonCode onto CONTROLLED_REASONS, or None if absent."""
    if not raw_reason_code or not raw_reason_code.strip():
        return None

    needle = raw_reason_code.strip().lower()
    for keyword, controlled in _KEYWORD_MAP.items():
        if keyword in needle:
            return controlled

    return "Other"


def is_missing_reason_exception(
    normalized_reason: str | None, age_days: float | None, threshold_days: int = DEFAULT_AGING_EXCEPTION_DAYS
) -> bool:
    """Lines back-ordered past the aging threshold with no reason are a data-quality
    exception — surfaced, never silently dropped (FR14)."""
    if normalized_reason is not None:
        return False
    if age_days is None:
        return False
    return age_days > threshold_days
