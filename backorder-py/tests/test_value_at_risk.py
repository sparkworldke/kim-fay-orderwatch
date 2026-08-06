"""Tests for the new-prd.md Value-at-Risk / VAT / reason-aging additions."""

from __future__ import annotations

import pandas as pd

from src.domain import reasons, value_at_risk, values
from src.domain.order_calc import build_order_calc_table, reconcile_with_headers
from src.domain.pipeline import flatten_sales_orders


def test_vat_amount_and_gross_reverse_calc():
    assert values.vat_amount(1000, 0.16) == 160.0
    assert values.gross_value(1000, 0.16) == 1160.0
    assert values.vat_amount(0, 0.16) == 0.0


def test_reason_normalization_maps_to_controlled_list():
    assert reasons.normalize_reason("Vendor Supply Delay") == "Vendor/Supplier Delay"
    assert reasons.normalize_reason("something unrecognized") == "Other"
    assert reasons.normalize_reason(None) is None
    assert reasons.normalize_reason("  ") is None


def test_missing_reason_exception_flags_only_past_threshold():
    assert reasons.is_missing_reason_exception(None, age_days=20, threshold_days=15) is True
    assert reasons.is_missing_reason_exception(None, age_days=5, threshold_days=15) is False
    assert reasons.is_missing_reason_exception("Other", age_days=20, threshold_days=15) is False
    assert reasons.is_missing_reason_exception(None, age_days=None) is False


def test_value_at_risk_excludes_on_hold_and_void():
    df = pd.DataFrame(
        [
            {
                "status": "Open",
                "is_active_backorder": True,
                "backorder_value": 100.0,
                "backorder_value_vat": 16.0,
                "backorder_value_gross": 116.0,
            },
            {
                "status": "On Hold",
                "is_active_backorder": True,
                "backorder_value": 500.0,
                "backorder_value_vat": 80.0,
                "backorder_value_gross": 580.0,
            },
            {
                "status": "Void",
                "is_active_backorder": True,
                "backorder_value": 999.0,
                "backorder_value_vat": 159.84,
                "backorder_value_gross": 1158.84,
            },
        ]
    )
    eligible = value_at_risk.eligible_lines(df)
    assert len(eligible) == 1
    hl = value_at_risk.headline(eligible)
    assert hl.net == 100.0
    assert hl.gross == 116.0


def test_aging_bucket_labels():
    assert value_at_risk.aging_bucket_label(0) == "0-7 days"
    assert value_at_risk.aging_bucket_label(7) == "0-7 days"
    assert value_at_risk.aging_bucket_label(8) == "8-14 days"
    assert value_at_risk.aging_bucket_label(30) == "15-30 days"
    assert value_at_risk.aging_bucket_label(31) == "30+ days"
    assert value_at_risk.aging_bucket_label(None) == "Unknown"


def test_order_calc_header_reconciliation_flags_drift():
    lines_df = pd.DataFrame(
        [
            {
                "order_nbr": "SO1",
                "order_value": 1000.0,
                "invoiced_value": 1000.0,
                "backorder_value": 0.0,
                "status": "Completed",
                "open_qty": 0,
            }
        ]
    )
    calc = build_order_calc_table(lines_df, vat_rate=0.16)
    # computed gross = 1160; header says 5000 -> way more than 1% drift, should be flagged.
    headers_df = pd.DataFrame([{"order_nbr": "SO1", "order_total": 5000.0}])
    flagged = reconcile_with_headers(calc, headers_df)
    assert len(flagged) == 1
    assert flagged.iloc[0]["order_nbr"] == "SO1"

    # A header total close to computed gross should NOT be flagged.
    headers_df_close = pd.DataFrame([{"order_nbr": "SO1", "order_total": 1160.0}])
    assert reconcile_with_headers(calc, headers_df_close).empty


def test_pipeline_adds_vat_and_aging_columns():
    raw_orders = [
        {
            "OrderNbr": {"value": "SO1"},
            "Status": {"value": "Open"},
            "CustomerID": {"value": "CUST1"},
            "Date": {"value": "2020-01-01T00:00:00"},
            "Details": [
                {
                    "InventoryID": {"value": "ITEM-1"},
                    "OrderQty": {"value": 10},
                    "ShippedQty": {"value": 0},
                    "OpenQty": {"value": 10},
                    "UnitPrice": {"value": 100},
                }
            ],
        }
    ]
    df = flatten_sales_orders(raw_orders, vat_rate=0.16)
    row = df.iloc[0]
    assert row["backorder_value"] == 1000.0
    assert row["backorder_value_vat"] == 160.0
    assert row["backorder_value_gross"] == 1160.0
    assert row["age_days"] is not None and row["age_days"] > 0
    # No ReasonCode present, order is very old -> should be flagged as missing-reason exception.
    assert bool(row["reason_missing_exception"]) is True
