"""Parse Sales Orders Excel exports and compute distinct-order unbilled totals."""
from __future__ import annotations

import re
import zipfile
from collections import defaultdict
from datetime import datetime, timedelta
from pathlib import Path
from xml.etree import ElementTree as ET

NS = {"m": "http://schemas.openxmlformats.org/spreadsheetml/2006/main"}


def excel_date(serial) -> str:
    try:
        s = float(serial)
        return (datetime(1899, 12, 30) + timedelta(days=s)).date().isoformat()
    except Exception:
        return str(serial)


def load_shared(z: zipfile.ZipFile) -> list[str]:
    if "xl/sharedStrings.xml" not in z.namelist():
        return []
    root = ET.fromstring(z.read("xl/sharedStrings.xml"))
    out: list[str] = []
    for si in root.findall("m:si", NS):
        texts = [
            t.text or ""
            for t in si.iter(
                "{http://schemas.openxmlformats.org/spreadsheetml/2006/main}t"
            )
        ]
        out.append("".join(texts))
    return out


def col_to_idx(col: str) -> int:
    n = 0
    for ch in col:
        n = n * 26 + (ord(ch) - 64)
    return n - 1


def idx_to_col(i: int) -> str:
    n = i + 1
    s = ""
    while n:
        n, r = divmod(n - 1, 26)
        s = chr(65 + r) + s
    return s


def parse_cell_value(c, shared: list[str]):
    t = c.get("t")
    v = c.find("m:v", NS)
    is_elem = c.find("m:is", NS)
    if t == "s" and v is not None:
        return shared[int(v.text)]
    if t == "inlineStr" and is_elem is not None:
        return "".join(x.text or "" for x in is_elem.findall(".//m:t", NS))
    if v is not None:
        return v.text
    return None


def parse_sheet(path: Path, sheet_idx: int = 0) -> tuple[list, list[dict]]:
    z = zipfile.ZipFile(path)
    shared = load_shared(z)
    sheets = sorted(n for n in z.namelist() if n.startswith("xl/worksheets/sheet"))
    root = ET.fromstring(z.read(sheets[sheet_idx]))
    rows_data: list[dict] = []
    for row in root.findall("m:sheetData/m:row", NS):
        cells: dict[str, object] = {}
        col_i = 0
        for c in row.findall("m:c", NS):
            ref = c.get("r")
            if ref:
                m = re.match(r"([A-Z]+)", ref or "")
                if m:
                    col = m.group(1)
                    col_i = col_to_idx(col)
                else:
                    col = idx_to_col(col_i)
            else:
                col = idx_to_col(col_i)
            cells[col] = parse_cell_value(c, shared)
            col_i += 1
        rows_data.append(cells)
    z.close()
    if not rows_data:
        return [], []
    header_row = rows_data[0]
    max_col = max(col_to_idx(c) for c in header_row) if header_row else 0
    headers = [header_row.get(idx_to_col(i)) for i in range(max_col + 1)]
    records: list[dict] = []
    for cells in rows_data[1:]:
        rec = {}
        for i, h in enumerate(headers):
            if not h:
                continue
            rec[h] = cells.get(idx_to_col(i))
        if any(v is not None and str(v).strip() != "" for v in rec.values()):
            records.append(rec)
    return headers, records


def analyze(path: Path) -> dict:
    headers, recs = parse_sheet(path)
    orders: set[str] = set()
    statuses: dict[str, int] = defaultdict(int)
    unbilled_by_order: dict[str, float] = {}
    unbilled_sum_rows = 0.0
    line_total_sum = 0.0
    order_dates: set[str] = set()
    created_dates: set[str] = set()
    conflicts = 0
    rows_detail = []

    for r in recs:
        on = r.get("Order Nbr.")
        if on:
            orders.add(str(on))
        st = r.get("Status") or "?"
        statuses[st] += 1
        try:
            ubf = float(r.get("Unbilled Order Total") or 0)
        except (TypeError, ValueError):
            ubf = 0.0
        try:
            ltf = float(r.get("Line Total") or 0)
        except (TypeError, ValueError):
            ltf = 0.0
        unbilled_sum_rows += ubf
        line_total_sum += ltf
        if on is not None:
            on = str(on)
            if on not in unbilled_by_order:
                unbilled_by_order[on] = ubf
            elif abs(unbilled_by_order[on] - ubf) > 0.01:
                conflicts += 1
        if r.get("Order Date"):
            order_dates.add(excel_date(r.get("Order Date")))
        if r.get("Created On"):
            created_dates.add(excel_date(r.get("Created On")))
        rows_detail.append(
            {
                "order": on,
                "status": st,
                "unbilled": ubf,
                "line_total": ltf,
                "order_date": excel_date(r.get("Order Date")) if r.get("Order Date") else None,
                "customer": r.get("Customer"),
                "customer_name": r.get("Customer Name"),
            }
        )

    by_status_orders: dict[str, dict[str, float]] = defaultdict(dict)
    for r in recs:
        on = r.get("Order Nbr.")
        st = r.get("Status") or "?"
        if not on:
            continue
        on = str(on)
        try:
            ub = float(r.get("Unbilled Order Total") or 0)
        except (TypeError, ValueError):
            ub = 0.0
        if on not in by_status_orders[st]:
            by_status_orders[st][on] = ub

    return {
        "file": path.name,
        "exported_rows": len(recs),
        "distinct_orders": len(orders),
        "statuses": dict(statuses),
        "sum_unbilled_all_rows": round(unbilled_sum_rows, 2),
        "sum_unbilled_distinct_order": round(sum(unbilled_by_order.values()), 2),
        "sum_line_total_all_rows": round(line_total_sum, 2),
        "unbilled_conflicts": conflicts,
        "min_order_date": min(order_dates) if order_dates else None,
        "max_order_date": max(order_dates) if order_dates else None,
        "min_created": min(created_dates) if created_dates else None,
        "max_created": max(created_dates) if created_dates else None,
        "by_status": {
            st: {
                "distinct_orders": len(d),
                "unbilled": round(sum(d.values()), 2),
            }
            for st, d in by_status_orders.items()
        },
        "orders_with_unbilled_3500": [
            o for o, v in unbilled_by_order.items() if abs(v - 3500) < 0.01
        ],
        "unbilled_by_order": unbilled_by_order,
        "rows": rows_detail,
    }


def main() -> None:
    paths = [
        Path(r"c:\laragon\www\kim-fay-orderwatch\backorder-excel-docs\Sales Orders 20260726.xlsx"),
        Path(r"c:\laragon\www\kim-fay-orderwatch\backorder-excel-docs\Sales Orders 202607261.xlsx"),
    ]
    results = []
    for p in paths:
        a = analyze(p)
        results.append(a)
        print(f"=== {a['file']} ===")
        for k in [
            "exported_rows",
            "distinct_orders",
            "statuses",
            "sum_unbilled_all_rows",
            "sum_unbilled_distinct_order",
            "sum_line_total_all_rows",
            "unbilled_conflicts",
            "min_order_date",
            "max_order_date",
            "min_created",
            "max_created",
            "by_status",
            "orders_with_unbilled_3500",
        ]:
            print(f"  {k}: {a[k]}")
        print()

    # Combined distinct across both files
    combined: dict[str, float] = {}
    for a in results:
        for o, v in a["unbilled_by_order"].items():
            # keep first file's value; note if differs
            if o not in combined:
                combined[o] = v
    print("=== COMBINED (distinct across both files) ===")
    print("  distinct orders:", len(combined))
    print("  sum unbilled distinct:", round(sum(combined.values()), 2))

    # Intersection / diffs
    a0 = set(results[0]["unbilled_by_order"])
    a1 = set(results[1]["unbilled_by_order"])
    print("  only in 20260726:", sorted(a0 - a1)[:20], f"(count {len(a0-a1)})")
    print("  only in 202607261:", sorted(a1 - a0)[:20], f"(count {len(a1-a0)})")
    print("  intersection:", len(a0 & a1))


if __name__ == "__main__":
    main()
