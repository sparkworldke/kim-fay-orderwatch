from pathlib import Path
import requests


def load_env(path: Path) -> dict[str, str]:
    out: dict[str, str] = {}
    for raw in path.read_text(encoding="utf-8", errors="ignore").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        out[k.strip()] = v.strip().strip('"').strip("'")
    return out


ENV = load_env(Path(__file__).resolve().parents[1] / "backend" / ".env")
BASE = ENV["ACUMATICA_BASE_URL"].rstrip("/")
TOKEN_URL = ENV.get("ACUMATICA_TOKEN_URL") or f"{BASE}/identity/connect/token"
r = requests.post(
    TOKEN_URL,
    data={
        "grant_type": "password",
        "client_id": ENV["ACUMATICA_CLIENT_ID"],
        "client_secret": ENV["ACUMATICA_CLIENT_SECRET"],
        "username": ENV["ACUMATICA_USERNAME"],
        "password": ENV["ACUMATICA_PASSWORD"],
        "scope": "api",
    },
    timeout=90,
)
r.raise_for_status()
token = r.json()["access_token"]
ENTITY = f"{BASE}/entity/{ENV.get('ACUMATICA_ENDPOINT', 'IpayV2')}/{ENV.get('ACUMATICA_VERSION', '22.200.001')}"
headers = {"Authorization": f"Bearer {token}"}

resp = requests.get(
    f"{ENTITY}/SalesOrder",
    headers=headers,
    params={
        "$filter": "OrderType eq 'SO' and Status eq 'Back Order'",
        "$top": 2,
        "$expand": "Details",
    },
    timeout=120,
)
print("status", resp.status_code, "len", len(resp.content), "ctype", resp.headers.get("Content-Type"))
print("body_start", repr(resp.text[:300]))
if not resp.text.strip():
    raise SystemExit("empty body")
data = resp.json()
rows = data.get("value", data) if isinstance(data, dict) else data
if isinstance(rows, dict):
    # single entity
    rows = [rows]
print("orders", len(rows) if isinstance(rows, list) else type(rows))
if not rows:
    raise SystemExit(0)

o = rows[0]


def val(obj, key, default=None):
    if obj is None:
        return default
    v = obj.get(key)
    if isinstance(v, dict) and "value" in v:
        return v.get("value")
    return v if v is not None else default


print("OrderNbr", val(o, "OrderNbr"), "Status", val(o, "Status"), "Date", val(o, "Date"))
details = o.get("Details") or []
print("details count", len(details))
if not details:
    raise SystemExit(0)

d = details[0]
print("detail keys", sorted(d.keys()))
for k in [
    "OpenQty",
    "OrderQty",
    "QtyOnShipments",
    "ShippedQty",
    "UnitPrice",
    "CuryUnitPrice",
    "InventoryID",
    "UnbilledAmount",
    "OpenAmount",
    "Amount",
    "LineNbr",
]:
    print(f"{k} => {d.get(k)}")

print("qty/open/ship/price-like:")
for k, v in sorted(d.items()):
    kl = k.lower()
    if any(x in kl for x in ("qty", "open", "ship", "price", "amount", "unbill")):
        print(f"  {k}: {v}")

# Sum OpenQty*UnitPrice across all details of first few orders
total = 0.0
lines = 0
for o in rows:
    for d in o.get("Details") or []:
        oq = val(d, "OpenQty")
        price = val(d, "UnitPrice") or val(d, "CuryUnitPrice") or 0
        try:
            oq = float(oq or 0)
            price = float(price or 0)
        except Exception:
            continue
        if oq > 0:
            total += oq * price
            lines += 1
print("sample 2 orders open_value", round(total, 2), "open_lines", lines)
