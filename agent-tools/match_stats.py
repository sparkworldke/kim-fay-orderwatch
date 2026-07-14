import json
from collections import Counter

with open("agent-tools/staff_email_match.json", encoding="utf-8") as f:
    d = json.load(f)

m = d["matches"]
print("org_level", dict(Counter(x.get("org_level") for x in m)))
print("function", dict(Counter(x.get("function_slug") for x in m)))
print("confidence", dict(Counter(x.get("match_confidence") for x in m)))
hi = [x for x in m if x.get("match_confidence") == "high"]
print("high org_level", dict(Counter(x.get("org_level") for x in hi)))
print("high function", dict(Counter(x.get("function_slug") for x in hi)))

keywords = ["steve", "susan", "jane", "purity", "vignesh", "muthoni", "carrefour", "rajdeep", "resham"]
for x in m:
    blob = " ".join(
        str(v).lower()
        for v in [x.get("email"), x.get("display_name"), x.get("staff_name"), x.get("designation")]
        if v
    )
    if any(k in blob for k in keywords):
        print(
            f"{x.get('email')} | {x.get('display_name')} | {x.get('staff_name')} | "
            f"{x.get('employee_number')} | {x.get('org_level')} | {x.get('sector_tags')} | "
            f"{x.get('function_slug')} | {x.get('match_confidence')}"
        )