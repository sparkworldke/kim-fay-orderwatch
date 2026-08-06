"""Spot checks for formulas called out in PRD-backorder.md acceptance criteria (section 9)."""

from __future__ import annotations

from src.domain import brands, segments, values
from src.domain.pipeline import flatten_sales_orders


def test_ac10_ac12_ac14_open_qty_and_unit_price_never_document_total():
    """PRD example: SO359099-style line — 460 x 24 = 11,040, not the 570,000 document total."""
    raw_orders = [
        {
            "OrderNbr": {"value": "SO359099"},
            "Status": {"value": "Open"},
            "CustomerID": {"value": "CUST1"},
            "Date": {"value": "2026-07-01T00:00:00"},
            "OrderTotal": {"value": 570000},
            "Details": [
                {
                    "InventoryID": {"value": "ITEM-460"},
                    "OrderQty": {"value": 460},
                    "ShippedQty": {"value": 0},
                    "OpenQty": {"value": 460},
                    "UnitPrice": {"value": 24},
                }
            ],
        }
    ]
    df = flatten_sales_orders(raw_orders)
    row = df.iloc[0]
    assert row["open_qty"] == 460
    assert row["unit_price_ex_vat"] == 24
    assert row["backorder_value"] == 11040.0


def test_ac20_ac21_trading_vs_manufactured():
    brand, product_type = brands.classify("Huggies Diapers Size 4", "SKU-1")
    assert (brand, product_type) == ("Huggies", "trading")

    brand, product_type = brands.classify("Kim-Fay Baby Wipes", "SKU-2")
    assert (brand, product_type) == (None, "manufactured")


def test_ac22_kp_vs_cs_segment_is_exhaustive():
    assert segments.segment_for_customer_class("KP-RETAIL") == "KP"
    assert segments.segment_for_customer_class("kp-wholesale") == "KP"
    assert segments.segment_for_customer_class("RETAIL") == "CS"
    assert segments.segment_for_customer_class(None) == "CS"


def test_value_guardrail_zero_price_or_qty_yields_zero():
    assert values.line_value(0, 100) == 0.0
    assert values.line_value(10, 0) == 0.0
