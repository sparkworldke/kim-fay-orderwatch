"""Manufactured (Kim-Fay) vs Trading Items (partner brands) classification.

Ported pattern list from OrderWatch's ProductBrandClassifier so numbers reconcile
(PRD-backorder.md section 5.5 / 11). Unknown / non-matching -> Manufactured.
"""

from __future__ import annotations

import re

MANUFACTURED = "manufactured"
TRADING = "trading"

TRADING_BRAND_PATTERNS: dict[str, re.Pattern] = {
    "Huggies": re.compile(r"\bHuggies\b", re.IGNORECASE),
    "Kotex": re.compile(r"\bKotex\b", re.IGNORECASE),
    "Vatika": re.compile(r"\bVatika\b", re.IGNORECASE),
    "Dabur": re.compile(r"\bDabur\b", re.IGNORECASE),
    "Miswak": re.compile(r"\bMiswak\b", re.IGNORECASE),
    "Bio-Oil": re.compile(r"\bBio[\s-]?Oil\b", re.IGNORECASE),
    "Duracell": re.compile(r"\bDuracell\b", re.IGNORECASE),
    "Dove": re.compile(r"\bDove\b", re.IGNORECASE),
    "Lux": re.compile(r"\bLux\b", re.IGNORECASE),
    "Rexona": re.compile(r"\bRexona\b", re.IGNORECASE),
    "Fem": re.compile(r"\bFem\b", re.IGNORECASE),
    "Hobby": re.compile(r"\bHobby\b", re.IGNORECASE),
    "ORS": re.compile(r"\bORS\b", re.IGNORECASE),
    "Dermoviva": re.compile(r"\bDermoviva\b", re.IGNORECASE),
}


def classify(description: str | None, inventory_id: str | None = None) -> tuple[str | None, str]:
    """Returns (brand, product_type). product_type is 'trading' or 'manufactured'."""
    haystack = " ".join(part for part in (description or "", inventory_id or "") if part).strip()

    if haystack:
        for brand, pattern in TRADING_BRAND_PATTERNS.items():
            if pattern.search(haystack):
                return brand, TRADING

    return None, MANUFACTURED
