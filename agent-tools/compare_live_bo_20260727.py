"""Live Acumatica vs local OrderWatch backorder value for 2026-07-27.

Computes sum(OpenQty * UnitPrice) on SalesOrder Details for several windows
and prints comparison to dashboard figure KES 15,440,314.76.
"""
from __future__ import annotations

import os
import sys
import time
from collections import defaultdict
from pathlib import Path

import requests

ROOT = Path(__file__).resolve().parents[1]


def load_env(path: Path) -> dict[str, str]:
    out: dict[str, str] = {}
    if not path.is_file():
        return out
    for raw in path.read_text(encoding="utf-8", errors="ignore").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        v = v.strip().strip('"').strip("'")
        out[k.strip()] = v
    return out


ENV = load_env(ROOT / "backend" / ".env")

BASE = (ENV.get("ACUMATICA_BASE_URL") or "https://kimfay.acumatica.com").rstrip("/")
TOKEN_URL = ENV.get("ACUMATICA_TOKEN_URL") or f"{BASE}/identity/connect/token"
CLIENT_ID = ENV.get("ACUMATICA_CLIENT_ID") or ""
CLIENT_SECRET = ENV.get("ACUMATICA_CLIENT_SECRET") or ""
USERNAME = ENV.get("ACUMATICA_USERNAME") or ""
PASSWORD = ENV.get("ACUMATICA_PASSWORD") or ""
ENDPOINT = ENV.get("ACUMATICA_ENDPOINT") or "IpayV2"
VERSION = ENV.get("ACUMATICA_VERSION") or "22.200.001"
ENTITY = f"{BASE}/entity/{ENDPOINT}/{VERSION}"
PAGE = 50
DASHBOARD = 15_440_314.76
TARGET = "2026-07-27"


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
        timeout=90,
    )
    r.raise_for_status()
    return r.json()["access_token"]


def fetch_orders(token: str, filt: str, label: str) -> list[dict]:
    headers = {"Authorization": f"Bearer {token}"}
    all_rows: list[dict] = []
    skip = 0
    print(f"\n=== Live fetch: {label} ===")
    print(f"filter: {filt}")
    while True:
        params = {
            "$filter": filt,
            "$top": PAGE,
            "$skip": skip,
            "$expand": "Details",
            "$select": "OrderNbr,Status,Date,CustomerID,OrderTotal,OrderType",
        }
        resp = requests.get(
            f"{ENTITY}/SalesOrder", headers=headers, params=params, timeout=180
        )
        print(f"  skip={skip} -> HTTP {resp.status_code}")
        if resp.status_code != 200:
            print(resp.text[:800])
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
    print(f"  orders returned: {len(all_rows)}")
    return all_rows


def summarize(orders: list[dict]) -> dict:
    total_open = 0.0
    total_ordered_open_lines = 0.0
    open_lines = 0
    orders_with_open = 0
    skus = set()
    by_status: dict[str, float] = defaultdict(float)
    sample = []

    for o in orders:
        details = o.get("Details") or []
        order_open = 0.0
        any_open = False
        for d in details:
            open_q = float(val(d, "OpenQty") or 0)
            price = float(val(d, "UnitPrice") or val(d, "CuryUnitPrice") or 0)
            if open_q <= 0:
                continue
            any_open = True
            line_val = open_q * price
            order_open += line_val
            total_open += line_val
            total_ordered_open_lines += float(val(d, "OrderQty") or 0) * price
            open_lines += 1
            inv = val(d, "InventoryID")
            if inv:
                skus.add(str(inv))
        if any_open:
            orders_with_open += 1
            st = str(val(o, "Status") or "?")
            by_status[st] += order_open
            if len(sample) < 8:
                sample.append(
                    {
                        "order": val(o, "OrderNbr"),
                        "status": st,
                        "date": str(val(o, "Date") or "")[:10],
                        "open_value": round(order_open, 2),
                        "customer": val(o, "CustomerID"),
                    }
                )

    return {
        "orders_fetched": len(orders),
        "orders_with_open_qty": orders_with_open,
        "open_lines": open_lines,
        "skus": len(skus),
        "open_value_openqty_x_price": round(total_open, 2),
        "delta_vs_dashboard": round(total_open - DASHBOARD, 2),
        "pct_vs_dashboard": round((total_open / DASHBOARD - 1) * 100, 2) if DASHBOARD else None,
        "by_status_open_value": {k: round(v, 2) for k, v in sorted(by_status.items())},
        "sample_orders": sample,
    }


def main() -> None:
    if not CLIENT_ID or not CLIENT_SECRET:
        print("Missing Acumatica credentials in backend/.env")
        sys.exit(1)

    print(f"Dashboard target: KES {DASHBOARD:,.2f}")
    print(f"Endpoint: {ENTITY}")
    print("Auth...")
    token = get_token()
    print("OK")

    scenarios = [
        (
            "A) Status=Back Order, Order Date = 2026-07-27 only",
            f"OrderType eq 'SO' and Status eq 'Back Order' "
            f"and Date ge datetimeoffset'{TARGET}T00:00:00' "
            f"and Date le datetimeoffset'{TARGET}T23:59:59'",
        ),
        (
            "B) Status=Back Order, Order Date MTD 2026-07-01..2026-07-27",
            "OrderType eq 'SO' and Status eq 'Back Order' "
            "and Date ge datetimeoffset'2026-07-01T00:00:00' "
            f"and Date le datetimeoffset'{TARGET}T23:59:59'",
        ),
        (
            "C) ALL Status=Back Order (no date — live open book)",
            "OrderType eq 'SO' and Status eq 'Back Order'",
        ),
        (
            "D) Status in (Open, Back Order, Shipping), Order Date = 2026-07-27, sum OpenQty*Price",
            f"OrderType eq 'SO' and (Status eq 'Back Order' or Status eq 'Open' or Status eq 'Shipping') "
            f"and Date ge datetimeoffset'{TARGET}T00:00:00' "
            f"and Date le datetimeoffset'{TARGET}T23:59:59'",
        ),
    ]

    results = []
    for label, filt in scenarios:
        try:
            orders = fetch_orders(token, filt, label)
            summary = summarize(orders)
            summary["label"] = label
            results.append(summary)
            print(json_dump(summary))
        except Exception as e:
            print(f"FAILED {label}: {e}")

    print("\n========== SUMMARY ==========")
    print(f"{'Scenario':62} {'Open value':>16} {'Δ vs dash':>14}")
    for r in results:
        print(
            f"{r['label'][:62]:62} "
            f"{r['open_value_openqty_x_price']:>16,.2f} "
            f"{r['delta_vs_dashboard']:>+14,.2f}"
        )
    print(f"{'Dashboard (user)':62} {DASHBOARD:>16,.2f}")


def json_dump(obj) -> str:
    import json

    return json.dumps(obj, indent=2, default=str)


if __name__ == "__main__":
    main()
