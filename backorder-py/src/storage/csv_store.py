"""CSV persistence for raw + derived extracts (PRD section 6)."""

from __future__ import annotations

import glob
import json
import os
from datetime import datetime, timezone
from pathlib import Path

import pandas as pd


def _paths(data_dir: Path, date_from: str, date_to: str) -> dict[str, Path]:
    suffix = f"{date_from}_{date_to}"
    return {
        "raw_orders": data_dir / f"raw_sales_orders_{suffix}.csv",
        "raw_lines": data_dir / f"raw_sales_order_lines_{suffix}.csv",
        "backorder_lines": data_dir / f"backorder_lines_{suffix}.csv",
        "order_calc": data_dir / f"order_calc_{suffix}.csv",
        "meta": data_dir / "last_sync_meta.json",
    }


def save_extract(
    data_dir: Path,
    date_from: str,
    date_to: str,
    headers_df: pd.DataFrame,
    lines_df: pd.DataFrame,
    order_calc_df: pd.DataFrame,
    *,
    filters_used: dict,
    row_counts: dict,
    api_errors: list[str],
) -> dict[str, Path]:
    data_dir.mkdir(parents=True, exist_ok=True)
    paths = _paths(data_dir, date_from, date_to)

    headers_df.to_csv(paths["raw_orders"], index=False)

    raw_line_cols = [
        "order_nbr",
        "order_date",
        "customer_id",
        "status",
        "inventory_id",
        "line_nbr",
        "description",
        "ordered_qty",
        "shipped_qty",
        "open_qty",
        "cancelled_qty",
        "unit_price_ex_vat",
        "warehouse_id",
        "uom",
    ]
    lines_df.reindex(columns=raw_line_cols).to_csv(paths["raw_lines"], index=False)

    derived_subset = lines_df[
        lines_df["is_active_backorder"] | lines_df["is_completed_shortfall"]
    ] if not lines_df.empty else lines_df
    derived_subset.to_csv(paths["backorder_lines"], index=False)

    order_calc_df.to_csv(paths["order_calc"], index=False)

    meta = {
        "synced_at": datetime.now(timezone.utc).isoformat(),
        "date_from": date_from,
        "date_to": date_to,
        "filters_used": filters_used,
        "row_counts": row_counts,
        "api_errors": api_errors,
    }
    paths["meta"].write_text(json.dumps(meta, indent=2), encoding="utf-8")

    return paths


def load_last_meta(data_dir: Path) -> dict | None:
    meta_path = data_dir / "last_sync_meta.json"
    if not meta_path.exists():
        return None
    return json.loads(meta_path.read_text(encoding="utf-8"))


def list_available_extracts(data_dir: Path) -> list[str]:
    """Return distinct {from}_{to} suffixes available in DATA_DIR, newest first."""
    pattern = str(data_dir / "backorder_lines_*.csv")
    files = sorted(glob.glob(pattern), key=os.path.getmtime, reverse=True)
    suffixes = []
    for f in files:
        name = Path(f).stem  # backorder_lines_{from}_{to}
        suffix = name.replace("backorder_lines_", "", 1)
        suffixes.append(suffix)
    return suffixes


def load_cached_lines(data_dir: Path, date_from: str, date_to: str) -> pd.DataFrame:
    paths = _paths(data_dir, date_from, date_to)
    if not paths["backorder_lines"].exists():
        return pd.DataFrame()
    return pd.read_csv(paths["backorder_lines"])


def load_cached_order_calc(data_dir: Path, date_from: str, date_to: str) -> pd.DataFrame:
    paths = _paths(data_dir, date_from, date_to)
    if not paths["order_calc"].exists():
        return pd.DataFrame()
    return pd.read_csv(paths["order_calc"])


# -------------------------------------------------------------------------
# Value-at-Risk history — append-only snapshot log for the trend view
# (new-prd.md section 10.3 "Trend line" + guardrail "time-stamp every report
# run and snapshot back-order data at time of run").
# -------------------------------------------------------------------------

VALUE_AT_RISK_HISTORY_COLUMNS = [
    "synced_at",
    "date_from",
    "date_to",
    "formula_version",
    "net",
    "vat",
    "gross",
    "line_count",
]


def append_value_at_risk_snapshot(
    data_dir: Path,
    synced_at: str,
    date_from: str,
    date_to: str,
    formula_version: str,
    net: float,
    vat: float,
    gross: float,
    line_count: int,
) -> Path:
    data_dir.mkdir(parents=True, exist_ok=True)
    history_path = data_dir / "value_at_risk_history.csv"

    row = pd.DataFrame(
        [
            {
                "synced_at": synced_at,
                "date_from": date_from,
                "date_to": date_to,
                "formula_version": formula_version,
                "net": net,
                "vat": vat,
                "gross": gross,
                "line_count": line_count,
            }
        ],
        columns=VALUE_AT_RISK_HISTORY_COLUMNS,
    )
    row.to_csv(history_path, mode="a", header=not history_path.exists(), index=False)
    return history_path


def load_value_at_risk_history(data_dir: Path) -> pd.DataFrame:
    history_path = data_dir / "value_at_risk_history.csv"
    if not history_path.exists():
        return pd.DataFrame(columns=VALUE_AT_RISK_HISTORY_COLUMNS)
    return pd.read_csv(history_path)
