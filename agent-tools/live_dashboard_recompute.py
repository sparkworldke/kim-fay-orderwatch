"""Recompute Sight backorder dashboard cards from live Acumatica for a date window.

Mirrors:
- SalesOrderLineFulfillmentDeriver (line mapping + isBackorderLine)
- OperationsController::backordersValueSummary formulas
"""
from __future__ import annotations

import json
import time
from collections import defaultdict
from pathlib import Path

import requests

BASE = "https://kimfay.acumatica.com"
TOKEN_URL = f"{BASE}/identity/connect/token"
CLIENT_ID = "B86BC41A-1183-A796-BD0E-64DB1C8F8103@Kim-Fay Limited"
CLIENT_SECRET = "RaRhquF5o-e4bO5x2cU9Nw"
USERNAME = "ipay"
PASSWORD = "DN3!724@Nms"
ENTITY = f"{BASE}/entity/IpayV2/22.200.001"
PAGE = 50

MANUFACTURED_PREFIXES = (
    "FAY", "SIF", "COS", "TIS", "ULT", "STD", "SHO", "ANT",
    "URI", "TOI", "AIR", "ALK", "DIS", "KLE",
)
TRADING_PREFIXES = (
    "DOV", "REX", "LUX", "HUG", "KOT", "COW", "APT", "BIO",
    "DAB", "ORS", "VAT", "HOB", "DUR", "FEM", "MIS",
    "MSW", "IKO", "CON", "BIG",
)

STATUS_FULLY = "Fully Fulfilled"
STATUS_BO = "Backorders Imported"
STATUS_CANCELLED = "Cancelled"
STATUS_PARTIAL = "Partially Shipped — Backorder Pending"
STATUS_PENDING = "Pending Shipment"
BACKORDER_STATUSES = {STATUS_BO, STATUS_PARTIAL}


def val(obj, key, default=None):
    if obj is None:
        return default
    v = obj.get(key)
    if isinstance(v, dict) and "value" in v:
        return v.get("value")
    return v if v is not None else default


def fval(obj, *keys, default=0.0):
    for k in keys:
        v = val(obj, k)
        if v is None:
            continue
        try:
            return float(v)
        except (TypeError, ValueError):
            continue
    return default


def has_field(obj, key) -> bool:
    return key in obj and obj[key] is not None


def get_token() -> str:
    r = requests.post(
        TOKEN_URL,
        data={
            "grant_type": "password",
            "client_id": CLIENT_ID,
            "client_secret": CLIENT_SECRET,
            "username": USERNAME,
            "password": PASSWORD,
            "scope": "api",
        },
        timeout=60,
    )
    r.raise_for_status()
    return r.json()["access_token"]


def classify_product(inventory_id: str | None) -> str:
    upper = (inventory_id or "").upper().strip()
    for p in TRADING_PREFIXES:
        if upper.startswith(p):
            return "trading"
    for p in MANUFACTURED_PREFIXES:
        if upper.startswith(p):
            return "manufactured"
    return "trading"


def derive_status(order_qty, shipped_qty, open_qty, cancelled_qty, completed) -> str:
    if completed and (open_qty <= 0 or shipped_qty >= order_qty):
        return STATUS_FULLY
    if open_qty <= 0 and shipped_qty >= order_qty and order_qty > 0:
        return STATUS_FULLY
    if open_qty <= 0 and completed:
        return STATUS_FULLY
    if open_qty > 0 and shipped_qty < order_qty:
        return STATUS_BO
    if cancelled_qty > 0 and shipped_qty == 0.0:
        return STATUS_CANCELLED
    if shipped_qty > 0 and open_qty > 0:
        return STATUS_PARTIAL
    return STATUS_PENDING


def is_backorder_line(fulfillment_status: str, open_qty: float, backorder_qty: float) -> bool:
    effective = open_qty if open_qty > 0 else backorder_qty
    if effective <= 0:
        return False
    if fulfillment_status in (STATUS_FULLY, STATUS_CANCELLED):
        return False
    return (
        fulfillment_status in BACKORDER_STATUSES
        or backorder_qty > 0
        or (open_qty > 0 and fulfillment_status == STATUS_PENDING)
    )


def map_line(line: dict) -> dict:
    order_qty = fval(line, "OrderQty", "OrderedQty")
    shipped_explicit = fval(line, "ShippedQty") if has_field(line, "ShippedQty") else None
    cancelled_qty = fval(line, "CancelledQty")
    if has_field(line, "QtyOnShipments"):
        qty_on_shipments = fval(line, "QtyOnShipments")
    else:
        qty_on_shipments = shipped_explicit or 0.0

    if shipped_explicit is not None and shipped_explicit > 0:
        shipped_qty = shipped_explicit
    elif qty_on_shipments > 0:
        shipped_qty = qty_on_shipments
    else:
        shipped_qty = shipped_explicit or 0.0

    # OpenQty: prefer explicit including 0
    if has_field(line, "OpenQty"):
        open_qty = fval(line, "OpenQty")
    else:
        open_qty = max(order_qty - shipped_qty - max(cancelled_qty, 0.0), 0.0)

    completed = bool(val(line, "Completed") or False)
    unit_price = fval(line, "CuryUnitPrice", "UnitPrice", "DiscountedUnitPrice")
    if unit_price <= 0:
        amount = fval(line, "CuryExtPrice", "ExtendedPrice", "ExtPrice", "Amount")
        if amount > 0 and order_qty > 0:
            unit_price = round(amount / order_qty, 4)

    demand = order_qty if order_qty > 0 else 0.0
    shipped_for_fill = shipped_qty if shipped_qty > 0 else qty_on_shipments
    if open_qty > 0:
        backorder_qty = open_qty
    else:
        backorder_qty = max(demand - shipped_for_fill - max(cancelled_qty, 0.0), 0.0)

    status = derive_status(order_qty, shipped_qty, open_qty, cancelled_qty, completed)
    inv = val(line, "InventoryID")

    return {
        "inventory_id": inv,
        "order_qty": order_qty,
        "shipped_qty": shipped_qty,
        "qty_on_shipments": qty_on_shipments,
        "open_qty": open_qty,
        "cancelled_qty": cancelled_qty,
        "backorder_qty": backorder_qty,
        "unit_price": unit_price,
        "fulfillment_status": status,
        "product_segment": classify_product(str(inv) if inv else None),
    }


def fetch_all_orders(token: str, date_from: str, date_to: str) -> list[dict]:
    headers = {"Authorization": f"Bearer {token}"}
    filt = (
        f"OrderType eq 'SO' "
        f"and Date ge datetimeoffset'{date_from}T00:00:00' "
        f"and Date le datetimeoffset'{date_to}T23:59:59'"
    )
    all_rows: list[dict] = []
    skip = 0
    while True:
        params = {
            "$filter": filt,
            "$top": PAGE,
            "$skip": skip,
            "$expand": "Details",
        }
        resp = requests.get(
            f"{ENTITY}/SalesOrder", headers=headers, params=params, timeout=180
        )
        print(f"  skip={skip} -> {resp.status_code}", flush=True)
        if resp.status_code != 200:
            print(resp.text[:400])
            break
        data = resp.json()
        rows = data.get("value", data) if isinstance(data, dict) else data
        if not rows:
            break
        all_rows.extend(rows)
        if len(rows) < PAGE:
            break
        skip += PAGE
        time.sleep(0.35)
    return all_rows


def zero():
    return {"order_value": 0.0, "invoiced_value": 0.0, "backorder_value": 0.0}


def add_bucket(bucket, ov, iv, bv):
    bucket["order_value"] += ov
    bucket["invoiced_value"] += iv
    bucket["backorder_value"] += bv


def main():
    date_from = "2026-07-22"
    date_to = "2026-07-25"
    target = {
        "backorder_value": 13776253.71,
        "invoiced_value": 16976781.52,
        "order_value": 31131528.36,
        "manufactured_bo": 5866073.68,
        "trading_bo": 7910180.03,
        "open_lines": 3000,
        "skus": 290,
        "open_orders": 344,
        "current_outstanding": 30772084.15,
    }

    print("Auth...")
    token = get_token()
    print(f"Fetch SalesOrders {date_from}..{date_to} with Details...")
    orders = fetch_all_orders(token, date_from, date_to)
    print(f"Orders fetched: {len(orders)}")

    # Accumulators
    totals_dash = zero()  # dashboard value_summary formula
    totals_pure_open = zero()  # open_qty * price for bo; same order/invoiced
    totals_rar = zero()  # backorder uses backorder_qty * price (= open when open>0)
    by_product_dash = {"manufactured": zero(), "trading": zero()}
    by_product_pure = {"manufactured": zero(), "trading": zero()}

    open_lines = 0
    skus = set()
    open_orders = set()
    so_status_lines = defaultdict(int)
    so_status_orders = defaultdict(set)
    fulfillment_counts = defaultdict(int)
    identity_gaps = 0.0
    double_sub_lines = 0
    double_sub_value = 0.0

    # Also: pure open on ALL lines (not just isBackorderLine) for reference
    all_open_value = 0.0
    all_open_lines = 0

    # Header Back Order only (Excel-like) open value
    bo_header_open = 0.0
    bo_header_orders = set()
    bo_header_lines = 0

    sample_double = []

    for o in orders:
        nbr = val(o, "OrderNbr")
        status = val(o, "Status") or "?"
        customer = val(o, "CustomerID")
        details = o.get("Details") or []
        order_has_bo_line = False

        for line in details:
            if not isinstance(line, dict):
                continue
            m = map_line(line)
            inv = m["inventory_id"]
            if not inv:
                continue

            oq = m["order_qty"]
            sq = m["shipped_qty"]
            cq = max(0.0, m["cancelled_qty"])
            commit = max(0.0, m["qty_on_shipments"])
            price = max(0.0, m["unit_price"])
            open_q = max(0.0, m["open_qty"])

            if open_q > 0:
                all_open_lines += 1
                all_open_value += open_q * price

            if not is_backorder_line(m["fulfillment_status"], m["open_qty"], m["backorder_qty"]):
                continue

            order_has_bo_line = True
            open_lines += 1
            skus.add(inv)
            open_orders.add(nbr)
            so_status_lines[status] += 1
            so_status_orders[status].add(nbr)
            fulfillment_counts[m["fulfillment_status"]] += 1

            # Dashboard value_summary formula
            net = max(0.0, oq - cq)
            capped_shipped = min(max(sq, 0.0), net)
            open_recalc = max(0.0, net - capped_shipped)
            bo_qty_dash = max(0.0, open_recalc - commit)
            ov = oq * price
            iv = capped_shipped * price
            bv_dash = bo_qty_dash * price
            bv_pure = open_q * price
            bv_rar = max(0.0, m["backorder_qty"]) * price

            if commit > 0 and open_recalc > 0 and abs(commit - capped_shipped) < 1e-6 and bo_qty_dash < open_q - 1e-6:
                double_sub_lines += 1
                double_sub_value += (open_q * price) - bv_dash
                if len(sample_double) < 8:
                    sample_double.append(
                        {
                            "order": nbr,
                            "inv": inv,
                            "order_qty": oq,
                            "shipped": sq,
                            "qty_on_shipments": commit,
                            "open_qty": open_q,
                            "bo_qty_dash": bo_qty_dash,
                            "pure_open_value": round(open_q * price, 2),
                            "dash_bo_value": round(bv_dash, 2),
                        }
                    )

            add_bucket(totals_dash, ov, iv, bv_dash)
            add_bucket(totals_pure_open, ov, iv, bv_pure)
            add_bucket(totals_rar, ov, iv, bv_rar)
            seg = m["product_segment"]
            add_bucket(by_product_dash[seg], ov, iv, bv_dash)
            add_bucket(by_product_pure[seg], ov, iv, bv_pure)

            identity_gaps += ov - iv - bv_dash

            if str(status).lower().strip() == "back order":
                bo_header_lines += 1
                bo_header_orders.add(nbr)
                bo_header_open += open_q * price

        # header-level unbilled for Back Order status orders (even if we already counted lines)
        # already handled via line open

    def rnd(d):
        return {k: round(v, 2) for k, v in d.items()}

    report = {
        "date_window": [date_from, date_to],
        "orders_fetched": len(orders),
        "dashboard_isBackorderLine": {
            "open_lines": open_lines,
            "skus": len(skus),
            "open_orders": len(open_orders),
            "totals_value_summary_formula": rnd(totals_dash),
            "totals_pure_open_qty_x_price": rnd(totals_pure_open),
            "totals_backorder_qty_x_price": rnd(totals_rar),
            "by_product_dash": {k: rnd(v) for k, v in by_product_dash.items()},
            "by_product_pure": {k: rnd(v) for k, v in by_product_pure.items()},
            "identity_gap_order_minus_inv_minus_bo": round(identity_gaps, 2),
            "so_status_line_counts": dict(so_status_lines),
            "so_status_order_counts": {k: len(v) for k, v in so_status_orders.items()},
            "fulfillment_counts": dict(fulfillment_counts),
            "double_subtraction_suspect_lines": double_sub_lines,
            "double_subtraction_value_deflated": round(double_sub_value, 2),
            "sample_double_sub": sample_double,
        },
        "all_lines_open_qty_gt_0": {
            "lines": all_open_lines,
            "open_value": round(all_open_value, 2),
        },
        "header_status_back_order_open_lines": {
            "lines": bo_header_lines,
            "orders": len(bo_header_orders),
            "open_value": round(bo_header_open, 2),
        },
        "target_dashboard": target,
        "deltas_vs_target": {
            "dash_bo": round(totals_dash["backorder_value"] - target["backorder_value"], 2),
            "dash_inv": round(totals_dash["invoiced_value"] - target["invoiced_value"], 2),
            "dash_ord": round(totals_dash["order_value"] - target["order_value"], 2),
            "pure_bo": round(totals_pure_open["backorder_value"] - target["backorder_value"], 2),
            "pure_vs_outstanding": round(
                totals_pure_open["backorder_value"] - target["current_outstanding"], 2
            ),
            "lines": open_lines - target["open_lines"],
            "orders": len(open_orders) - target["open_orders"],
            "skus": len(skus) - target["skus"],
            "mfg_dash": round(
                by_product_dash["manufactured"]["backorder_value"] - target["manufactured_bo"], 2
            ),
            "trd_dash": round(
                by_product_dash["trading"]["backorder_value"] - target["trading_bo"], 2
            ),
            "mfg_pure": round(
                by_product_pure["manufactured"]["backorder_value"] - target["manufactured_bo"], 2
            ),
            "trd_pure": round(
                by_product_pure["trading"]["backorder_value"] - target["trading_bo"], 2
            ),
        },
    }

    out = Path(__file__).with_name("live_dashboard_recompute_out.json")
    out.write_text(json.dumps(report, indent=2, default=str), encoding="utf-8")
    print(json.dumps(report, indent=2, default=str))
    print(f"\nWrote {out}")


if __name__ == "__main__":
    main()
