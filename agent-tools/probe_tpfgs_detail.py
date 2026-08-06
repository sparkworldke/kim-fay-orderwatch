"""Deep dive: TPFGS qty field shape + SO line presence."""
from __future__ import annotations

import json

import requests

BASE = "https://kimfay.acumatica.com"
TOKEN_URL = f"{BASE}/identity/connect/token"
ENTITY = f"{BASE}/entity/IpayV2/22.200.001"


def val(obj, key, default=None):
    if obj is None:
        return default
    v = obj.get(key)
    if isinstance(v, dict) and "value" in v:
        return v.get("value")
    return v if v is not None else default


def main() -> None:
    r = requests.post(
        TOKEN_URL,
        data={
            "grant_type": "password",
            "client_id": "B86BC41A-1183-A796-BD0E-64DB1C8F8103@Kim-Fay Limited",
            "client_secret": "RaRhquF5o-e4bO5x2cU9Nw",
            "username": "ipay",
            "password": "DN3!724@Nms",
            "scope": "api",
        },
        timeout=60,
    )
    r.raise_for_status()
    h = {"Authorization": f"Bearer {r.json()['access_token']}"}

    found = None
    for skip in range(0, 300, 50):
        resp = requests.get(
            f"{ENTITY}/StockItem",
            headers=h,
            params={"$top": 50, "$skip": skip, "$expand": "WarehouseDetails"},
            timeout=90,
        )
        data = resp.json()
        if not data:
            break
        for item in data:
            for d in item.get("WarehouseDetails") or []:
                if str(val(d, "WarehouseID") or "").upper() == "TPFGS":
                    found = {"inventory_id": val(item, "InventoryID"), "detail": d}
                    break
            if found:
                break
        if found:
            break

    print("=== Full TPFGS WarehouseDetails row ===")
    print(json.dumps(found, indent=2, default=str)[:4000])

    nonzero = 0
    total_tpfgs = 0
    examples = []
    for skip in range(0, 400, 50):
        resp = requests.get(
            f"{ENTITY}/StockItem",
            headers=h,
            params={"$top": 50, "$skip": skip, "$expand": "WarehouseDetails"},
            timeout=90,
        )
        data = resp.json()
        if not isinstance(data, list) or not data:
            break
        for item in data:
            for d in item.get("WarehouseDetails") or []:
                if str(val(d, "WarehouseID") or "").upper() != "TPFGS":
                    continue
                total_tpfgs += 1
                fields = {
                    k: val(d, k)
                    for k in d.keys()
                    if "Qty" in k or "Available" in k or "OnHand" in k
                }
                qoh = val(d, "QtyOnHand")
                if (isinstance(qoh, (int, float)) and qoh != 0) or any(
                    isinstance(v, (int, float)) and v != 0 for v in fields.values()
                ):
                    nonzero += 1
                    if len(examples) < 8:
                        examples.append({"id": val(item, "InventoryID"), "fields": fields})

    print("\nTPFGS detail rows scanned:", total_tpfgs)
    print("with non-zero qty fields:", nonzero)
    print("examples:", json.dumps(examples, indent=2))

    print("\n=== SO lines warehouse distribution (100 recent SO) ===")
    tpfgs_so = 0
    whs: dict[str, int] = {}
    for skip in range(0, 100, 20):
        resp = requests.get(
            f"{ENTITY}/SalesOrder",
            headers=h,
            params={
                "$top": 20,
                "$skip": skip,
                "$filter": "OrderType eq 'SO' and Date ge datetimeoffset'2026-06-01T00:00:00'",
                "$expand": "Details",
            },
            timeout=120,
        )
        data = resp.json()
        if not isinstance(data, list) or not data:
            break
        for so in data:
            for d in so.get("Details") or []:
                wid = str(val(d, "WarehouseID") or "")
                if not wid:
                    continue
                whs[wid] = whs.get(wid, 0) + 1
                if wid.upper() == "TPFGS":
                    tpfgs_so += 1
                    if tpfgs_so <= 8:
                        print(
                            "  SO",
                            val(so, "OrderNbr"),
                            val(d, "InventoryID"),
                            "qty",
                            val(d, "OrderQty"),
                        )
    print("SO warehouses:", dict(sorted(whs.items(), key=lambda x: -x[1])))
    print("TPFGS SO lines:", tpfgs_so)

    # Related TP warehouses visibility
    print("\n=== Related TP* warehouses on StockItem details (200 items) ===")
    tp_whs: dict[str, int] = {}
    for skip in range(0, 200, 50):
        resp = requests.get(
            f"{ENTITY}/StockItem",
            headers=h,
            params={"$top": 50, "$skip": skip, "$expand": "WarehouseDetails"},
            timeout=90,
        )
        data = resp.json()
        if not isinstance(data, list) or not data:
            break
        for item in data:
            for d in item.get("WarehouseDetails") or []:
                wid = str(val(d, "WarehouseID") or "")
                if wid.upper().startswith("TP"):
                    tp_whs[wid] = tp_whs.get(wid, 0) + 1
    print("TP* warehouses:", tp_whs)


if __name__ == "__main__":
    main()
