"""Guardrail tests GT-01..GT-06 from PRD-backorder.md section 9.6."""

from __future__ import annotations

import pandas as pd

from src.domain import quantities, values
from src.domain.order_calc import build_order_calc_table
from src.domain.pipeline import dedupe_lines


def test_gt01_zero_open_qty_full_ship_not_active_backorder():
    open_qty = quantities.resolve_open_qty({"OpenQty": {"value": 0}}, order_qty=10, shipped_qty=10, cancelled_qty=0)
    assert open_qty == 0
    assert quantities.is_active_backorder_line(open_qty, status="Open") is False


def test_gt02_open_qty_times_price():
    assert values.line_value(6, 100) == 600.00


def test_gt03_cancelled_only_remainder_not_active_backorder():
    line_raw = {"CancelledQty": {"value": 10}}  # no OpenQty field -> derive
    open_qty = quantities.resolve_open_qty(line_raw, order_qty=10, shipped_qty=0, cancelled_qty=10)
    assert open_qty == 0
    assert quantities.is_active_backorder_line(open_qty, status="Open") is False


def test_gt04_zero_order_total_fulfilment_is_na():
    lines_df = pd.DataFrame(
        [
            {
                "order_nbr": "SO1",
                "order_value": 0.0,
                "invoiced_value": 0.0,
                "backorder_value": 0.0,
                "status": "Open",
                "open_qty": 0,
            }
        ]
    )
    calc = build_order_calc_table(lines_df)
    row = calc.loc[calc["order_nbr"] == "SO1"].iloc[0]
    assert pd.isna(row["order_fulfilment_rate"])


def test_gt05_overship_does_not_exceed_100_pct():
    assert quantities.safe_fill_rate(shipped_qty=15, ordered_qty=10) == 100.0


def test_gt06_dedupe_merge_of_range_and_still_open_sets():
    range_lines = pd.DataFrame([{"order_nbr": "SO1", "line_nbr": "1", "open_qty": 5}])
    still_open_lines = pd.DataFrame([{"order_nbr": "SO1", "line_nbr": "1", "open_qty": 5}])
    merged = dedupe_lines(pd.concat([range_lines, still_open_lines], ignore_index=True))
    assert len(merged) == 1
