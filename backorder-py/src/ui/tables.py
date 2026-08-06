"""Tab content: active lines, order calculations, solved/shortfall, export (PRD 7.4-7.7)."""

from __future__ import annotations

from pathlib import Path

import pandas as pd
import streamlit as st

from src.domain import value_at_risk
from src.domain.order_calc import build_order_calc_table, reconcile_with_headers
from src.domain.values import format_kes

ACTIVE_LINE_DISPLAY_COLUMNS = {
    "order_nbr": "Order #",
    "order_date": "Date",
    "customer_name": "Customer",
    "customer_segment": "Segment",
    "inventory_id": "Inventory ID",
    "description": "Description",
    "brand": "Brand",
    "product_type": "Type",
    "ordered_qty": "Ordered",
    "shipped_qty": "Shipped",
    "open_qty": "Open",
    "unit_price_ex_vat": "Unit price (Net)",
    "unit_price_vat": "Unit price VAT (16%)",
    "unit_price_gross": "Unit price (Gross)",
    "backorder_value": "Backorder value (Net)",
    "backorder_value_gross": "Backorder value (Gross)",
    "warehouse_id": "Warehouse",
    "status": "Status",
    "reason_normalized": "Reason",
}


def render_active_lines_tab(df: pd.DataFrame) -> None:
    active = df[df["is_active_backorder"]] if not df.empty else df
    if active.empty:
        st.info("No active backorder lines match the current filters.")
        return

    display = active.sort_values("backorder_value", ascending=False)
    display = display[list(ACTIVE_LINE_DISPLAY_COLUMNS.keys())].rename(columns=ACTIVE_LINE_DISPLAY_COLUMNS)
    st.dataframe(display, use_container_width=True, hide_index=True)


def _highlight_fulfilment(val) -> str:
    if pd.isna(val):
        return ""
    if val < 80:
        return "background-color: rgba(192,57,43,0.25)"
    if val < 95:
        return "background-color: rgba(230,126,34,0.25)"
    return "background-color: rgba(30,132,73,0.20)"


def render_order_calc_tab(df: pd.DataFrame, backorder_mode: str, headers_df: pd.DataFrame | None = None) -> None:
    calc = build_order_calc_table(df, backorder_mode=backorder_mode)
    if calc.empty:
        st.info("No orders match the current filters.")
        return

    st.caption("Prices shown as Net (ex-VAT) / VAT (16%) / Gross. Price Basis: VAT Exclusive (assumed, reverse-calc from unit price — see README).")

    display = calc.rename(
        columns={
            "order_nbr": "Region (SO)",
            "order_total": "Order Total (Net)",
            "order_total_vat": "Order Total VAT",
            "order_total_gross": "Order Total (Gross)",
            "invoice_total": "Invoice Total",
            "back_order": "Back order",
            "order_fulfilment_rate": "Order Fulfilment Rate",
            "order_to_delivery": "Order to Delivery",
            "csi": "CSI",
        }
    ).drop(columns=["fulfilment_health"])

    styled = display.style.map(_highlight_fulfilment, subset=["Order Fulfilment Rate"]).format(
        {
            "Order Total (Net)": "{:,.2f}",
            "Order Total VAT": "{:,.2f}",
            "Order Total (Gross)": "{:,.2f}",
            "Invoice Total": "{:,.2f}",
            "Back order": "{:,.2f}",
            "Order Fulfilment Rate": "{:.1f}%",
            "Order to Delivery": "{:.0f}%",
            "CSI": "{:.1f}%",
        },
        na_rep="N/A",
    )
    st.dataframe(styled, use_container_width=True, hide_index=True)

    if headers_df is not None and not headers_df.empty:
        flagged = reconcile_with_headers(calc, headers_df)
        if not flagged.empty:
            with st.expander(f"⚠ {len(flagged)} order(s) don't reconcile with Acumatica header totals (>1% drift)"):
                st.dataframe(flagged, use_container_width=True, hide_index=True)


def render_solved_tab(df: pd.DataFrame) -> None:
    shortfall = df[df["is_completed_shortfall"]] if not df.empty else df
    if shortfall.empty:
        st.info("No completed-shortfall (solved) lines in the current filter/date range.")
        return

    display = shortfall[list(ACTIVE_LINE_DISPLAY_COLUMNS.keys())].rename(columns=ACTIVE_LINE_DISPLAY_COLUMNS)
    st.dataframe(display.sort_values("Backorder value", ascending=False), use_container_width=True, hide_index=True)


def render_value_at_risk_tab(lines_df: pd.DataFrame, history_df: pd.DataFrame) -> None:
    """new-prd.md section 10 — headline + breakdowns + aging + trend + reason exceptions.

    Figures are the interim OpenQty-derived estimate (value_at_risk.FORMULA_VERSION),
    not the InItemPlan/PlanType=68-verified figure — see the disclaimer rendered here.
    """
    eligible = value_at_risk.eligible_lines(lines_df)
    hl = value_at_risk.headline(eligible)

    st.warning(value_at_risk.DISCLAIMER)
    st.caption(f"Run at: {hl.run_at} · Formula version: {value_at_risk.FORMULA_VERSION}")

    c1, c2, c3 = st.columns(3)
    c1.metric("Value at Risk (Net)", format_kes(hl.net))
    c2.metric("VAT (16%)", format_kes(hl.vat))
    c3.metric("Value at Risk (Gross)", format_kes(hl.gross))
    st.caption(f"{hl.line_count:,} eligible back-ordered lines (excludes Cancelled/Void/On Hold/Pending Approval).")

    if eligible.empty:
        st.info("No eligible back-ordered lines in the current filter/date range.")
        return

    st.markdown("#### By Inventory Item")
    st.dataframe(
        value_at_risk.breakdown_by(eligible, "inventory_id").rename(
            columns={"inventory_id": "Inventory ID", "net": "Net", "vat": "VAT", "gross": "Gross", "line_count": "Lines"}
        ),
        use_container_width=True,
        hide_index=True,
    )

    st.markdown("#### By Customer")
    st.dataframe(
        value_at_risk.breakdown_by(eligible, "customer_name").rename(
            columns={"customer_name": "Customer", "net": "Net", "vat": "VAT", "gross": "Gross", "line_count": "Lines"}
        ),
        use_container_width=True,
        hide_index=True,
    )

    st.markdown("#### By Brand Type")
    st.dataframe(
        value_at_risk.breakdown_by(eligible, "product_type").rename(
            columns={"product_type": "Brand Type", "net": "Net", "vat": "VAT", "gross": "Gross", "line_count": "Lines"}
        ),
        use_container_width=True,
        hide_index=True,
    )

    st.markdown("#### Aging")
    st.dataframe(
        value_at_risk.aging_breakdown(eligible).rename(
            columns={"aging_bucket": "Age", "net": "Net", "vat": "VAT", "gross": "Gross", "line_count": "Lines"}
        ),
        use_container_width=True,
        hide_index=True,
    )

    if not history_df.empty:
        st.markdown("#### Trend (Gross value at risk per sync)")
        trend = history_df.copy()
        trend["synced_at"] = pd.to_datetime(trend["synced_at"])
        st.line_chart(trend.set_index("synced_at")["gross"])

    st.markdown("#### Missing Back Order Reason (exceptions)")
    exceptions = eligible[eligible["reason_missing_exception"]] if "reason_missing_exception" in eligible.columns else eligible.iloc[0:0]
    if exceptions.empty:
        st.success("No lines are past the reason-aging threshold without a Back Order Reason.")
    else:
        st.dataframe(
            exceptions[["order_nbr", "inventory_id", "customer_name", "age_days", "backorder_value_gross"]].rename(
                columns={
                    "order_nbr": "Order #",
                    "inventory_id": "Inventory ID",
                    "customer_name": "Customer",
                    "age_days": "Age (days)",
                    "backorder_value_gross": "Value at Risk (Gross)",
                }
            ),
            use_container_width=True,
            hide_index=True,
        )


def render_export_tab(lines_df: pd.DataFrame, order_calc_df: pd.DataFrame) -> None:
    st.markdown("Download the currently filtered extracts as CSV.")

    c1, c2 = st.columns(2)
    with c1:
        st.download_button(
            "Download filtered lines CSV",
            data=lines_df.to_csv(index=False).encode("utf-8"),
            file_name="backorder_lines_filtered.csv",
            mime="text/csv",
            disabled=lines_df.empty,
        )
    with c2:
        st.download_button(
            "Download order calculations CSV",
            data=order_calc_df.to_csv(index=False).encode("utf-8"),
            file_name="order_calc_filtered.csv",
            mime="text/csv",
            disabled=order_calc_df.empty,
        )
