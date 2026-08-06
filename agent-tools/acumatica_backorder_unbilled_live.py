"""Compute live 'Unbilled Order Total' proxy from Acumatica Back Order SOs.

IpayV2 SalesOrder does not expose UnbilledOrderTotal. Excel GI unbilled for
SO367750/SO367588 matches sum(OpenQty * UnitPrice) over Details, so we use that.
"""
from __future__ import annotations

import json
import time
from collections import defaultdict
from pathlib import Path

import requests

# Reuse excel parser
import sys

sys.path.insert(0, str(Path(__file__).parent))
from excel_unbilled_stats import analyze  # noqa: E402

BASE = "https://kimfay.acumatica.com"
TOKEN_URL = f"{BASE}/identity/connect/token"
CLIENT_ID = "B86BC41A-1183-A796-BD0E-64DB1C8F8103@Kim-Fay Limited"
CLIENT_SECRET = "RaRhquF5o-e4bO5x2cU9Nw"
USERNAME = "ipay"
PASSWORD = "DN3!724@Nms"
ENTITY = f"{BASE}/entity/IpayV2/22.200.001"
PAGE = 50


def val(obj, key, default=None):
    if obj is None:
        return default
    v = obj.get(key)
    if isinstance(v, dict) and "value" in v:
        return v.get("value")
    return v if v is not None else default


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


def fetch_back_orders(token: str, date_from: str, date_to: str) -> list[dict]:
    headers = {"Authorization": f"Bearer {token}"}
    filt = (
        f"OrderType eq 'SO' and Status eq 'Back Order' "
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
        print(f"  page skip={skip} -> {resp.status_code}")
        if resp.status_code != 200:
            print(resp.text[:500])
            break
        data = resp.json()
        rows = data.get("value", data) if isinstance(data, dict) else data
        if not rows:
            break
        all_rows.extend(rows)
        if len(rows) < PAGE:
            break
        skip += PAGE
        time.sleep(0.4)
    return all_rows


def order_open_unbilled(order: dict) -> dict:
    nbr = val(order, "OrderNbr")
    details = order.get("Details") or []
    open_value = 0.0
    ordered_value = 0.0
    open_lines = []
    for d in details:
        oq = float(val(d, "OrderQty") or 0)
        open_q = float(val(d, "OpenQty") or 0)
        price = float(val(d, "UnitPrice") or val(d, "CuryUnitPrice") or 0)
        amt = val(d, "Amount")
        if amt is None:
            amt = val(d, "CuryLineTotal")
        if amt is None:
            amt = oq * price
        else:
            amt = float(amt)
        line_open = open_q * price
        open_value += line_open
        ordered_value += float(amt)
        if open_q > 0:
            open_lines.append(
                {
                    "inventory": val(d, "InventoryID"),
                    "open_qty": open_q,
                    "order_qty": oq,
                    "unit_price": price,
                    "open_value": round(line_open, 4),
                }
            )
    return {
        "order": nbr,
        "status": val(order, "Status"),
        "date": val(order, "Date"),
        "customer": val(order, "CustomerID"),
        "order_total": val(order, "OrderTotal"),
        "vat_taxable_total": val(order, "VATTaxableTotal"),
        "line_count": len(details),
        "open_line_count": len(open_lines),
        "open_unbilled": round(open_value, 2),
        "ordered_line_value": round(ordered_value, 2),
        "open_lines": open_lines,
    }


def main() -> None:
    excel_path = Path(
        r"c:\laragon\www\kim-fay-orderwatch\backorder-excel-docs\Sales Orders 20260726.xlsx"
    )
    excel = analyze(excel_path)
    excel_orders = excel["unbilled_by_order"]
    print(
        f"Excel: {excel['exported_rows']} rows, {excel['distinct_orders']} orders, "
        f"unbilled={excel['sum_unbilled_distinct_order']}"
    )
    print(f"Excel dates: {excel['min_order_date']} .. {excel['max_order_date']}")

    print("\nAuth...")
    token = get_token()

    # Match Excel date window first, then slightly wider for drift
    windows = [
        ("2026-07-22", "2026-07-25"),
        ("2026-07-20", "2026-07-26"),
        ("2026-07-01", "2026-07-26"),
    ]

    report = {"excel": {
        "exported_rows": excel["exported_rows"],
        "distinct_orders": excel["distinct_orders"],
        "sum_unbilled_distinct_order": excel["sum_unbilled_distinct_order"],
        "date_range": [excel["min_order_date"], excel["max_order_date"]],
    }, "windows": {}}

    for dfrom, dto in windows:
        print(f"\n=== Live Back Order {dfrom}..{dto} with Details ===")
        orders = fetch_back_orders(token, dfrom, dto)
        computed = [order_open_unbilled(o) for o in orders]
        by_order = {c["order"]: c for c in computed if c["order"]}
        live_sum = round(sum(c["open_unbilled"] for c in computed), 2)

        excel_set = set(excel_orders)
        live_set = set(by_order)
        only_excel = sorted(excel_set - live_set)
        only_live = sorted(live_set - excel_set)
        both = sorted(excel_set & live_set)

        # Compare unbilled for matching orders
        diffs = []
        match_count = 0
        for o in both:
            e = excel_orders[o]
            l = by_order[o]["open_unbilled"]
            delta = round(l - e, 2)
            if abs(delta) <= 0.02:
                match_count += 1
            else:
                diffs.append({"order": o, "excel": e, "live_open": l, "delta": delta})

        # Sum of live unbilled for orders that are in excel only
        live_for_excel_orders = round(
            sum(by_order[o]["open_unbilled"] for o in both), 2
        )
        excel_for_live_matched = round(sum(excel_orders[o] for o in both), 2)

        print(f"  live BO count: {len(computed)}")
        print(f"  live open_unbilled sum (all BO in window): {live_sum}")
        print(f"  intersection with excel: {len(both)}")
        print(f"  unbilled match within 0.02: {match_count}/{len(both)}")
        print(f"  live sum on intersection: {live_for_excel_orders}")
        print(f"  excel sum on intersection: {excel_for_live_matched}")
        print(f"  only in excel ({len(only_excel)}): {only_excel[:15]}")
        print(f"  only in live  ({len(only_live)}): {only_live[:15]}")
        if diffs:
            print(f"  value diffs ({len(diffs)}):")
            for d in sorted(diffs, key=lambda x: -abs(x["delta"]))[:15]:
                print(f"    {d}")

        # SO367750 special case (3500)
        if "SO367750" in by_order:
            c = by_order["SO367750"]
            print(
                f"  SO367750 live open_unbilled={c['open_unbilled']} "
                f"excel={excel_orders.get('SO367750')} open_lines={c['open_lines']}"
            )

        report["windows"][f"{dfrom}..{dto}"] = {
            "live_bo_count": len(computed),
            "live_open_unbilled_sum": live_sum,
            "intersection_count": len(both),
            "unbilled_match_count": match_count,
            "live_sum_intersection": live_for_excel_orders,
            "excel_sum_intersection": excel_for_live_matched,
            "only_excel": only_excel,
            "only_live": only_live,
            "value_diffs": diffs,
            "orders": [
                {
                    "order": c["order"],
                    "date": c["date"],
                    "customer": c["customer"],
                    "open_unbilled": c["open_unbilled"],
                    "excel_unbilled": excel_orders.get(c["order"]),
                    "open_line_count": c["open_line_count"],
                }
                for c in sorted(computed, key=lambda x: x["order"] or "")
            ],
        }

    out = Path(__file__).with_name("acumatica_backorder_unbilled_live_out.json")
    out.write_text(json.dumps(report, indent=2, default=str), encoding="utf-8")
    print(f"\nWrote {out}")


if __name__ == "__main__":
    main()
