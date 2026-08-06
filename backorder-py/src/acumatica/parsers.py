"""Unwrap Acumatica's `{"value": ...}` field envelopes into plain Python values."""

from __future__ import annotations

from typing import Any


def val(field: Any) -> Any:
    """Unwrap a single Acumatica field. Returns None for missing/empty fields."""
    if field is None:
        return None
    if isinstance(field, dict):
        return field.get("value")
    return field


def has_field(raw: dict, key: str) -> bool:
    """True when key exists in raw AND is not an empty-array placeholder."""
    if key not in raw:
        return False
    value = raw[key]
    return not (isinstance(value, list) and len(value) == 0)


def str_val(field: Any) -> str | None:
    v = val(field)
    if v is None or v == "":
        return None
    if isinstance(v, (dict, list)):
        return None
    return str(v)


def float_val(field: Any) -> float:
    v = str_val(field)
    if v is None:
        return 0.0
    try:
        return float(v)
    except (TypeError, ValueError):
        return 0.0


def bool_val(field: Any) -> bool:
    v = val(field)
    if isinstance(v, bool):
        return v
    if isinstance(v, str):
        return v.strip().lower() in ("true", "1", "yes")
    return bool(v)


def first_string(raw: dict, fields: list[str]) -> str | None:
    for field in fields:
        v = str_val(raw.get(field))
        if v is not None and v.strip() != "":
            return v
    return None
