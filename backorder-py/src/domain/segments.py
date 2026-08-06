"""KP (Kimfay Professional) vs CS (Consumer Sales) customer segmentation.

Rule ported from OrderWatch's FillRateCalculator::segmentForCustomerClass —
exhaustive, no third bucket (PRD-backorder.md section 5.6 / 10.4).
"""

from __future__ import annotations

SEGMENT_KP = "KP"
SEGMENT_CS = "CS"


def segment_for_customer_class(customer_class: str | None) -> str:
    normalized = (customer_class or "").strip().upper()
    return SEGMENT_KP if normalized.startswith("KP") else SEGMENT_CS
