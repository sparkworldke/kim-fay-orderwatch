"""Fetch live Acumatica SalesOrders and compare unbilled totals to Excel exports."""
from __future__ import annotations

import json
import time
from collections import defaultdict
from datetime import date
from pathlib import Path

import requests

BASE = "https://kimfay.acumatica.com"
TOKEN_URL = f"{BASE}/identity/connect/token"
CLIENT_ID = "B86BC41A-1183-A796-BD0E-64DB1C8F8103@Kim-Fay Limited"
CLIENT_SECRET = "RaRhquF5o-e4bO5x2cU9Nw"
USERNAME = "ipay"
PASSWORD = "DN3!724@Nms"
ENTITY = f"{BASE}/entity/IpayV2/22.200.001"
PAGE = 100


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


def fetch_sales_orders(
    token: str,
    date_from: str,
    date_to: str,
    *,
    status: str | None = None,
    order_type: str = "SO",
) -> list[dict]:
    headers = {"Authorization": f"Bearer {token}"}
    filt = (
        f"OrderType eq '{order_type}' "
        f"and Date ge datetimeoffset'{date_from}T00:00:00' "
        f"and Date le datetimeoffset'{date_to}T23:59:59'"
    )
    if status:
        filt += f" and Status eq '{status}'"

    all_rows: list[dict] = []
    skip = 0
    while True:
        params = {
            "$filter": filt,
            "$top": PAGE,
            "$skip": skip,
            # Avoid Details for speed on header unbilled reconciliation
        }
        resp = requests.get(
            f"{ENTITY}/SalesOrder", headers=headers, params=params, timeout=120
        )
        print(f"  page skip={skip} status={resp.status_code}")
        if resp.status_code != 200:
            print("  ERROR:", resp.text[:500])
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


def summarize(orders: list[dict], label: str) -> dict:
    by_status: dict[str, list] = defaultdict(list)
    unbilled_keys_found: set[str] = set()
    total_fields_sample: dict[str, object] = {}

    rows = []
    for o in orders:
        nbr = val(o, "OrderNbr")
        st = val(o, "Status")
        # Try multiple possible unbilled field names on IpayV2
        candidates = {
            "UnbilledOrderTotal": val(o, "UnbilledOrderTotal"),
            "UnbilledAmount": val(o, "UnbilledAmount"),
            "CuryUnbilledOrderTotal": val(o, "CuryUnbilledOrderTotal"),
            "CuryUnbilledAmount": val(o, "CuryUnbilledAmount"),
            "OrderTotal": val(o, "OrderTotal"),
            "CuryOrderTotal": val(o, "CuryOrderTotal"),
            "OpenOrderTotal": val(o, "OpenOrderTotal"),
            "CuryOpenOrderTotal": val(o, "CuryOpenOrderTotal"),
            "UnpaidBalance": val(o, "UnpaidBalance"),
            "CuryUnpaidBalance": val(o, "CuryUnpaidBalance"),
        }
        for k, v in candidates.items():
            if v is not None:
                unbilled_keys_found.add(k)
        if not total_fields_sample and nbr:
            # dump all scalar-like fields once
            for k, v in o.items():
                if isinstance(v, dict) and "value" in v:
                    total_fields_sample[k] = v.get("value")

        # Prefer unbilled fields
        unbilled = None
        for k in (
            "UnbilledOrderTotal",
            "CuryUnbilledOrderTotal",
            "UnbilledAmount",
            "CuryUnbilledAmount",
        ):
            if candidates[k] is not None:
                unbilled = float(candidates[k])
                break

        order_total = None
        for k in ("OrderTotal", "CuryOrderTotal"):
            if candidates[k] is not None:
                order_total = float(candidates[k])
                break

        by_status[str(st or "?")].append(nbr)
        rows.append(
            {
                "order": nbr,
                "status": st,
                "date": val(o, "Date"),
                "customer": val(o, "CustomerID"),
                "unbilled": unbilled,
                "order_total": order_total,
                "candidates": {k: v for k, v in candidates.items() if v is not None},
            }
        )

    # Distinct unbilled sum (one per order nbr)
    unbilled_by_order: dict[str, float] = {}
    missing_unbilled = 0
    for r in rows:
        if not r["order"]:
            continue
        if r["unbilled"] is None:
            missing_unbilled += 1
            continue
        unbilled_by_order[r["order"]] = r["unbilled"]

    summary = {
        "label": label,
        "row_count": len(orders),
        "distinct_orders": len({r["order"] for r in rows if r["order"]}),
        "status_counts": {k: len(v) for k, v in by_status.items()},
        "fields_present": sorted(unbilled_keys_found),
        "sum_unbilled_distinct": round(sum(unbilled_by_order.values()), 2),
        "orders_missing_unbilled": missing_unbilled,
        "sample_fields": {
            k: total_fields_sample[k]
            for k in sorted(total_fields_sample)
            if any(
                x in k.lower()
                for x in (
                    "unbill",
                    "total",
                    "status",
                    "amount",
                    "balance",
                    "open",
                    "date",
                    "order",
                    "qty",
                )
            )
        },
        "unbilled_by_order": unbilled_by_order,
        "rows": rows,
    }
    return summary


def fetch_one(token: str, order_type: str, order_nbr: str) -> dict | None:
    headers = {"Authorization": f"Bearer {token}"}
    resp = requests.get(
        f"{ENTITY}/SalesOrder/{order_type}/{order_nbr}",
        headers=headers,
        params={"$expand": "Details"},
        timeout=90,
    )
    print(f"  GET {order_type}/{order_nbr} -> {resp.status_code}")
    if resp.status_code != 200:
        print("   ", resp.text[:300])
        return None
    return resp.json()


def main() -> None:
    print("Authenticating...")
    token = get_token()
    print("OK")

    # Probe known Excel orders
    print("\n=== Probe known Excel orders ===")
    for nbr in ("SO367750", "SO367889", "SO367588"):
        o = fetch_one(token, "SO", nbr)
        if not o:
            continue
        print(f"  --- {nbr} ---")
        for k, v in sorted(o.items()):
            if k in ("id", "rowNumber", "note", "_links", "custom", "Details"):
                continue
            if isinstance(v, dict) and "value" in v:
                val_ = v.get("value")
                if val_ is not None and any(
                    x in k.lower()
                    for x in (
                        "unbill",
                        "total",
                        "status",
                        "amount",
                        "balance",
                        "open",
                        "date",
                        "order",
                        "qty",
                        "customer",
                    )
                ):
                    print(f"    {k} = {val_}")
        details = o.get("Details") or []
        print(f"    Details: {len(details)} lines")
        line_open_value = 0.0
        line_order_value = 0.0
        for d in details:
            oq = float(val(d, "OrderQty") or 0)
            open_q = float(val(d, "OpenQty") or 0)
            price = float(val(d, "UnitPrice") or val(d, "CuryUnitPrice") or 0)
            amount = val(d, "Amount") or val(d, "CuryLineTotal") or val(d, "CuryExtPrice")
            line_order_value += float(amount) if amount is not None else oq * price
            line_open_value += open_q * price
            print(
                f"      {val(d,'InventoryID')} OQ={oq} Open={open_q} "
                f"Price={price} Amt={amount}"
            )
        print(f"    derived open_value (OpenQty*UnitPrice): {round(line_open_value,2)}")
        print(f"    derived order_value: {round(line_order_value,2)}")

    # Date windows matching Excel (Jul 2026 around export dates)
    windows = [
        ("2026-07-01", "2026-07-26", None),
        ("2026-07-20", "2026-07-26", None),
        ("2026-07-01", "2026-07-26", "Back Order"),
        ("2026-07-20", "2026-07-26", "Back Order"),
        ("2026-07-26", "2026-07-26", None),
        ("2026-07-25", "2026-07-26", None),
    ]

    summaries = []
    for dfrom, dto, status in windows:
        label = f"{dfrom}..{dto}" + (f" status={status}" if status else " all open-ish")
        print(f"\n=== Fetch SalesOrder {label} ===")
        try:
            orders = fetch_sales_orders(token, dfrom, dto, status=status)
        except Exception as e:
            print("  fetch failed:", e)
            continue
        s = summarize(orders, label)
        summaries.append(s)
        print(f"  rows={s['row_count']} distinct={s['distinct_orders']}")
        print(f"  statuses={s['status_counts']}")
        print(f"  fields_present={s['fields_present']}")
        print(f"  sum_unbilled_distinct={s['sum_unbilled_distinct']}")
        print(f"  missing_unbilled={s['orders_missing_unbilled']}")
        if s["sample_fields"]:
            print(f"  sample_fields keys: {list(s['sample_fields'].keys())[:30]}")

    # Also try Status eq 'Backorder' without space (common Acumatica internal)
    print("\n=== Fetch Backorder (no space) 2026-07-01..2026-07-26 ===")
    orders = fetch_sales_orders(token, "2026-07-01", "2026-07-26", status="Backorder")
    s = summarize(orders, "Backorder no-space")
    print(
        f"  rows={s['row_count']} distinct={s['distinct_orders']} "
        f"unbilled={s['sum_unbilled_distinct']} statuses={s['status_counts']}"
    )

    out = Path(__file__).with_name("acumatica_verify_unbilled_out.json")
    # shrink for dump
    dump = []
    for s in summaries + [s]:
        dump.append(
            {
                "label": s["label"],
                "row_count": s["row_count"],
                "distinct_orders": s["distinct_orders"],
                "status_counts": s["status_counts"],
                "fields_present": s["fields_present"],
                "sum_unbilled_distinct": s["sum_unbilled_distinct"],
                "orders_missing_unbilled": s["orders_missing_unbilled"],
                "sample_fields": s.get("sample_fields"),
                "top_orders": sorted(
                    (
                        {"order": o, "unbilled": v}
                        for o, v in s["unbilled_by_order"].items()
                    ),
                    key=lambda x: -x["unbilled"],
                )[:15],
            }
        )
    out.write_text(json.dumps(dump, indent=2, default=str), encoding="utf-8")
    print(f"\nWrote {out}")


if __name__ == "__main__":
    main()
