"""Open / shipped / backorder quantity derivation.

Ported from OrderWatch's SalesOrderLineFulfillmentDeriver so numbers reconcile
between the two apps (see PRD-backorder.md section 5.1 and 11).
"""

from __future__ import annotations

from src.acumatica.parsers import float_val, has_field


def resolve_open_qty(line_raw: dict, order_qty: float, shipped_qty: float, cancelled_qty: float) -> float:
    """Prefer Acumatica OpenQty (including explicit 0). Else order - shipped - cancelled."""
    if has_field(line_raw, "OpenQty") or has_field(line_raw, "OpenLineQty"):
        return float_val(line_raw.get("OpenQty") or line_raw.get("OpenLineQty"))

    if order_qty <= 0:
        return 0.0

    return max(order_qty - shipped_qty - cancelled_qty, 0.0)


def resolve_qty_on_shipments(line_raw: dict, shipped_qty: float) -> tuple[float, str]:
    """IpayV2 often omits ShippedQty; QtyOnShipments is the reliable delivered qty."""
    if has_field(line_raw, "QtyOnShipments"):
        return float_val(line_raw.get("QtyOnShipments")), "qty_on_shipments"
    return shipped_qty, "shipped_qty_fallback"


def resolve_shipped_qty(line_raw: dict) -> tuple[float, float, str]:
    """Returns (shipped_qty, qty_on_shipments, qty_on_shipments_source)."""
    shipped_explicit = float_val(line_raw.get("ShippedQty")) if has_field(line_raw, "ShippedQty") else None
    qty_on_shipments, source = resolve_qty_on_shipments(line_raw, shipped_explicit or 0.0)

    if shipped_explicit is not None and shipped_explicit > 0:
        shipped_qty = shipped_explicit
    elif qty_on_shipments > 0:
        shipped_qty = qty_on_shipments
    else:
        shipped_qty = shipped_explicit or 0.0

    return shipped_qty, qty_on_shipments, source


def backorder_qty(demand_qty: float, qty_shipped: float, open_qty: float = 0.0, cancelled_qty: float = 0.0) -> float:
    """Missing / open qty only — never (order - shipped) when cancelled/closed remainder differs."""
    if open_qty > 0:
        return open_qty

    derived = max(demand_qty - qty_shipped - max(cancelled_qty, 0.0), 0.0)
    if derived > 0:
        return derived

    return max(demand_qty - qty_shipped, 0.0)


def is_active_backorder_line(open_qty: float, status: str) -> bool:
    """A line is an active backorder when open_qty > 0 and the order status is non-terminal."""
    terminal = {"Completed", "Cancelled", "Canceled", "Rejected"}
    return open_qty > 0 and status not in terminal


def safe_fill_rate(shipped_qty: float, ordered_qty: float) -> float | None:
    """(shipped / ordered) * 100, capped at 100, None on zero-ordered (GT-04/GT-05)."""
    if ordered_qty <= 0:
        return None
    rate = (shipped_qty / ordered_qty) * 100
    return round(min(rate, 100.0), 2)
