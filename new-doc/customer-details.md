# KP Customer Details — Profile, CSV Fields & Consultant Mapping

**Sources**

| File | Role |
|---|---|
| `excels/KP-Customers 20260805 Agusst.csv` | Business export (1 000 KP rows) |
| `backend/database/seeders/data/kp-customers-20260805.csv` | Same headers — seeder input |
| Seeder | `KpCustomerPortfolio202608Seeder` → `customer_data` + contacts + servicing assignments |
| Rep alignment | `KpRepCodeAlignment202608Seeder` (employee # → `users.rep_code`) |

**Related models:** `AcumaticaCustomer` (ERP master), `CustomerData` (CSV/profile extras), `CustomerContact`, `UserCustomerAssignment`

---

## 1. Goals (what “good” looks like)

1. **Every CSV column** is stored and visible on a **Customer Profile** tab (non-sales).  
2. **Contacts** can be maintained in-app (required fields marked, company defaults from Acumatica/CSV).  
3. Reminders when **phone** or **email** is missing.  
4. **Customer Group** (especially **Kim-Fay Professional**) is first-class for filters, FOL, and portfolio.  
5. After deploy, each account’s **Rep Code / Sales Rep** maps to the **correct consultant** on My Portfolio / KP CRM / FOL.  
6. Periodic cron refreshes **status** (Active / On Hold) and **credit terms** from Acumatica.

---

## 2. CSV inventory (1 000 rows)

### 2.1 Columns in export

| # | CSV column | Example / notes | In `customer_data`? | Elsewhere / gap |
|---|---|---|---|---|
| 1 | Selected | Always `False` | No | Ignore (export checkbox) |
| 2 | Customer ID | `CUST100002` | Key: `customer_acumatica_id` | Must match `acumatica_customers.acumatica_id` |
| 3 | Customer Name | Company name | No | `acumatica_customers.name`; also default contact first_name |
| 4 | Customer Class | `KPREST`, `KPHOTEL`, … | No | **`acumatica_customers.customer_class`** — import should upsert if empty |
| 5 | Country | `KE` | Yes | |
| 6 | City | | Yes | |
| 7 | Currency ID | `KES` | Yes | |
| 8 | Terms | `30D`, `30DS` | No | **`acumatica_customers.payment_terms`** — map on import |
| 9 | Customer Status | `Active` | No | **`acumatica_customers.status`** — cron + import |
| 10 | Sage Code | | Yes | |
| 11 | **Customer Group** | **Kim-Fay Professional** (996), Consumer sales (4) | Yes | Critical for KP Professional portfolio |
| 12 | Category | | Yes | |
| 13 | Customer Region | e.g. Nairobi West | Yes | |
| 14 | Parent Code | Parent customer id | No | **`acumatica_customers.parent_acumatica_id`** |
| 15 | Price Class ID | `KPBASE` | Yes | Used by PCR |
| 16 | Price Class Name | KP Base price | Yes | |
| 17 | Main A/CC Owner | | Yes | |
| 18 | Route Code | `3G` | Yes | FK to routes |
| 19 | Route Name | Langata | No | Lookup via `acumatica_routes` by `route_code` — show on UI |
| 20 | Credit Limit | `80,000.00` | Yes | |
| 21 | Rep Code | `P505`, `YVON`, … | Yes | Portfolio join key |
| 22 | Sales Rep | Shirleen Chebet | Yes | Display name from CSV |
| 23 | Zone ID | `Z003` | Yes as `shipping_zone_id` | |
| 24 | Customer Zone | Ngong | Yes | |
| 25–27 | Address Line 1–3 | | Yes | |
| 28 | Shipping Rule | Back Order Allowed | Yes | |
| 29 | Email | | Yes | + default contact email |
| 30 | Statement Type | Open Item | Yes | |
| 31 | Business Account ID | | Yes | |
| 32 | Tax Registration ID | PIN | Yes | |
| 33 | Statement cycle | MONTHLY | Yes | |
| 34 | Delivery | | Yes | |

### 2.2 Customer Group (do not miss)

| Customer Group | Count | Portfolio behaviour today |
|---|---:|---|
| **Kim-Fay Professional** | 996 | Seeder creates **servicing** assignment when `users.rep_code` matches CSV **Rep Code** |
| **Consumer sales** | 4 | **No** auto-assignment in seeder (group gate) — decide: attach manually or widen rule |

Treat **Kim-Fay Professional** as the KP Professional book for FOL, dormant, and portfolio filters. Surface `customer_group` on:

- Customer profile header badge  
- Filters (My Portfolio / KP accounts / FOL picker)  
- Exports  

### 2.3 Customer Class distribution (sample)

`KPSERVICE`, `KPHOTEL`, `KPPMGR`, `KPREST`, `KPINDUSTRY`, `KPCCLNRS`, `KPHEALTH`, `KPDIST`, `KPEDU`, `KPNGO`, …  

These stay on **`acumatica_customers.customer_class`** (ERP). Profile must show class **and** group side by side.

---

## 3. Mapping status today

### 3.1 What the seeder already does

`KpCustomerPortfolio202608Seeder`:

1. Upserts **`customer_data`** for all FIELD_MAP columns + credit limit.  
2. If **Customer Group = Kim-Fay Professional** and **Rep Code** matches an **active user.rep_code** → create `user_customer_assignments` (`source = kp_customers_20260805`, type servicing).  
3. If no contacts → create default primary contact from Customer Name + Email (last_name empty).

### 3.2 Gaps vs “every CSV detail”

| Gap | Action |
|---|---|
| **Customer Name / Class / Terms / Status / Parent Code** not written to master on import | On import: upsert `acumatica_customers` fields when blank or from trusted KP file |
| **Route Name** not stored | Resolve from `route_code` in UI; ensure routes seeded |
| **Customer profile UI** does not show `customer_data` | Build Profile tab (below) |
| **Consumer sales (4)** not auto-assigned | Product decision + optional assign |
| Default contact: full name in first_name, empty last_name | Split trading name / contact name (see contacts) |
| No company **phone** in CSV | Prompt users to add phone on contact form |
| **LITIGATION / BADDEBTPRO** rep codes | May not map to real users — flag as unassigned |

### 3.3 Consultant mapping rule (source of truth after deploy)

```text
CSV Rep Code  →  users.rep_code (active)
              →  UserCustomerAssignment (servicing, source kp_customers_20260805)
              →  My Portfolio / KP CRM / FOL “attached” book
```

**Order of operations on deploy:**

1. Users exist with correct **employee_number** / identity.  
2. Run **`KpRepCodeAlignment202608Seeder`** (e.g. P317→YVON, P460→C967, P483→C1262).  
3. Run **`KpCustomerPortfolio202608Seeder`** (or re-run against current CSV).  
4. Review seeder warning: *“rep codes without an active user profile”*.  
5. Manually fix unmapped codes; re-run assignment pass if needed.

Dashboard / FOL do **not** use Sales Rep name string alone — only **rep code → user → assignment**.

---

## 4. Customer Profile tab (non-sales)

One account screen, tabs e.g. **Profile | Contacts | Contracts | Account history**.  
Profile = CSV + master **without** sales KPIs.

### 4.1 Sections to show (all CSV fields)

**Identity**

- Customer ID, Customer Name  
- Customer Status, Customer Class  
- Customer Group (**Kim-Fay Professional** badge)  
- Category, Customer Region, Parent Code / parent name  

**Commercial**

- Terms (credit terms), Credit Limit, Currency  
- Price Class ID + Name  
- Statement Type, Statement cycle  
- Shipping Rule, Delivery  
- Sage Code, Business Account ID, Tax Registration ID  
- Main A/CC Owner  

**Territory / route**

- Rep Code, Sales Rep (CSV) + **linked Sight user** (if assignment exists)  
- Route Code + Route Name  
- Zone ID + Customer Zone  

**Address & channel**

- Country, City, Address 1–3  
- Email (company)  
- Phone (from contacts / Acumatica — flag if missing)  

**Provenance**

- Source (`excel_upload` / Acumatica sync), last `synced_at`  

### 4.2 Contacts component (from original notes)

| Requirement | Spec |
|---|---|
| Required fields with `*` | e.g. First name*, Designation*, at least one of Email* or Phone* |
| Default contact from company | Split **Customer Name** sensibly: company stays on account; contact first/last for person if known; if only company name → first_name = company, last_name optional / “—” |
| Missing phone | Banner: “Update company / contact phone numbers” |
| Missing email | Banner: “No email on file — add a contact email” |
| Primary contact | Exactly one primary; used for FOL / notifications where applicable |

### 4.3 Contracts & FOL compliant

- Upload / list contracts on account.  
- If account has **FOL consumables purchase history** → badge **FOL compliant** (term from product/ops).  
- Not a sales chart — compliance flag only on Profile / Contracts.

---

## 5. Cron — account hygiene

| Job | Frequency | Action |
|---|---|---|
| Customer status / terms refresh | e.g. nightly or weekly | Pull Acumatica customer status + payment terms + credit-related fields into `acumatica_customers` (+ optional `customer_data` mirror) |
| On Hold / Active | Same | Update status; surface On Hold on profile and order gates |
| Soft alert | Optional | Notify consultant if primary contact still missing email/phone after N days |

Do not overwrite trusted manual contact rows; only master status/terms/limits.

---

## 6. Confirm consultant mapping after deploy

### 6.1 Automated checks (run on server)

```bash
# 1) Rep codes in customer_data with no active user
# 2) Kim-Fay Professional customers with no servicing assignment
# 3) Assignment user rep_code ≠ customer_data.rep_code (drift)
```

Suggested artisan later: `orderwatch:verify-kp-portfolio` reporting:

| Metric | Pass if |
|---|---|
| % Kim-Fay Professional with assignment | ≥ 95% (excl. LITIGATION/BADDEBT/empty) |
| Unresolved rep codes | Listed and ticketed |
| Sample 10 customers per rep | Sales Rep name matches user name (manual spot-check) |

### 6.2 Dashboard UI checks

| Surface | What to verify |
|---|---|
| **My Portfolio** as consultant X | Customer count ≈ CSV rows for their Rep Code (Professional only) |
| Customer row / profile | Shows Sales Rep + Rep Code + Group |
| **FOL** create | Consultant only sees **attached** customers (manual / `kp_customers_20260805`) |
| HoD KP team view | Union of reportees’ books |
| Admin portfolio | Source tag **Manual / kp_customers_20260805** vs Acumatica |

### 6.3 Known mapping risks

| Risk | Mitigation |
|---|---|
| User missing `rep_code` before seeder | Run `KpRepCodeAlignment202608Seeder` first |
| CSV Rep Code ≠ Acumatica SO rep on orders | Portfolio attach uses CSV assignment; SO may still show different ERP rep — show both on profile |
| LITIGATION / BADDEBTPRO | Do not force to a salesperson; bucket “Special / finance” |
| Consumer sales (4 accounts) | Explicit decision: leave out of KP Professional book or assign by rep |
| Empty Rep Code | Flag in verify report; no assignment |

### 6.4 Spot-check from CSV (examples)

| Customer ID | Name | Group | Rep | Sales Rep | Expect after deploy |
|---|---|---|---|---|---|
| CUST100002 | 4Horsemen Limited | Kim-Fay Professional | P505 | Shirleen Chebet | On Shirleen’s book if `users.rep_code=P505` |
| CUST100003 | 5Th Avenue Management Office | Kim-Fay Professional | C967 | Berna Piwang | On Berna’s book if `rep_code=C967` (after alignment) |
| CUST100221 | Davestar… Closed | Consumer sales | P271 | Hafswa Fadhili | **Not** auto-assigned by current seeder |

---

## 7. Implementation flow (recommended order)

```text
1. Copy/refresh seeder CSV from excels/ if needed
2. Align user rep codes (KpRepCodeAlignment)
3. Import customer_data + assignments (KpCustomerPortfolio)
4. Upsert master fields: class, terms, status, parent, name (extend seeder)
5. Profile API + UI tab (all customer_data + master fields)
6. Contacts form: required *, split name, missing email/phone banners
7. Verify portfolio report + spot-check dashboard as 2–3 consultants
8. Cron: status/terms refresh
9. Contracts + FOL compliant badge
```

---

## 8. Acceptance checklist

- [ ] All non-junk CSV columns visible on Customer Profile (or resolved Route Name).  
- [ ] **Customer Group** shown and filterable; Kim-Fay Professional drives KP Professional portfolio.  
- [ ] Credit terms, credit limit, price class, route/zone, addresses, tax/sage/business ids present.  
- [ ] Contact form marks required fields; prompts for missing phone/email.  
- [ ] After deploy, ≥95% Professional accounts with valid rep code are on the matching consultant’s assignment.  
- [ ] FOL / My Portfolio for a test rep only lists their assigned customers.  
- [ ] Unresolved rep codes documented (LITIGATION, BADDEBT, empty, missing users).  
- [ ] Cron updates On Hold / Active and terms without wiping contacts.

---

## 9. Out of scope (this doc)

- Full sales KPI redesign (see portfolio PRD)  
- Changing Acumatica ERP master in ERP itself  
- Auto-merging Consumer sales into Professional without ops sign-off  

---

## 10. Quick reference — seeder FIELD_MAP

Already mapped into `customer_data`:

`route_code`, `shipping_zone_id`, `customer_zone`, **`customer_group`**, `tax_registration_id`, `currency_id`, `price_class_id`, `price_class_name`, `main_ac_owner`, `rep_code`, `sales_rep`, `category`, `customer_region`, `sage_code`, `business_account_id`, `statement_type`, `statement_cycle`, `shipping_rule`, `delivery`, `country`, `city`, `address_line_*`, `email`, `credit_limit`.

**Still need explicit product/UI + optional master upsert:**  
Customer Name, Customer Class, Terms, Customer Status, Parent Code, Route Name (display).
