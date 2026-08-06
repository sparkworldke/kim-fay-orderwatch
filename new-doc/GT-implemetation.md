# General Trade (GT) Implementation — Product Spec

**Product:** Kim-Fay Sight  
**Channel:** General Trade (GT)  
**Status:** Draft PRD  
**Sources:** Original GT notes · `excels/Kimfay Employees - HODs.csv` · existing Sales Intelligence / org patterns  

---

## 1. Problem (current notes, clarified)

| Need | Detail |
|---|---|
| **GT head** | **Steve Ananda** — email **`salesstrategy@kimfay.com`**. No payroll employee number; Sight assigns synthetic **`XGT001`**. He heads **General Trade**. |
| **Wrong manager in CSV** | In `Kimfay Employees - HODs.csv`, GT staff have **Reporting To = P496 (Purity Nduku Kioko)**. Purity is **Modern Trade Manager**. GT must **not** report to Purity. |
| **Correct hierarchy** | **CCO (Vignesh) → Steve Ananda → GT reportees** (and further reportees under regional leads). |
| **Success attribution** | Accounts / outlets attached to a team member count toward **that person’s** and **Steve’s** rollup. |
| **CCO visibility** | CCO can open his commercial team, **select Steve**, then drill into ASMs / reps. |
| **Regions** | Team members can be tagged with **region(s)** so outlets and reportees can be attached by territory. |
| **SFA** | Future integration of an **SFA tool** to match field data into Sight. |

---

## 2. Goals

1. Standalone **General Trade** product area in the app (not buried only under Sales Intelligence).  
2. Org tree: all **Division = General Trade** people from HODs CSV map under **Steve Ananda**, not Purity.  
3. GT users see only GT-relevant menus and data scope.  
4. Two primary GT menus: **Revenue and Orders**, **SFA Data**.  
5. Regional structure supports attaching outlets and nested reportees.  
6. CCO and Steve can roll up performance by team member and region.

---

## 3. Navigation — General Trade as its own group

Place a **new top-level sidebar group** immediately **below OrderWatch** (above or beside other commercial groups as product decides; default: **after OrderWatch**).

```text
OrderWatch
  Dashboard
  Orders
  …
General Trade          ← NEW standalone group
  Revenue and Orders
  SFA Data
Sales Intelligence
  …
```

### 3.1 Menu items (GT only)

| Menu | Purpose |
|---|---|
| **Revenue and Orders** | GT sales performance: revenue, order counts/value, trends, by rep / region / outlet (Acumatica SO + GT channel scope). |
| **SFA Data** | Field force / SFA-matched activity: visits, coverage, orders captured in SFA, gaps vs ERP — ready for SFA integration. |

Optional later (not required for V1): My Team (GT-only), Outlets by region, Targets.

### 3.2 Access

| Role | Sees GT menu group | Scope |
|---|---|---|
| **Steve Ananda** (GT head) | Yes | Full GT book + all reportees |
| **GT reportees** (ASM, DSR, VSR, etc.) | Yes | Own book + own reportees (if any) |
| **CCO** | Yes | All commercial; can drill **Steve → members** |
| **Purity / MT team** | No GT group (unless dual role) | MT stays under Modern Trade / Sales Intelligence |
| **Other OrderWatch users** | No | Unchanged |

Capability flag e.g. `gt` / department slug `gt` (align with existing `capability: "gt"` patterns).

---

## 4. Org hierarchy (override HODs CSV for GT)

### 4.1 Intended tree

```text
CCO — Vignesh Ramachandran (P320)
 └── Steve Ananda                    ← GT head (Sight user; email in DB)
      ├── Area Sales Managers (GT)
      │    └── Distributor / Van / Tuk Tuk reps (region)
      ├── Territory supervisors
      └── Direct GT sales reps
```

**Not:**

```text
Purity (P496) → GT staff     ← CSV today; DO NOT USE for GT
```

Purity remains head of **Modern Trade** only. Export / other Consumer Sales divisions keep their own managers (e.g. Francis P084 for Export).

### 4.2 GT roster from HODs CSV (Division = General Trade)

Import **identity** (employee number, name, designation) from the CSV.  
Replace **Reporting To** with Steve for all of these (CSV shows P496 today).

| Emp # | Name | Designation (CSV) | CSV reports to | **Sight reports to** |
|---|---|---|---|---|
| P028 | Jackson Musyoka Pius | Area Sales Manager | P496 | **Steve** |
| P033 | Johnsam Kioko Musyoki | Area Sales Manager | P496 | **Steve** |
| P150 | Lilian Judy Muthoni Maina | Distributor Sales Representative | P496 | **Steve** (or ASM if region assigned) |
| P154 | Faith Wamaitha Muiya | Van Sales Representative | P496 | **Steve** / ASM |
| P158 | Melan Naliaka Khisa | Distributor Sales Representative | P496 | **Steve** / ASM |
| P172 | Linet Adhiambo | Distributor Sales Representative | P496 | **Steve** / ASM |
| P178 | Duncan Mwanzia Nguni | Distributor Sales Representative | P496 | **Steve** / ASM |
| P276 | Peter Kago Mburu | Van Sales Representative | P496 | **Steve** / ASM |
| P323 | Simon Macharia Mwaniki | Van Sales Representative | P496 | **Steve** / ASM |
| P335 | James Fundi Njeru | Tuk Tuk Sales Representative | P496 | **Steve** / ASM |
| P339 | Jonathan Mwasi | Van Sales Representative-Lake | P496 | **Steve** / ASM |
| P342 | Samson Kiwoi Vorogha | Distributor Sales Representative | P496 | **Steve** / ASM |
| P343 | Rebecca Kananu | Distributor Sales Representative | P496 | **Steve** / ASM |
| P344 | Purity Wanjira Gichohi | Distributor Sales Representative | P496 | **Steve** / ASM |
| P345 | Dan Kaberia Mutiga | Consumer Sales Representative | P496 | **Steve** / ASM |
| P346 | Lucy Wanjiru Kamau | Distributor Sales Representative | P496 | **Steve** / ASM |
| P348 | Pitwel Mwakulegwa Beja | Distributor Sales Representative | P496 | **Steve** / ASM |
| P373 | Everlyne Awiti Otiende | Distributor Sales Representative | P496 | **Steve** / ASM |
| P395 | Johnson Muthiga Mbatia | Area Sales Manager | P496 | **Steve** |
| P413 | Peter Muuo Nzuki | Distributor Sales Representative | P496 | **Steve** / ASM |
| P421 | Hannah Mwelu Mutisya | Distributor Sales Representative | P496 | **Steve** / ASM |
| P427 | Lawrence Kipkemoi | Distributor Sales Representative | P496 | **Steve** / ASM |
| P428 | Kathleen Karimi Mutua | Distributor Sales Representative | P496 | **Steve** / ASM |
| P430 | Bernadette Mghoi Waleghwa | Distributor Sales Representative | P496 | **Steve** / ASM |
| P455 | Moses Mwaka Daniel | CSR – Wholesale and Van Operations | P496 | **Steve** / ASM |
| P461 | Fredrick Juma Wesonga | CSR – Rift | P496 | **Steve** / ASM |
| P481 | Stephanie Kerubo Ayieni | Territory Supervisor – Coast | P496 | **Steve** |
| P487 | Clement Omondi Otieno | Consumer Sales Representative | P496 | **Steve** / ASM |

*Exact ASM nesting (rep under which ASM) can be refined with region mapping; until then, direct report to Steve is acceptable for V1.*

### 4.3 Steve Ananda profile requirements

| Field | Rule |
|---|---|
| User in DB | Must exist (create/link if missing) |
| Email | **`salesstrategy@kimfay.com`** (login) |
| Employee number | **`XGT001`** (Sight-generated; not payroll `P`/`C`) |
| Role / department | GT head · department **GT** · commercial |
| `reports_to` | CCO (P320 / Vignesh) |
| Not in HODs CSV | Expected — synthetic emp # documents the gap |

---

## 5. Regions

| Feature | Spec |
|---|---|
| **Region on user** | Optional multi-select (e.g. Coast, Rift, Lake, Nairobi, …) from CSV titles where present (Coast, Rift, Lake). |
| **Attach outlets** | Outlets / GT customers can be tagged with region and assigned to a team member. |
| **Attach reportees** | A manager can have reportees limited to a region (ASM Coast → coast DSRs). |
| **Rollup** | Revenue and Orders filters: by region, by member, by Steve total. |

---

## 6. Accounts & success contribution

| Rule | Detail |
|---|---|
| Portfolio | GT outlets/customers attached to a rep (manual, import, or channel class GT) feed **that rep’s** Revenue and Orders. |
| Manager view | Steve sees union of all GT attachments under his tree. |
| CCO | Can pick Steve → see team list → open one member’s contribution. |
| Channel | Prefer `sales_channel_code = GT` (and existing category rules e.g. CSDIST / CSWSALERS → GT). |

---

## 7. Screens (V1)

### 7.1 Revenue and Orders

- Period filter, region filter, team member filter (scoped by role).  
- KPIs: order count, revenue (or ordered value), growth vs prior period.  
- Tables: by rep, by region, by customer/outlet.  
- Drill: order list (GT-scoped) → optional line detail later.  
- Respect revenue masking if role requires it.

### 7.2 SFA Data

- Placeholder + contract for SFA integration.  
- Show matched vs unmatched field activity when feed exists.  
- Until SFA is live: empty state explaining “SFA data will appear here once connected”; optional manual upload later.  
- Goal: reconcile SFA visits/orders with Sight ERP orders for the same GT outlets.

---

## 8. Implementation flow (product sequence)

```text
1. Confirm Steve Ananda user (email, active, department GT)
2. Import / align GT users from HODs CSV (identity only)
3. Set reports_to: all GT Division → Steve (override P496)
4. Set Steve → CCO
5. Assign regions (from titles + admin UI)
6. Attach GT outlets / channel scope to members
7. Sidebar: General Trade group below OrderWatch
      - Revenue and Orders
      - SFA Data
8. CCO team drill: CCO → Steve → members
9. SFA integration (phase 2)
```

---

## 9. Explicit non-goals / boundaries

| Do | Don’t |
|---|---|
| GT under Steve | Put GT under Purity (P496) |
| Standalone GT menu group | Only a single link under Sales Intelligence (may keep channel deep-link later) |
| MT stays with Purity | Merge MT and GT org trees |
| Export under Francis (as CSV) | Move Export into GT without a separate decision |

---

## 10. Acceptance criteria

- [ ] Sidebar shows **General Trade** as its own group **below OrderWatch**.  
- [ ] Under GT: only **Revenue and Orders** and **SFA Data** (V1).  
- [ ] Steve Ananda is GT head; CCO can select him and drill to members.  
- [ ] All HODs CSV rows with Division **General Trade** report to **Steve**, not Purity.  
- [ ] Purity’s tree remains Modern Trade only.  
- [ ] GT member sees only their (and reportees’) revenue/orders contribution.  
- [ ] Region can be set on a member; outlets can be attached by region.  
- [ ] SFA Data page exists and is ready for integration (or clear empty state).  
- [ ] Account attachments contribute to the correct person’s success metrics.

---

## 11. Open decisions (ops)

1. Full list of **region codes** for Kenya GT (standard list).  
2. Which ASMs (P028, P033, P395, …) own which regions for nested reporting.  
3. Steve’s official **employee_number** if HR later issues one (optional).  
4. SFA vendor / API timeline for phase 2.  

---

## 12. Related

- HODs roster: `excels/Kimfay Employees - HODs.csv`  
- Channel rules: GT categories in portfolio foundation (CSDIST, CSWSALERS, …)  
- Existing SI channel filter `channel=GT` may deep-link into Revenue and Orders later  
- User identity seeders: employee numbers for GT staff; **reports_to override** is GT-specific  
