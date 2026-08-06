"""Kim-Fay Backorder Analytics — Streamlit entry point (PRD-backorder.md section 7)."""

from __future__ import annotations

from datetime import datetime, timezone

import pandas as pd
import streamlit as st

from src.acumatica.auth import AcumaticaAuthError
from src.acumatica.client import AcumaticaApiError, AcumaticaClient
from src.acumatica.parsers import str_val
from src.config import load_settings
from src.domain import value_at_risk
from src.domain.order_calc import build_order_calc_table
from src.domain.pipeline import dedupe_lines, flatten_sales_order_headers, flatten_sales_orders
from src.storage import csv_store
from src.ui import filters as filters_ui
from src.ui import kpi_cards
from src.ui import tables

st.set_page_config(page_title="Kim-Fay Backorder Analytics", layout="wide")

settings = load_settings()

if "lines_df" not in st.session_state:
    st.session_state.lines_df = pd.DataFrame()
if "headers_df" not in st.session_state:
    st.session_state.headers_df = pd.DataFrame()
if "sync_errors" not in st.session_state:
    st.session_state.sync_errors = []
if "last_sync_at" not in st.session_state:
    st.session_state.last_sync_at = None
if "connection_status" not in st.session_state:
    st.session_state.connection_status = None


def run_live_sync(client: AcumaticaClient, date_from: str, date_to: str, opts: filters_ui.LineFilters, progress_cb) -> None:
    errors: list[str] = []

    customer_class_map: dict[str, str] = {}
    try:
        customers = client.fetch_all_customers(on_progress=lambda p, r: progress_cb(f"Customers: page {p}, {r} rows"))
        for c in customers:
            cid = str_val(c.get("CustomerID"))
            cclass = str_val(c.get("CustomerClass"))
            if cid:
                customer_class_map[cid] = cclass or ""
    except (AcumaticaApiError, AcumaticaAuthError) as exc:
        errors.append(f"Customer enrichment failed (KP/CS may default to CS): {exc}")

    include_terminal = opts.include_completed_shortfall
    only_status = "Backorder" if opts.only_status_backorder else None
    primary_rows, meta = client.fetch_all_sales_orders_for_range(
        date_from,
        date_to,
        include_terminal=include_terminal,
        only_status=only_status,
        on_progress=lambda p, r: progress_cb(f"Sales orders: page {p}, {r} rows"),
    )
    errors.extend(meta.errors)

    still_open_rows: list[dict] = []
    if opts.include_still_open:
        so_rows, so_meta = client.fetch_all_sales_orders_for_range(
            None,
            None,
            include_terminal=False,
            only_status=only_status,
            on_progress=lambda p, r: progress_cb(f"Still-open: page {p}, {r} rows"),
        )
        errors.extend(so_meta.errors)
        still_open_rows = so_rows

    synced_at = datetime.now(timezone.utc).isoformat()
    headers_df = flatten_sales_order_headers(primary_rows)
    lines_df = flatten_sales_orders(primary_rows, customer_class_map, synced_at, vat_rate=settings.vat_rate)
    lines_df["still_open_carryover"] = False

    if still_open_rows:
        still_open_lines = flatten_sales_orders(still_open_rows, customer_class_map, synced_at, vat_rate=settings.vat_rate)
        still_open_lines = still_open_lines[
            (still_open_lines["open_qty"] > 0)
            & (still_open_lines["order_date"].astype(str).str.slice(0, 10) < date_from)
        ].copy()
        still_open_lines["still_open_carryover"] = True
        lines_df = dedupe_lines(pd.concat([lines_df, still_open_lines], ignore_index=True))

    st.session_state.lines_df = lines_df
    st.session_state.headers_df = headers_df
    st.session_state.sync_errors = errors
    st.session_state.last_sync_at = synced_at

    order_calc_df = build_order_calc_table(lines_df, vat_rate=settings.vat_rate)
    csv_store.save_extract(
        settings.data_dir,
        date_from,
        date_to,
        headers_df,
        lines_df,
        order_calc_df,
        filters_used={
            "include_still_open": opts.include_still_open,
            "include_completed_shortfall": opts.include_completed_shortfall,
            "only_status_backorder": opts.only_status_backorder,
        },
        row_counts={"orders": len(primary_rows), "lines": len(lines_df)},
        api_errors=errors,
    )

    var_headline = value_at_risk.headline(value_at_risk.eligible_lines(lines_df))
    csv_store.append_value_at_risk_snapshot(
        settings.data_dir,
        synced_at,
        date_from,
        date_to,
        value_at_risk.FORMULA_VERSION,
        var_headline.net,
        var_headline.vat,
        var_headline.gross,
        var_headline.line_count,
    )


# -------------------------------------------------------------------------
# Sidebar
# -------------------------------------------------------------------------

with st.sidebar:
    st.markdown("### Connection")
    st.text(f"Endpoint: {settings.endpoint} / {settings.version}")
    st.text(f"Client ID: {settings.masked_client_id()}")

    offline_mode = st.toggle("Offline mode (use cached CSV)", value=False)

    if st.button("Test connection", disabled=offline_mode):
        client = AcumaticaClient(settings)
        ok, message = client.connection_status()
        st.session_state.connection_status = (ok, message)

    if st.session_state.connection_status:
        ok, message = st.session_state.connection_status
        (st.success if ok else st.error)(message)

    if st.session_state.last_sync_at:
        st.caption(f"Last sync: {st.session_state.last_sync_at}")

    if st.session_state.sync_errors:
        with st.expander(f"Sync errors ({len(st.session_state.sync_errors)})"):
            for err in st.session_state.sync_errors:
                st.error(err)

# -------------------------------------------------------------------------
# Header
# -------------------------------------------------------------------------

header_col1, header_col2 = st.columns([4, 1])
with header_col1:
    st.title("Kim-Fay Backorder Analytics")
with header_col2:
    st.write("")
    refresh_clicked = st.button("🔄 Refresh from API", disabled=offline_mode, use_container_width=True)

date_range = filters_ui.render_date_controls()
date_from_str = date_range.date_from.isoformat()
date_to_str = date_range.date_to.isoformat()

so_col1, so_col2, so_col3 = st.columns(3)
with so_col1:
    include_still_open = st.checkbox("Include still-open BOs ordered before range", value=False, key="include_still_open")
with so_col2:
    include_completed_shortfall = st.checkbox("Include completed shortfall (solved)", value=False, key="include_completed_shortfall")
with so_col3:
    only_status_backorder = st.checkbox(
        "Only Status = Backorder (narrower/faster pull)",
        value=False,
        key="only_status_backorder",
        help=(
            "PRD guardrail (section 4.2): off by default. Acumatica's header Status "
            "can miss lines that are genuinely backordered on orders sitting in other "
            "open statuses, so the default pull derives backorders from line OpenQty "
            "across all non-terminal statuses. Turn this on only for a deliberately "
            "narrower/faster pull — numbers may then diverge from OrderWatch."
        ),
    )

sync_opts = filters_ui.LineFilters(
    include_still_open=include_still_open,
    include_completed_shortfall=include_completed_shortfall,
    only_status_backorder=only_status_backorder,
)

if offline_mode:
    cached = csv_store.load_cached_lines(settings.data_dir, date_from_str, date_to_str)
    if cached.empty:
        available = csv_store.list_available_extracts(settings.data_dir)
        st.warning(
            f"No cached extract for {date_from_str}..{date_to_str}. "
            + (f"Available: {', '.join(available[:5])}" if available else "No cached extracts found in data/.")
        )
    else:
        st.session_state.lines_df = cached
        st.info(f"Loaded cached extract for {date_from_str}..{date_to_str}.")
elif refresh_clicked:
    progress = st.progress(0.0, text="Starting sync…")
    status_text = st.empty()

    def _progress_cb(msg: str) -> None:
        status_text.text(msg)

    client = AcumaticaClient(settings)
    try:
        with st.spinner("Pulling sales orders from Acumatica…"):
            run_live_sync(client, date_from_str, date_to_str, sync_opts, _progress_cb)
        progress.progress(1.0, text="Sync complete")
        st.success(f"Synced {len(st.session_state.lines_df)} lines for {date_from_str}..{date_to_str}.")
    except (AcumaticaApiError, AcumaticaAuthError) as exc:
        st.error(f"Sync failed: {exc}")
    finally:
        # Always release the concurrent-login slot — Acumatica's Users
        # (SM201010) cap is easy to exhaust if every sync leaves a session open.
        client.logout()

lines_df = st.session_state.lines_df

if lines_df.empty:
    st.info(
        "No data loaded yet. Click **Refresh from API** to pull sales orders, "
        "or enable **Offline mode** to load a cached CSV extract."
    )
    st.stop()

# -------------------------------------------------------------------------
# Filters (operate on already-loaded data — no re-fetch)
# -------------------------------------------------------------------------

active_filters = filters_ui.render_line_filters(lines_df)
active_filters.include_still_open = include_still_open
active_filters.include_completed_shortfall = include_completed_shortfall
active_filters.date_from = date_from_str
active_filters.date_to = date_to_str

filtered_df = filters_ui.apply_filters(lines_df, active_filters)

# -------------------------------------------------------------------------
# KPI rows
# -------------------------------------------------------------------------

st.markdown("---")
kpi_cards.render_kpi_row_1(filtered_df)
kpi_cards.render_kpi_row_2(filtered_df)
kpi_cards.render_kpi_row_3(filtered_df)

# -------------------------------------------------------------------------
# Tabs
# -------------------------------------------------------------------------

order_calc_df = build_order_calc_table(filtered_df, vat_rate=settings.vat_rate)
history_df = csv_store.load_value_at_risk_history(settings.data_dir)

st.markdown("---")
tab1, tab2, tab3, tab4, tab5 = st.tabs(
    ["Active lines", "Order calculations", "Solved / shortfall", "Value at Risk", "Export"]
)

with tab1:
    tables.render_active_lines_tab(filtered_df)

with tab2:
    tables.render_order_calc_tab(filtered_df, backorder_mode="difference", headers_df=st.session_state.headers_df)

with tab3:
    tables.render_solved_tab(filtered_df)

with tab4:
    tables.render_value_at_risk_tab(filtered_df, history_df)

with tab5:
    tables.render_export_tab(filtered_df, order_calc_df)
