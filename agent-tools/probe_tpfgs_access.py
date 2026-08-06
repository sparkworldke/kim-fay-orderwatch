"""Probe whether iPay credentials can see warehouse TPFGS in Acumatica IpayV2."""
from __future__ import annotations

import json
from collections import Counter

import requests

BASE = "https://kimfay.acumatica.com"
TOKEN_URL = f"{BASE}/identity/connect/token"
CLIENT_ID = "B86BC41A-1183-A796-BD0E-64DB1C8F8103@Kim-Fay Limited"
CLIENT_SECRET = "RaRhquF5o-e4bO5x2cU9Nw"
USERNAME = "ipay"
PASSWORD = "DN3!724@Nms"
ENTITY = f"{BASE}/entity/IpayV2/22.200.001"


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
    print("auth status:", r.status_code)
    r.raise_for_status()
    return r.json()["access_token"]


def probe(headers, path, params=None, label=None):
    label = label or path
    try:
        resp = requests.get(
            f"{ENTITY}/{path}",
            headers=headers,
            params=params or {},
            timeout=90,
        )
        print(f"--- {label} -> {resp.status_code} ---")
        if resp.status_code == 200:
            data = resp.json()
            if isinstance(data, list):
                print(f"  rows: {len(data)}")
            elif isinstance(data, dict):
                print(f"  dict keys: {list(data.keys())[:12]}")
            return resp.status_code, data
        print("  body:", resp.text[:500])
        return resp.status_code, None
    except Exception as e:
        print(f"--- {label} ERROR: {e} ---")
        return None, None


def main() -> None:
    print("=== 1. AUTH (ipay / IpayV2) ===")
    token = get_token()
    headers = {"Authorization": f"Bearer {token}"}
    print("auth OK")

    print("\n=== 2. WAREHOUSE ENTITY ===")
    for ent in ["Warehouse", "warehouse", "INSite", "Site"]:
        status, data = probe(headers, ent, {"$top": 100}, label=ent)
        if status == 200 and isinstance(data, list) and data:
            ids = []
            for row in data:
                wid = (
                    val(row, "WarehouseID")
                    or val(row, "SiteID")
                    or val(row, "InventorySiteID")
                    or val(row, "id")
                )
                desc = val(row, "Description") or val(row, "WarehouseName")
                ids.append((str(wid) if wid is not None else "?", desc))
            print("  sample:", ids[:40])
            tp = [i for i in ids if "TP" in str(i[0]).upper() or "TATU" in str(i[1] or "").upper()]
            print("  TP/Tatu matches:", tp)
            tpfgs = [i for i in ids if str(i[0]).upper() == "TPFGS"]
            print("  exact TPFGS:", tpfgs)

    print("\n=== 3. StockItem WarehouseDetails (first 20) ===")
    status, data = probe(
        headers,
        "StockItem",
        {"$top": 20, "$skip": 0, "$expand": "WarehouseDetails"},
        label="StockItem+WarehouseDetails",
    )
    wh_counter: Counter[str] = Counter()
    tpfgs_items = []
    if status == 200 and isinstance(data, list):
        for item in data:
            iid = val(item, "InventoryID")
            for d in item.get("WarehouseDetails") or []:
                wid = val(d, "WarehouseID")
                if wid:
                    wh_counter[str(wid)] += 1
                if wid and str(wid).upper() == "TPFGS":
                    tpfgs_items.append(
                        {
                            "inventory_id": iid,
                            "qty_on_hand": val(d, "QtyOnHand"),
                            "qty_available": val(d, "QtyAvailable") or val(d, "QtyAvail"),
                        }
                    )
        print("  warehouses on first 20 items:", dict(wh_counter.most_common(40)))
        print("  TPFGS detail sample:", tpfgs_items[:5])

    print("\n=== 4. StockItem filter DefaultWarehouseID eq TPFGS ===")
    status, data = probe(
        headers,
        "StockItem",
        {
            "$top": 10,
            "$filter": "DefaultWarehouseID eq 'TPFGS'",
            "$expand": "WarehouseDetails",
        },
        label="StockItem DefaultWarehouse=TPFGS",
    )
    if status == 200 and isinstance(data, list):
        print(f"  items with default WH TPFGS: {len(data)}")
        for item in data[:5]:
            iid = val(item, "InventoryID")
            dwh = val(item, "DefaultWarehouseID")
            whs = [val(d, "WarehouseID") for d in (item.get("WarehouseDetails") or [])]
            print(f"  item={iid} default={dwh} details={whs}")

    print("\n=== 5. Also try FGS for comparison ===")
    status, data = probe(
        headers,
        "StockItem",
        {
            "$top": 3,
            "$filter": "DefaultWarehouseID eq 'FGS'",
            "$expand": "WarehouseDetails",
        },
        label="StockItem DefaultWarehouse=FGS",
    )
    if status == 200 and isinstance(data, list):
        print(f"  items with default WH FGS: {len(data)} (page)")

    print("\n=== 6. Inventory inquiry entities ===")
    for ent in [
        "InventorySummaryInquiry",
        "InventorySummary",
        "WarehouseSummary",
        "ItemWarehouse",
        "InventoryItem",
    ]:
        probe(headers, ent, {"$top": 5}, label=ent)

    print("\n=== 7. SalesOrder+Details warehouse scan (recent SO) ===")
    status, data = probe(
        headers,
        "SalesOrder",
        {
            "$top": 20,
            "$filter": "OrderType eq 'SO' and Date ge datetimeoffset'2026-07-01T00:00:00'",
            "$expand": "Details",
        },
        label="SalesOrder+Details recent",
    )
    so_wh: Counter[str] = Counter()
    tpfgs_lines = []
    if status == 200 and isinstance(data, list):
        for so in data:
            onbr = val(so, "OrderNbr")
            for d in so.get("Details") or []:
                wid = val(d, "WarehouseID")
                if wid:
                    so_wh[str(wid)] += 1
                if wid and str(wid).upper() == "TPFGS":
                    tpfgs_lines.append(
                        {
                            "order": onbr,
                            "inventory": val(d, "InventoryID"),
                            "qty": val(d, "OrderQty"),
                        }
                    )
        print("  warehouses on SO lines:", dict(so_wh.most_common(30)))
        print("  TPFGS lines in sample:", tpfgs_lines[:10], "count=", len(tpfgs_lines))

    print("\n=== 8. Branch entity ===")
    for ent in ["Branch", "Company", "Organization"]:
        status, data = probe(headers, ent, {"$top": 30}, label=ent)
        if status == 200 and isinstance(data, list):
            for row in data[:30]:
                bid = val(row, "BranchID") or val(row, "OrganizationID") or val(row, "CompanyID")
                name = val(row, "BranchName") or val(row, "Name") or val(row, "Description")
                active = val(row, "Active")
                print(f"  id={bid} name={name} active={active}")

    print("\n=== 9. Deeper StockItem scan (up to 200 items) for TPFGS ===")
    wh_all: Counter[str] = Counter()
    tpfgs_detail_count = 0
    tpfgs_examples = []
    for skip in (0, 50, 100, 150):
        status, data = probe(
            headers,
            "StockItem",
            {"$top": 50, "$skip": skip, "$expand": "WarehouseDetails"},
            label=f"StockItem skip={skip}",
        )
        if status != 200 or not isinstance(data, list) or not data:
            break
        for item in data:
            iid = val(item, "InventoryID")
            for d in item.get("WarehouseDetails") or []:
                wid = val(d, "WarehouseID")
                if not wid:
                    continue
                wh_all[str(wid)] += 1
                if str(wid).upper() == "TPFGS":
                    tpfgs_detail_count += 1
                    if len(tpfgs_examples) < 8:
                        tpfgs_examples.append(
                            {
                                "inventory_id": iid,
                                "qty_on_hand": val(d, "QtyOnHand"),
                                "qty_available": val(d, "QtyAvailable") or val(d, "QtyAvail"),
                                "status": val(d, "Status") or val(d, "InventoryStatus"),
                            }
                        )
    print("  all warehouses across scanned items:")
    for wid, cnt in wh_all.most_common(60):
        mark = " << TPFGS" if wid.upper() == "TPFGS" else ""
        print(f"    {wid}: {cnt}{mark}")
    print("  TPFGS WarehouseDetails rows:", tpfgs_detail_count)
    print("  examples:", json.dumps(tpfgs_examples, indent=2))

    # Direct GET by warehouse key if Warehouse entity supports keys
    print("\n=== 10. Direct Warehouse/TPFGS GET ===")
    for path in ["Warehouse/TPFGS", "Warehouse/TPFGS?", "warehouse/TPFGS"]:
        probe(headers, path.rstrip("?"), label=path)

    print("\nDONE")


if __name__ == "__main__":
    main()
