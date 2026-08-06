"""Recompute using open-orders filter (excludes Completed/Cancelled/Rejected)."""
from __future__ import annotations

import json
import time
from collections import defaultdict
from pathlib import Path

import requests

from live_dashboard_recompute import (
    ENTITY,
    PAGE,
    add_bucket,
    get_token,
    is_backorder_line,
    map_line,
    val,
    zero,
)


def rnd(d):
    return {k: round(v, 2) for k, v in d.items()}


def fetch_open_orders(token: str, date_from: str, date_to: str) -> list[dict]:
    headers = {"Authorization": f"Bearer {token}"}
    filt = (
        "OrderType eq 'SO' "
        "and Status ne 'Completed' and Status ne 'Cancelled' "
        "and Status ne 'Canceled' and Status ne 'Rejected' "
        f"and Date ge datetimeoffset'{date_from}T00:00:00' "
        f"and Date le datetimeoffset'{date_to}T23:59:59'"
    )
    all_rows = []
    skip = 0
    while True:
        params = {"$filter": filt, "$top": PAGE, "$skip": skip, "$expand": "Details"}
        resp = requests.get(f"{ENTITY}/SalesOrder", headers=headers, params=params, timeout=180)
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


def run(orders, label, status_allow=None):
    totals_dash = zero()
    totals_pure = zero()
    by_product_dash = {"manufactured": zero(), "trading": zero()}
    by_product_pure = {"manufactured": zero(), "trading": zero()}
    open_lines = 0
    skus = set()
    open_orders = set()
    so_status_lines = defaultdict(int)
    so_status_orders = defaultdict(set)
    double_sub_lines = 0
    double_sub_value = 0.0
    identity = 0.0

    for o in orders:
        nbr = val(o, "OrderNbr")
        status = val(o, "Status") or "?"
        if status_allow is not None and status not in status_allow:
            continue
        for line in o.get("Details") or []:
            if not isinstance(line, dict):
                continue
            m = map_line(line)
            if not m["inventory_id"]:
                continue
            if not is_backorder_line(m["fulfillment_status"], m["open_qty"], m["backorder_qty"]):
                continue

            oq, sq, cq = m["order_qty"], m["shipped_qty"], max(0.0, m["cancelled_qty"])
            commit = max(0.0, m["qty_on_shipments"])
            price = max(0.0, m["unit_price"])
            open_q = max(0.0, m["open_qty"])

            open_lines += 1
            skus.add(m["inventory_id"])
            open_orders.add(nbr)
            so_status_lines[status] += 1
            so_status_orders[status].add(nbr)

            net = max(0.0, oq - cq)
            capped = min(max(sq, 0.0), net)
            open_recalc = max(0.0, net - capped)
            bo_dash = max(0.0, open_recalc - commit)
            ov, iv = oq * price, capped * price
            bv_dash, bv_pure = bo_dash * price, open_q * price

            if commit > 0 and open_recalc > 0 and abs(commit - capped) < 1e-6 and bo_dash + 1e-6 < open_q:
                double_sub_lines += 1
                double_sub_value += bv_pure - bv_dash

            add_bucket(totals_dash, ov, iv, bv_dash)
            add_bucket(totals_pure, ov, iv, bv_pure)
            seg = m["product_segment"]
            add_bucket(by_product_dash[seg], ov, iv, bv_dash)
            add_bucket(by_product_pure[seg], ov, iv, bv_pure)
            identity += ov - iv - bv_dash

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

    out = {
        "label": label,
        "open_lines": open_lines,
        "skus": len(skus),
        "open_orders": len(open_orders),
        "totals_dash": rnd(totals_dash),
        "totals_pure_open": rnd(totals_pure),
        "by_product_dash": {k: rnd(v) for k, v in by_product_dash.items()},
        "by_product_pure": {k: rnd(v) for k, v in by_product_pure.items()},
        "identity_gap": round(identity, 2),
        "so_status_lines": dict(so_status_lines),
        "so_status_orders": {k: len(v) for k, v in so_status_orders.items()},
        "double_sub_lines": double_sub_lines,
        "double_sub_value": round(double_sub_value, 2),
        "delta": {
            "bo_dash": round(totals_dash["backorder_value"] - target["backorder_value"], 2),
            "inv_dash": round(totals_dash["invoiced_value"] - target["invoiced_value"], 2),
            "ord_dash": round(totals_dash["order_value"] - target["order_value"], 2),
            "bo_pure": round(totals_pure["backorder_value"] - target["backorder_value"], 2),
            "bo_pure_vs_outstanding": round(totals_pure["backorder_value"] - target["current_outstanding"], 2),
            "lines": open_lines - target["open_lines"],
            "orders": len(open_orders) - target["open_orders"],
            "skus": len(skus) - target["skus"],
            "mfg_dash": round(by_product_dash["manufactured"]["backorder_value"] - target["manufactured_bo"], 2),
            "trd_dash": round(by_product_dash["trading"]["backorder_value"] - target["trading_bo"], 2),
            "mfg_pure": round(by_product_pure["manufactured"]["backorder_value"] - target["manufactured_bo"], 2),
            "trd_pure": round(by_product_pure["trading"]["backorder_value"] - target["trading_bo"], 2),
        },
    }
    print(json.dumps(out, indent=2))
    return out


def main():
    print("Auth...")
    token = get_token()
    print("Fetch open SOs 2026-07-22..2026-07-25...")
    orders = fetch_open_orders(token, "2026-07-22", "2026-07-25")
    print(f"Open orders fetched: {len(orders)}")

    results = [
        run(orders, "open-filter (no Completed/Cancelled/Rejected)"),
        run(orders, "Shipping + Back Order only", status_allow={"Shipping", "Back Order"}),
        run(orders, "Back Order header only", status_allow={"Back Order"}),
        run(orders, "Shipping only", status_allow={"Shipping"}),
    ]

    u_ord, u_inv, u_bo = 31131528.36, 16976781.52, 13776253.71
    print("\nUser card identity: order - invoiced - backorder =", round(u_ord - u_inv - u_bo, 2))
    print("User outstanding - user backorder card =", round(30772084.15 - u_bo, 2))

    out = Path(__file__).with_name("live_dashboard_recompute_open_out.json")
    out.write_text(json.dumps(results, indent=2), encoding="utf-8")
    print("Wrote", out)


if __name__ == "__main__":
    main()
