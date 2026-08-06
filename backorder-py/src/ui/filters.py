"""Sidebar/top filter controls + pure filtering of the line-level DataFrame.

Dynamic cards rule (PRD 7.2): every KPI is a pure function of the filtered
DataFrame returned by apply_filters — no filter here re-queries the API.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import date, timedelta

import pandas as pd
import streamlit as st

DATE_PRESETS = ["Today", "Yesterday", "This Week", "This Month", "Last Month", "Custom"]
ORDER_STATES = ["All shortfalls", "Active BO", "Partially shipped", "Completed shortfall"]


@dataclass
class DateRange:
    date_from: date
    date_to: date
    preset: str


@dataclass
class LineFilters:
    brand_group: str = "All"
    brand: str = "All"
    search: str = ""
    customer_group: str = "All"
    warehouse: str = "All"
    order_state: str = "All shortfalls"
    root_cause: str = "All"
    include_still_open: bool = False
    include_completed_shortfall: bool = False
    only_status_backorder: bool = False
    date_from: str | None = None
    date_to: str | None = None


def resolve_date_preset(preset: str) -> tuple[date, date]:
    today = date.today()
    if preset == "Today":
        return today, today
    if preset == "Yesterday":
        y = today - timedelta(days=1)
        return y, y
    if preset == "This Week":
        return today - timedelta(days=today.weekday()), today
    if preset == "This Month":
        return today.replace(day=1), today
    if preset == "Last Month":
        first_this_month = today.replace(day=1)
        last_month_end = first_this_month - timedelta(days=1)
        return last_month_end.replace(day=1), last_month_end
    return today.replace(day=1), today  # Custom handled by caller


def render_date_controls() -> DateRange:
    preset = st.selectbox("Date preset", DATE_PRESETS, index=DATE_PRESETS.index("This Month"))
    default_from, default_to = resolve_date_preset(preset)

    is_custom = preset == "Custom"
    col1, col2 = st.columns(2)
    with col1:
        date_from = st.date_input("From", value=default_from, disabled=not is_custom)
    with col2:
        date_to = st.date_input("To", value=default_to, disabled=not is_custom)

    if preset != "Custom":
        date_from, date_to = default_from, default_to

    return DateRange(date_from=date_from, date_to=date_to, preset=preset)


def render_line_filters(lines_df: pd.DataFrame) -> LineFilters:
    brand_groups = ["All", "Manufactured", "Trading"]
    brands = ["All"] + sorted(
        b for b in lines_df.get("brand", pd.Series(dtype=str)).dropna().unique().tolist()
    )
    warehouses = ["All"] + sorted(
        w for w in lines_df.get("warehouse_id", pd.Series(dtype=str)).dropna().unique().tolist()
    )
    reasons = ["All"] + sorted(
        r for r in lines_df.get("reason_code", pd.Series(dtype=str)).dropna().unique().tolist()
    )

    st.markdown("**Filters**")
    c1, c2, c3 = st.columns(3)
    with c1:
        brand_group = st.selectbox("Brand group", brand_groups)
    with c2:
        brand = st.selectbox("Brand", brands)
    with c3:
        customer_group = st.selectbox("Customer group", ["All", "KP", "CS"])

    search = st.text_input("Search SO / Customer / Product", value="")

    c4, c5, c6 = st.columns(3)
    with c4:
        warehouse = st.selectbox("Warehouse", warehouses)
    with c5:
        order_state = st.selectbox("Order state", ORDER_STATES)
    with c6:
        root_cause = st.selectbox("Root cause", reasons)

    # include_still_open / include_completed_shortfall are sync-time decisions
    # rendered once in app.py (before the API pull) — set on the returned
    # LineFilters by the caller rather than re-rendered here.
    return LineFilters(
        brand_group=brand_group,
        brand=brand,
        search=search,
        customer_group=customer_group,
        warehouse=warehouse,
        order_state=order_state,
        root_cause=root_cause,
    )


def apply_filters(lines_df: pd.DataFrame, filters: LineFilters) -> pd.DataFrame:
    if lines_df.empty:
        return lines_df

    df = lines_df.copy()

    if filters.brand_group == "Manufactured":
        df = df[df["product_type"] == "manufactured"]
    elif filters.brand_group == "Trading":
        df = df[df["product_type"] == "trading"]

    if filters.brand != "All":
        df = df[df["brand"] == filters.brand]

    if filters.customer_group != "All":
        df = df[df["customer_segment"] == filters.customer_group]

    if filters.warehouse != "All":
        df = df[df["warehouse_id"] == filters.warehouse]

    if filters.root_cause != "All":
        df = df[df["reason_code"] == filters.root_cause]

    if filters.order_state == "Active BO":
        df = df[df["is_active_backorder"]]
    elif filters.order_state == "Partially shipped":
        df = df[df["fulfillment_status"] == "Partially Shipped — Backorder Pending"]
    elif filters.order_state == "Completed shortfall":
        df = df[df["is_completed_shortfall"]]
    else:
        if not filters.include_completed_shortfall:
            df = df[df["is_active_backorder"] | ~df["is_completed_shortfall"]]

    if filters.date_from and filters.date_to and "order_date" in df.columns:
        order_date_only = df["order_date"].astype(str).str.slice(0, 10)
        in_range = (order_date_only >= filters.date_from) & (order_date_only <= filters.date_to)
        # Rows carried in via "include still-open BOs before range" are exempt —
        # they are deliberately outside [date_from, date_to] (PRD 5.8).
        carryover = df["still_open_carryover"] if "still_open_carryover" in df.columns else False
        df = df[in_range | carryover]

    if filters.search:
        needle = filters.search.strip().lower()
        mask = (
            df["order_nbr"].astype(str).str.lower().str.contains(needle, na=False)
            | df["customer_id"].astype(str).str.lower().str.contains(needle, na=False)
            | df["customer_name"].astype(str).str.lower().str.contains(needle, na=False)
            | df["inventory_id"].astype(str).str.lower().str.contains(needle, na=False)
            | df["description"].astype(str).str.lower().str.contains(needle, na=False)
        )
        df = df[mask]

    return df
