"""Dynamic KPI cards — every value is a pure function of the filtered DataFrame
passed in (PRD section 7.3). No card here triggers a re-fetch.
"""

from __future__ import annotations

import pandas as pd
import streamlit as st

from src.domain.values import format_kes

CARD_COLORS = {
    "red": "#c0392b",
    "green": "#1e8449",
    "neutral": "#2c3e50",
}


def _card(label: str, value: str, subtitle: str, color: str = "neutral") -> str:
    hex_color = CARD_COLORS.get(color, CARD_COLORS["neutral"])
    return f"""
    <div style="border-left: 4px solid {hex_color}; padding: 0.5rem 0.75rem; margin-bottom: 0.5rem;
                background: rgba(127,127,127,0.06); border-radius: 4px;">
      <div style="font-size: 0.78rem; opacity: 0.75;">{label}</div>
      <div style="font-size: 1.35rem; font-weight: 600; color: {hex_color};">{value}</div>
      <div style="font-size: 0.72rem; opacity: 0.65;">{subtitle}</div>
    </div>
    """


def _active(df: pd.DataFrame) -> pd.DataFrame:
    if df.empty or "is_active_backorder" not in df.columns:
        return df
    return df[df["is_active_backorder"]]


def _completed_shortfall(df: pd.DataFrame) -> pd.DataFrame:
    if df.empty or "is_completed_shortfall" not in df.columns:
        return df
    return df[df["is_completed_shortfall"]]


def render_kpi_row_1(df: pd.DataFrame) -> None:
    backorder_value = _active(df)["backorder_value"].sum() if not df.empty else 0.0
    invoiced_value = df["invoiced_value"].sum() if not df.empty else 0.0
    order_value = df["order_value"].sum() if not df.empty else 0.0

    c1, c2, c3 = st.columns(3)
    with c1:
        st.markdown(
            _card("Backorder value", format_kes(backorder_value), "Unshipped remainder × unit price", "red"),
            unsafe_allow_html=True,
        )
    with c2:
        st.markdown(
            _card("Invoiced value", format_kes(invoiced_value), "Shipped qty × unit price", "green"),
            unsafe_allow_html=True,
        )
    with c3:
        st.markdown(
            _card(
                "Order value",
                format_kes(order_value),
                "Ordered qty × unit price (invoiced + backorder)",
                "neutral",
            ),
            unsafe_allow_html=True,
        )


def render_kpi_row_2(df: pd.DataFrame) -> None:
    active = _active(df)

    def _segment_value(mask_col: str, mask_val: str) -> tuple[float, float]:
        subset = active[active[mask_col] == mask_val] if not active.empty else active
        bo = subset["backorder_value"].sum() if not subset.empty else 0.0
        inv_subset = df[df[mask_col] == mask_val] if not df.empty else df
        inv = inv_subset["invoiced_value"].sum() if not inv_subset.empty else 0.0
        return bo, inv

    manufactured_bo, manufactured_inv = _segment_value("product_type", "manufactured")
    trading_bo, trading_inv = _segment_value("product_type", "trading")
    kp_bo, kp_inv = _segment_value("customer_segment", "KP")
    cs_bo, cs_inv = _segment_value("customer_segment", "CS")

    c1, c2, c3, c4 = st.columns(4)
    with c1:
        st.markdown(
            _card(
                "Manufactured",
                format_kes(manufactured_bo),
                f"Kim-Fay products · invoiced {format_kes(manufactured_inv)}",
                "red",
            ),
            unsafe_allow_html=True,
        )
    with c2:
        st.markdown(
            _card(
                "Trading (Partners)",
                format_kes(trading_bo),
                f"Third-party brands · invoiced {format_kes(trading_inv)}",
                "red",
            ),
            unsafe_allow_html=True,
        )
    with c3:
        st.markdown(
            _card("KP", format_kes(kp_bo), f"Kimfay Professional · invoiced {format_kes(kp_inv)}", "red"),
            unsafe_allow_html=True,
        )
    with c4:
        st.markdown(
            _card("CS", format_kes(cs_bo), f"Consumer Sales · invoiced {format_kes(cs_inv)}", "red"),
            unsafe_allow_html=True,
        )


def render_kpi_row_3(df: pd.DataFrame) -> None:
    active = _active(df)
    shortfall = _completed_shortfall(df)

    open_lines = len(active)
    skus = active["inventory_id"].nunique() if not active.empty else 0
    open_orders = active["order_nbr"].nunique() if not active.empty else 0
    completed_shortfall_value = (
        ((shortfall["ordered_qty"] - shortfall["shipped_qty"]) * shortfall["unit_price_ex_vat"]).sum()
        if not shortfall.empty
        else 0.0
    )
    outstanding = active["backorder_value"].sum() if not active.empty else 0.0

    c1, c2, c3, c4, c5 = st.columns(5)
    with c1:
        st.markdown(_card("Open lines", f"{open_lines:,}", "Count", "neutral"), unsafe_allow_html=True)
    with c2:
        st.markdown(_card("SKUs", f"{skus:,}", "Distinct inventory IDs", "neutral"), unsafe_allow_html=True)
    with c3:
        st.markdown(_card("Open orders", f"{open_orders:,}", "Distinct SO", "neutral"), unsafe_allow_html=True)
    with c4:
        st.markdown(
            _card("Completed shortfall", format_kes(completed_shortfall_value), "Historical residual", "neutral"),
            unsafe_allow_html=True,
        )
    with c5:
        st.markdown(
            _card("Current outstanding", format_kes(outstanding), "Live open balance", "neutral"),
            unsafe_allow_html=True,
        )
