import json
import re
from difflib import SequenceMatcher

import pandas as pd

def norm_name(s):
    if pd.isna(s):
        return ""
    s = str(s).strip().lower()
    s = re.sub(r"[^a-z\s]", "", s)
    s = re.sub(r"\s+", " ", s)
    return s

def name_tokens(s):
    return set(norm_name(s).split()) - {"", "mr", "mrs", "ms", "dr"}

def similarity(a, b):
    na, nb = norm_name(a), norm_name(b)
    if not na or not nb:
        return 0.0
    if na == nb:
        return 1.0
    ta, tb = name_tokens(a), name_tokens(b)
    if ta and tb and ta == tb:
        return 0.98
    if ta and tb and (ta <= tb or tb <= ta):
        return 0.95
    return SequenceMatcher(None, na, nb).ratio()

def infer_org_level(dept, division, designation, email):
    text = " ".join(
        str(x).lower() for x in [dept, division, designation, email] if pd.notna(x)
    )
    if any(k in text for k in ["chairman", "ceo", "chief executive", "executive"]):
        return "executive"
    if any(k in text for k in ["c-suite", "c suite", "director", "cfo", "coo"]):
        return "c_suite"
    if any(k in text for k in ["head", "hod", "manager"]):
        return "hod"
    if "brand" in text or "brandoperations" in text.replace(" ", ""):
        return "brandsops"
    if any(k in text for k in ["sales", "consultant", "kam", "key account"]):
        return "sales"
    return "gap"

def infer_sector(dept, division, designation):
    text = " ".join(
        str(x).upper() for x in [dept, division, designation] if pd.notna(x)
    )
    sectors = []
    if "GT" in text or "GENERAL TRADE" in text:
        sectors.append("GT")
    if "MT" in text or "MODERN TRADE" in text or "CONSUMER" in text:
        sectors.append("MT")
    if "KP" in text or "KIMFAY PROFESSIONAL" in text or "PROFESSIONAL" in text:
        sectors.append("KP")
    return sectors

def infer_function(dept, division):
    text = " ".join(str(x).lower() for x in [dept, division] if pd.notna(x))
    mapping = {
        "customer service": "customer_service",
        "finance": "finance",
        "marketing": "marketing",
        "procurement": "procurement",
        "production": "production",
        "store": "stores",
        "dispatch": "dispatch",
        "partner brand": "partner_brands",
        "brand": "partner_brands",
        "it": "it",
        "hr": "hr",
        "human resource": "hr",
    }
    for key, slug in mapping.items():
        if key in text:
            return slug
    if "gt" in text:
        return "gt"
    if "mt" in text or "consumer" in text:
        return "mt_consumer_sales"
    if "kp" in text:
        return "kp"
    return "gap"

emails_df = pd.read_excel("Active Email Users(1).xlsx")
stc_df = pd.read_excel("Active staff July 2026- HQ.xlsx", sheet_name="STC", header=0)
perm_df = pd.read_excel("Active staff July 2026- HQ.xlsx", sheet_name="Permanent staff", header=0)

email_col = emails_df.columns[0]
name_col = emails_df.columns[1] if len(emails_df.columns) > 1 else None

staff_rows = []
for _, row in perm_df.iterrows():
    staff_rows.append(
        {
            "source": "permanent",
            "employee_number": str(row.iloc[0]).strip() if pd.notna(row.iloc[0]) else "",
            "staff_name": row.iloc[1],
            "department": row.iloc[2] if len(row) > 2 else None,
            "division": row.iloc[3] if len(row) > 3 else None,
            "designation": row.iloc[4] if len(row) > 4 else None,
        }
    )
for _, row in stc_df.iterrows():
    staff_rows.append(
        {
            "source": "stc",
            "employee_number": str(row.iloc[0]).strip() if pd.notna(row.iloc[0]) else "",
            "staff_name": row.iloc[1],
            "department": row.iloc[2] if len(row) > 2 else None,
            "division": row.iloc[3] if len(row) > 3 else None,
            "designation": None,
        }
    )

staff_df = pd.DataFrame(staff_rows)
staff_df["norm_name"] = staff_df["staff_name"].map(norm_name)

matches = []
gaps_email_only = []
used_staff = set()

for _, erow in emails_df.iterrows():
    email = str(erow[email_col]).strip().lower() if pd.notna(erow[email_col]) else ""
    display_name = erow[name_col] if name_col else None
    if not email:
        continue

    best = None
    best_score = 0.0
    for idx, srow in staff_df.iterrows():
        score = similarity(display_name or "", srow["staff_name"])
        if score > best_score:
            best_score = score
            best = (idx, srow)

    record = {
        "email": email,
        "display_name": display_name,
        "match_score": round(best_score, 3),
        "match_confidence": (
            "high" if best_score >= 0.9 else "medium" if best_score >= 0.75 else "low"
        ),
    }

    if best and best_score >= 0.75:
        idx, srow = best
        used_staff.add(idx)
        record.update(
            {
                "employee_number": srow["employee_number"],
                "staff_name": srow["staff_name"],
                "department": srow["department"],
                "division": srow["division"],
                "designation": srow["designation"],
                "staff_source": srow["source"],
                "org_level": infer_org_level(
                    srow["department"], srow["division"], srow["designation"], email
                ),
                "sector_tags": infer_sector(
                    srow["department"], srow["division"], srow["designation"]
                ),
                "function_slug": infer_function(srow["department"], srow["division"]),
            }
        )
        matches.append(record)
    else:
        record.update(
            {
                "employee_number": None,
                "staff_name": None,
                "department": None,
                "division": None,
                "designation": None,
                "staff_source": None,
                "org_level": infer_org_level(None, None, None, email),
                "sector_tags": [],
                "function_slug": "gap",
                "gap_reason": "no_staff_match",
            }
        )
        gaps_email_only.append(record)
        matches.append(record)

staff_unmatched = []
for idx, srow in staff_df.iterrows():
    if idx not in used_staff:
        staff_unmatched.append(
            {
                "employee_number": srow["employee_number"],
                "staff_name": srow["staff_name"],
                "department": srow["department"],
                "division": srow["division"],
                "designation": srow["designation"],
                "staff_source": srow["source"],
                "gap_reason": "no_email_match",
            }
        )

summary = {
    "email_rows": len(emails_df),
    "staff_rows": len(staff_df),
    "matched_high": sum(1 for m in matches if m.get("match_confidence") == "high"),
    "matched_medium": sum(1 for m in matches if m.get("match_confidence") == "medium"),
    "matched_low": sum(1 for m in matches if m.get("match_confidence") == "low"),
    "email_gaps": len(gaps_email_only),
    "staff_gaps": len(staff_unmatched),
}

out = {
    "summary": summary,
    "matches": matches,
    "email_gaps": gaps_email_only,
    "staff_gaps": staff_unmatched[:50],
    "staff_gaps_total": len(staff_unmatched),
}

with open("agent-tools/staff_email_match.json", "w", encoding="utf-8") as f:
    json.dump(out, f, indent=2, default=str)

match_df = pd.DataFrame(matches)
match_df.to_excel("docs/data/staff_email_match.xlsx", index=False)

gaps_df = pd.DataFrame(gaps_email_only + staff_unmatched)
gaps_df.to_excel("docs/data/staff_email_gaps.xlsx", index=False)

print(json.dumps(summary, indent=2))
print("\nSample high-confidence matches:")
for m in [x for x in matches if x.get("match_confidence") == "high"][:15]:
    print(f"  {m['email']} -> {m.get('staff_name')} ({m.get('employee_number')})")

print("\nEmail gaps (first 10):")
for m in gaps_email_only[:10]:
    print(f"  {m['email']} / {m['display_name']}")