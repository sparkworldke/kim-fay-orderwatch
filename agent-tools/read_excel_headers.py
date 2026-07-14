"""Read header row and sample rep codes from the customer Excel export."""
import sys
import zipfile
import xml.etree.ElementTree as ET

XLSX = r"C:\laragon\www\kim-fay-orderwatch\Customers 20260713.xlsx"

NS = {"s": "http://schemas.openxmlformats.org/spreadsheetml/2006/main"}


def _text(cell, shared_strings):
    t = cell.get("t")
    v_el = cell.find("s:v", NS)
    if v_el is None:
        return ""
    v = v_el.text or ""
    if t == "s":
        return shared_strings[int(v)]
    if t == "inlineStr":
        is_el = cell.find("s:is/s:t", NS)
        return is_el.text if is_el is not None else v
    return v


with zipfile.ZipFile(XLSX) as zf:
    shared_strings = []
    if "xl/sharedStrings.xml" in zf.namelist():
        ss_root = ET.fromstring(zf.read("xl/sharedStrings.xml"))
        for si in ss_root.findall("s:si", NS):
            texts = si.findall(".//s:t", NS)
            shared_strings.append("".join(t.text or "" for t in texts))

    ws_root = ET.fromstring(zf.read("xl/worksheets/sheet1.xml"))

rows = list(ws_root.findall(".//s:row", NS))

header_row = rows[0]
headers = [_text(c, shared_strings) for c in header_row.findall("s:c", NS)]
print("HEADERS:", headers)

# Locate key column indices
rep_idx = next((i for i, h in enumerate(headers) if "Rep Code" in h), None)
cust_idx = next((i for i, h in enumerate(headers) if h == "Customer ID"), None)
route_idx = next((i for i, h in enumerate(headers) if h == "Route Code"), None)
route_name_idx = next((i for i, h in enumerate(headers) if h == "Route Name"), None)
zone_idx = next((i for i, h in enumerate(headers) if h == "Zone ID"), None)
czone_idx = next((i for i, h in enumerate(headers) if h == "Customer Zone"), None)

print(f"\nrep_idx={rep_idx}  cust_idx={cust_idx}  route_idx={route_idx}  "
      f"route_name_idx={route_name_idx}  zone_idx={zone_idx}  czone_idx={czone_idx}")
print(f"Total data rows: {len(rows) - 1}")

def row_vals(row_el):
    cells = row_el.findall("s:c", NS)
    # cells may have gaps due to sparse storage; use column letter from 'r' attr
    vals = [""] * len(headers)
    for cell in cells:
        ref = cell.get("r", "")
        col_letters = "".join(c for c in ref if c.isalpha())
        # Convert column letters to 0-based index
        idx = 0
        for ch in col_letters:
            idx = idx * 26 + (ord(ch) - ord("A") + 1)
        idx -= 1
        if idx < len(vals):
            vals[idx] = _text(cell, shared_strings)
    return vals

# Distinct rep codes
rep_codes = set()
for row_el in rows[1:]:
    vals = row_vals(row_el)
    if rep_idx is not None and rep_idx < len(vals) and vals[rep_idx]:
        rep_codes.add(vals[rep_idx])

print(f"\nDistinct rep codes ({len(rep_codes)}):")
for code in sorted(rep_codes):
    print(f"  {code!r}")

print("\nFirst 8 data rows:")
count = 0
for row_el in rows[1:]:
    if count >= 8:
        break
    vals = row_vals(row_el)
    rep = vals[rep_idx] if rep_idx is not None else ""
    cust = vals[cust_idx] if cust_idx is not None else ""
    route = vals[route_idx] if route_idx is not None else ""
    rname = vals[route_name_idx] if route_name_idx is not None else ""
    zone = vals[zone_idx] if zone_idx is not None else ""
    czone = vals[czone_idx] if czone_idx is not None else ""
    if not rep and not cust:
        continue
    print(f"  rep={rep!r}  cust={cust!r}  route={route!r}({rname!r})  zone={zone!r}({czone!r})")
    count += 1

# Route/zone combos sample
print("\nRoute→Zone mapping sample (first 20 unique):")
combos = {}
for row_el in rows[1:]:
    vals = row_vals(row_el)
    route = vals[route_idx] if route_idx is not None and route_idx < len(vals) else ""
    rname = vals[route_name_idx] if route_name_idx is not None and route_name_idx < len(vals) else ""
    zone = vals[zone_idx] if zone_idx is not None and zone_idx < len(vals) else ""
    czone = vals[czone_idx] if czone_idx is not None and czone_idx < len(vals) else ""
    if route and route not in combos:
        combos[route] = (rname, zone, czone)
    if len(combos) >= 20:
        break

for route, (rname, zone, czone) in sorted(combos.items()):
    print(f"  route={route!r}({rname!r}) -> zone={zone!r}({czone!r})")
