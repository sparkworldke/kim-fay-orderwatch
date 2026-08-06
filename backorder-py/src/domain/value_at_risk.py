"""Value-at-Risk ("Lost to Business") view — new-prd.md sections 9-10.

IMPORTANT — formula provenance (versioned per guardrail "version and document
any change to the lost value formula"):

  FORMULA_VERSION = "v1-openqty-interim"

new-prd.md section 10.1 defines Back Order Qty via the InItemPlan
(PlanType=68) allocation join — the authoritative signal for "genuinely
out of stock" vs "just hasn't shipped yet". That entity is NOT exposed on
this tenant's IpayV2/22.200.001 endpoint (confirmed 404 via live probe; see
ACUMATICA-ENDPOINT-EXTENSION-NEEDED.md). Until it is, this module computes
Value at Risk from the existing OpenQty-derived `is_active_backorder` /
`backorder_value` fields instead. Never present this as the InItemPlan-
verified figure — always show DISCLAIMER alongside it.
"""

from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime, timezone

import pandas as pd

FORMULA_VERSION = "v1-openqty-interim"

DISCLAIMER = (
    "Value at risk is an estimate based on current OpenQty-derived back-order "
    "status and is not a guaranteed revenue loss — orders may still ship. "
    "This is an interim figure (formula " + FORMULA_VERSION + "), not yet verified "
    "against Acumatica's InItemPlan/PlanType=68 allocation join (not exposed on "
    "this endpoint — see ACUMATICA-ENDPOINT-EXTENSION-NEEDED.md)."
)

# Excluded beyond the terminal statuses already excluded from is_active_backorder
# (Completed/Cancelled/Canceled/Rejected) — new-prd.md 9.1: "Exclude Cancelled,
# Void, and On Hold orders from active back-order and lost-value totals."
_ADDITIONAL_EXCLUDED_STATUSES = {"on hold", "void", "pending approval"}

AGING_BUCKETS = [
    (0, 7, "0-7 days"),
    (8, 14, "8-14 days"),
    (15, 30, "15-30 days"),
    (31, None, "30+ days"),
]


def eligible_lines(lines_df: pd.DataFrame) -> pd.DataFrame:
    """Active backorder lines eligible for value-at-risk totals (guardrail 9.1)."""
    if lines_df.empty:
        return lines_df
    status_ok = ~lines_df["status"].astype(str).str.strip().str.lower().isin(_ADDITIONAL_EXCLUDED_STATUSES)
    return lines_df[lines_df["is_active_backorder"] & status_ok]


def aging_bucket_label(age_days: float | None) -> str:
    if age_days is None or pd.isna(age_days):
        return "Unknown"
    for lo, hi, label in AGING_BUCKETS:
        if hi is None:
            if age_days >= lo:
                return label
        elif lo <= age_days <= hi:
            return label
    return "Unknown"


@dataclass
class Headline:
    net: float
    vat: float
    gross: float
    line_count: int
    run_at: str


def headline(eligible_df: pd.DataFrame) -> Headline:
    if eligible_df.empty:
        return Headline(0.0, 0.0, 0.0, 0, datetime.now(timezone.utc).isoformat())
    return Headline(
        net=round(eligible_df["backorder_value"].sum(), 2),
        vat=round(eligible_df["backorder_value_vat"].sum(), 2),
        gross=round(eligible_df["backorder_value_gross"].sum(), 2),
        line_count=len(eligible_df),
        run_at=datetime.now(timezone.utc).isoformat(),
    )


def breakdown_by(eligible_df: pd.DataFrame, column: str, top_n: int = 20) -> pd.DataFrame:
    """Value at risk grouped by an arbitrary column (item, customer, brand)."""
    if eligible_df.empty or column not in eligible_df.columns:
        return pd.DataFrame(columns=[column, "net", "vat", "gross", "line_count"])

    grouped = eligible_df.groupby(column, as_index=False).agg(
        net=("backorder_value", "sum"),
        vat=("backorder_value_vat", "sum"),
        gross=("backorder_value_gross", "sum"),
        line_count=("backorder_value", "count"),
    )
    return grouped.sort_values("gross", ascending=False).head(top_n)


def aging_breakdown(eligible_df: pd.DataFrame) -> pd.DataFrame:
    if eligible_df.empty:
        return pd.DataFrame(columns=["aging_bucket", "net", "vat", "gross", "line_count"])

    df = eligible_df.copy()
    df["aging_bucket"] = df["age_days"].map(aging_bucket_label)
    grouped = df.groupby("aging_bucket", as_index=False).agg(
        net=("backorder_value", "sum"),
        vat=("backorder_value_vat", "sum"),
        gross=("backorder_value_gross", "sum"),
        line_count=("backorder_value", "count"),
    )
    order = {label: i for i, (_, _, label) in enumerate(AGING_BUCKETS)}
    order["Unknown"] = len(order)
    grouped["_sort"] = grouped["aging_bucket"].map(order)
    return grouped.sort_values("_sort").drop(columns="_sort")
