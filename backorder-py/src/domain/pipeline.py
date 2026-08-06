"""Flatten raw SalesOrder + Details JSON into the line-level table (PRD section 6.2).

This is the single place that wires together parsers + quantities + values +
brands + segments, so every consumer (CSV export, KPI cards, tables) sees the
same derived numbers.
"""

from __future__ import annotations

from datetime import date, datetime, timezone

import pandas as pd

from src.acumatica.parsers import bool_val, first_string, float_val, str_val
from src.domain import brands, quantities, reasons, segments, values

TERMINAL_STATUSES = {"Completed", "Cancelled", "Canceled", "Rejected"}
DEFAULT_VAT_RATE = 0.16

MINIMUM_COLUMNS = [
    "order_nbr",
    "order_date",
    "status",
    "customer_id",
    "customer_name",
    "customer_segment",
    "inventory_id",
    "description",
    "brand",
    "product_type",
    "warehouse_id",
    "uom",
    "line_nbr",
    "ordered_qty",
    "shipped_qty",
    "open_qty",
    "cancelled_qty",
    "unit_price_ex_vat",
    "order_value",
    "invoiced_value",
    "backorder_value",
    "fulfillment_status",
    "reason_code",
    "is_active_backorder",
    "is_completed_shortfall",
    "synced_at",
    # extra data-quality columns beyond the PRD minimum
    "open_qty_source",
    "flag_missing_price",
    "flag_missing_inventory_id",
    # VAT Net/VAT/Gross breakdown (new-prd.md FR4, section 5.2) — reverse-calc off
    # unit_price_ex_vat via the configurable VAT rate; see domain/values.py for why.
    "unit_price_vat",
    "unit_price_gross",
    "price_basis",
    "order_value_vat",
    "order_value_gross",
    "invoiced_value_vat",
    "invoiced_value_gross",
    "backorder_value_vat",
    "backorder_value_gross",
    # Back Order Reason — controlled list + aging exception (new-prd.md FR11-FR14)
    "reason_normalized",
    "age_days",
    "reason_missing_exception",
]


def derive_line_status(order_qty: float, shipped_qty: float, open_qty: float, cancelled_qty: float, completed: bool) -> str:
    if completed and (open_qty <= 0 or shipped_qty >= order_qty):
        return "Fully Fulfilled"
    if open_qty <= 0 and shipped_qty >= order_qty and order_qty > 0:
        return "Fully Fulfilled"
    if open_qty <= 0 and completed:
        return "Fully Fulfilled"
    if open_qty > 0 and shipped_qty < order_qty:
        return "Backorders Imported"
    if cancelled_qty > 0 and shipped_qty == 0.0:
        return "Cancelled"
    if shipped_qty > 0 and open_qty > 0:
        return "Partially Shipped — Backorder Pending"
    return "Pending Shipment"


def flatten_sales_orders(
    raw_orders: list[dict],
    customer_class_map: dict[str, str] | None = None,
    synced_at: str | None = None,
    vat_rate: float = DEFAULT_VAT_RATE,
) -> pd.DataFrame:
    """One row per Details[] line, joined with parent SalesOrder header fields."""
    customer_class_map = customer_class_map or {}
    synced_at = synced_at or datetime.now(timezone.utc).isoformat()
    today = date.today()

    rows: list[dict] = []

    for order in raw_orders:
        order_nbr = str_val(order.get("OrderNbr"))
        order_date = str_val(order.get("Date"))
        status = str_val(order.get("Status")) or ""
        customer_id = str_val(order.get("CustomerID"))
        customer_name = str_val(order.get("CustomerName"))
        customer_class = str_val(order.get("CustomerClass")) or customer_class_map.get(customer_id or "", None)
        customer_segment = segments.segment_for_customer_class(customer_class)

        details = order.get("Details")
        detail_list = details.get("value") if isinstance(details, dict) else details
        if not isinstance(detail_list, list):
            continue

        for line in detail_list:
            order_qty = float_val(line.get("OrderQty") or line.get("OrderedQty"))
            cancelled_qty = float_val(line.get("CancelledQty"))
            shipped_qty, _qty_on_shipments, _source = quantities.resolve_shipped_qty(line)
            open_qty = quantities.resolve_open_qty(line, order_qty, shipped_qty, cancelled_qty)
            open_qty_source = "OpenQty" if ("OpenQty" in line or "OpenLineQty" in line) else "derived"

            unit_price = values.resolve_unit_price(line, order_qty)
            completed = bool_val(line.get("Completed"))
            fulfillment_status = derive_line_status(order_qty, shipped_qty, open_qty, cancelled_qty, completed)

            inventory_id = str_val(line.get("InventoryID"))
            description = first_string(
                line, ["TransactionDescr", "Description", "TranDesc", "LineDescription"]
            )
            brand, product_type = brands.classify(description, inventory_id)

            is_active_backorder = quantities.is_active_backorder_line(open_qty, status)
            is_completed_shortfall = (
                status == "Completed" and open_qty <= 0 and order_qty > shipped_qty
            )

            if is_completed_shortfall:
                backorder_value = values.line_value(order_qty - shipped_qty, unit_price)
            else:
                backorder_value = values.line_value(open_qty, unit_price)

            order_value = values.line_value(order_qty, unit_price)
            invoiced_value = values.line_value(shipped_qty, unit_price)

            raw_reason_code = str_val(line.get("ReasonCode"))
            reason_normalized = reasons.normalize_reason(raw_reason_code)

            age_days = None
            if order_date:
                try:
                    order_dt = datetime.fromisoformat(order_date.replace("Z", "+00:00")).date()
                    age_days = (today - order_dt).days
                except ValueError:
                    age_days = None

            reason_missing_exception = is_active_backorder and reasons.is_missing_reason_exception(
                reason_normalized, age_days
            )

            rows.append(
                {
                    "order_nbr": order_nbr,
                    "order_date": order_date,
                    "status": status,
                    "customer_id": customer_id,
                    "customer_name": customer_name,
                    "customer_segment": customer_segment,
                    "inventory_id": inventory_id,
                    "description": description,
                    "brand": brand,
                    "product_type": product_type,
                    "warehouse_id": str_val(line.get("WarehouseID") or line.get("SiteID")),
                    "uom": str_val(line.get("UOM")),
                    "line_nbr": str_val(line.get("LineNbr")),
                    "ordered_qty": order_qty,
                    "shipped_qty": shipped_qty,
                    "open_qty": open_qty,
                    "cancelled_qty": cancelled_qty,
                    "unit_price_ex_vat": unit_price,
                    "order_value": order_value,
                    "invoiced_value": invoiced_value,
                    "backorder_value": backorder_value,
                    "fulfillment_status": fulfillment_status,
                    "reason_code": raw_reason_code,
                    "is_active_backorder": is_active_backorder,
                    "is_completed_shortfall": is_completed_shortfall,
                    "synced_at": synced_at,
                    "open_qty_source": open_qty_source,
                    "flag_missing_price": unit_price <= 0,
                    "flag_missing_inventory_id": not inventory_id,
                    "unit_price_vat": values.vat_amount(unit_price, vat_rate),
                    "unit_price_gross": values.gross_value(unit_price, vat_rate),
                    "price_basis": values.PRICE_BASIS_LABEL,
                    "order_value_vat": values.vat_amount(order_value, vat_rate),
                    "order_value_gross": values.gross_value(order_value, vat_rate),
                    "invoiced_value_vat": values.vat_amount(invoiced_value, vat_rate),
                    "invoiced_value_gross": values.gross_value(invoiced_value, vat_rate),
                    "backorder_value_vat": values.vat_amount(backorder_value, vat_rate),
                    "backorder_value_gross": values.gross_value(backorder_value, vat_rate),
                    "reason_normalized": reason_normalized,
                    "age_days": age_days,
                    "reason_missing_exception": reason_missing_exception,
                }
            )

    if not rows:
        return pd.DataFrame(columns=MINIMUM_COLUMNS)

    return pd.DataFrame(rows, columns=MINIMUM_COLUMNS)


def dedupe_lines(df: pd.DataFrame) -> pd.DataFrame:
    """De-dupe by (order_nbr, line_nbr) when merging date-range + still-open sets (PRD 5.8, GT-06)."""
    if df.empty:
        return df
    return df.drop_duplicates(subset=["order_nbr", "line_nbr"], keep="first")


def flatten_sales_order_headers(raw_orders: list[dict]) -> pd.DataFrame:
    """One row per SalesOrder header (raw_sales_orders_*.csv)."""
    rows = []
    for order in raw_orders:
        rows.append(
            {
                "order_nbr": str_val(order.get("OrderNbr")),
                "order_type": str_val(order.get("OrderType")),
                "status": str_val(order.get("Status")),
                "customer_id": str_val(order.get("CustomerID")),
                "customer_name": str_val(order.get("CustomerName")),
                "date": str_val(order.get("Date")),
                "requested_on": str_val(order.get("RequestedOn")),
                "scheduled_shipment_date": str_val(order.get("ScheduledShipmentDate")),
                "currency_id": str_val(order.get("CurrencyID") or order.get("CuryID")),
                "order_total": float_val(order.get("OrderTotal") or order.get("CuryOrderTotal")),
                "branch": str_val(order.get("Branch")),
            }
        )
    return pd.DataFrame(rows)
