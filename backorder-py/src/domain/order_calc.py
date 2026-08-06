"""Order-level rollup matching the Excel inspiration sheets (PRD section 5.3, 7.5).

Order Total / Invoice Total / Back order / Order Fulfilment Rate / Order to
Delivery / CSI, computed from the flattened line-level DataFrame.
"""

from __future__ import annotations

import pandas as pd

from src.domain import values

FULFILMENT_CRITICAL = 80.0
FULFILMENT_AT_RISK = 95.0
RECONCILE_TOLERANCE_PCT = 1.0  # flag orders where computed vs header total drifts >1%


def fulfilment_health(pct: float | None) -> str:
    if pct is None:
        return "na"
    if pct < FULFILMENT_CRITICAL:
        return "critical"
    if pct < FULFILMENT_AT_RISK:
        return "at_risk"
    return "healthy"


def build_order_calc_table(
    lines_df: pd.DataFrame, backorder_mode: str = "difference", vat_rate: float = 0.16
) -> pd.DataFrame:
    """One row per order_nbr, aggregated from all lines belonging to that order.

    backorder_mode:
      "difference" — Back order = Order Total - Invoice Total (default; matches
                      the Excel sample columns, includes cancelled residual).
      "open_only"  — Back order = sum(open_qty * unit_price) for active lines only.
    """
    output_columns = [
        "order_nbr",
        "order_total",
        "order_total_vat",
        "order_total_gross",
        "invoice_total",
        "back_order",
        "order_fulfilment_rate",
        "order_to_delivery",
        "csi",
        "fulfilment_health",
    ]
    if lines_df.empty:
        return pd.DataFrame(columns=output_columns)

    grouped = lines_df.groupby("order_nbr", as_index=False).agg(
        order_total=("order_value", "sum"),
        invoice_total=("invoiced_value", "sum"),
        open_backorder_total=("backorder_value", "sum"),
        status=("status", "first"),
        fully_shipped=("open_qty", lambda s: (s <= 0).all()),
    )

    if backorder_mode == "open_only":
        grouped["back_order"] = grouped["open_backorder_total"]
    else:
        grouped["back_order"] = (grouped["order_total"] - grouped["invoice_total"]).clip(lower=0)

    def _fulfilment(row: pd.Series) -> float | None:
        if row["order_total"] <= 0:
            return None
        if str(row["status"]).strip().lower() in ("on hold", "pending approval"):
            return None
        rate = (row["invoice_total"] / row["order_total"]) * 100
        return round(min(rate, 100.0), 2)

    grouped["order_fulfilment_rate"] = grouped.apply(_fulfilment, axis=1)

    # No Shipment-entity delivery-date evidence pulled in v1: fully-shipped orders
    # are treated as on-time (100%); everything else is left N/A rather than guessed.
    grouped["order_to_delivery"] = grouped["fully_shipped"].map(lambda full: 100.0 if full else None)

    grouped["csi"] = grouped["order_fulfilment_rate"]
    grouped["fulfilment_health"] = grouped["order_fulfilment_rate"].map(fulfilment_health)

    grouped["order_total_vat"] = grouped["order_total"].map(lambda v: values.vat_amount(v, vat_rate))
    grouped["order_total_gross"] = grouped["order_total"].map(lambda v: values.gross_value(v, vat_rate))

    return grouped[output_columns].sort_values("order_fulfilment_rate", ascending=True, na_position="first")


def reconcile_with_headers(order_calc_df: pd.DataFrame, headers_df: pd.DataFrame) -> pd.DataFrame:
    """Flag (never silently drop) orders where our computed Gross total drifts from
    the Acumatica SalesOrder header OrderTotal by more than RECONCILE_TOLERANCE_PCT
    (new-prd.md guardrail 9.1). Returns only the flagged rows.
    """
    if order_calc_df.empty or headers_df.empty or "order_total" not in headers_df.columns:
        return pd.DataFrame(columns=["order_nbr", "computed_gross", "header_order_total", "delta_pct"])

    merged = order_calc_df.merge(
        headers_df[["order_nbr", "order_total"]].rename(columns={"order_total": "header_order_total"}),
        on="order_nbr",
        how="inner",
    )
    merged = merged[merged["header_order_total"] > 0].copy()
    if merged.empty:
        return pd.DataFrame(columns=["order_nbr", "computed_gross", "header_order_total", "delta_pct"])

    merged["computed_gross"] = merged["order_total_gross"]
    merged["delta_pct"] = (
        (merged["computed_gross"] - merged["header_order_total"]).abs() / merged["header_order_total"]
    ) * 100

    flagged = merged[merged["delta_pct"] > RECONCILE_TOLERANCE_PCT]
    return flagged[["order_nbr", "computed_gross", "header_order_total", "delta_pct"]].sort_values(
        "delta_pct", ascending=False
    )


def fill_rate_summary(lines_df: pd.DataFrame) -> float | None:
    """Period fill rate = sum(shipped) / sum(ordered) * 100, rolled up by qty (PRD 5.4)."""
    if lines_df.empty:
        return None
    total_ordered = lines_df["ordered_qty"].sum()
    total_shipped = lines_df["shipped_qty"].clip(upper=lines_df["ordered_qty"]).sum()
    if total_ordered <= 0:
        return None
    return round(min((total_shipped / total_ordered) * 100, 100.0), 2)
