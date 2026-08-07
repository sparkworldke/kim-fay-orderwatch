# GT Direct Customers — SFA → Acumatica Match & Create SO

**Product:** Kim-Fay Sight · General Trade  
**Type:** Extension PRD (depends on **SFA Sync** Phase 1 warehouse)  
**Status:** Decision draft — fleshed for build  
**Depends on:** `sfa-sync-with-ai.md` (local SFA tables + match columns) · `GT-implemetation.md` (GT menus, Steve / team)  
**Primary users:** GT head (Steve) · GT ASMs / DSRs / VSRs · Sales Ops (SO create) · Super Admin (match QA)  
**Timezone:** Africa/Nairobi  

---

## 1. One-sentence goal
Under GT Add new Menu - DIrect Orders

Once SFA data lands in Sight, **match SFA outlets to Acumatica customers**, show **direct field orders** (product + qty), and **create / draft Sales Orders from the dashboard** for matched GT direct customers — starting with **Wholesalers and Bishara Street**, without retyping orders into ERP.

---

## 2. Why (business)

| Pain today | With this module |
|------------|------------------|
| Field order sits in SFA; ERP SO is typed again | One path: SFA entry → matched customer → SO in Acumatica (or Sight draft) |
| Customer IDs diverge (SFA shop vs Acumatica ID) | **One commercial identity** via match + shared ID rules |
| GT leadership cannot see “ordered in field, not yet in ERP” | Queue of **direct orders ready to post** |
| Wholesale / Bishara volume is high-friction, high-trust | Pilot where match quality and SO speed pay off first |

**Not replacing Acumatica.** ERP remains system of record for SO, credit, stock, invoice.  
**Not writing back to Solutech** (SFA sync rule stays read-only upstream).

---

## 3. Placement in the roadmap

```text
SFA Sync v1 (warehouse)          THIS PRD (Phase 2a — Direct)         Later (Phase 3 — MT)
─────────────────────            ───────────────────────────          ──────────────────
bi_* → local tables              Customer match                       OSA / SOS / visit
reps, customers, sales_entries   Product → Inventory ID               Why outlet underperforms
match columns empty              Direct order queue → Create SO       (design hooks only)
GT menu: SFA Data (empty/live)   GT: Direct Orders + Match desk
```

| Prerequisite (must be true) | Source |
|-----------------------------|--------|
| SFA customers + sales_entries syncing | `sfa-sync-with-ai.md` |
| Columns ready: `customers.acumatica_customer_id`, `match_status`, `matched_at` | SFA schema Phase 2 hooks |
| Products synced locally (`products` / SFA product master) | SFA table inventory |
| Acumatica customers + inventory in Sight | Existing Acumatica sync |
| GT access / menu group | `GT-implemetation.md` |

---

## 4. Scope

### 4.1 In scope (V1)

| # | Capability |
|---|------------|
| 1 | **Customer match** SFA outlet ↔ Acumatica customer (auto + manual confirm) |
| 2 | **Stable commercial ID** display: prefer Acumatica ID once matched; keep SFA shop id as secondary |
| 3 | **Product map** SFA product → Acumatica **Inventory ID** (+ UOM rules) |
| 4 | **Direct order inbox** from SFA `sales_entries` for matched GT customers |
| 5 | **Create SO** from selected lines (customer + inventory + qty) via dashboard |
| 6 | **Pilot filter:** Wholesalers + **Bishara Street** clients first |
| 7 | Match QA desk: unmatched / ambiguous / conflicts |

### 4.2 Explicit non-goals (V1)

- Auto-post SO without human confirm (default: **review → create**)  
- Full credit / price engine redesign (use Acumatica defaults / price class of customer)  
- Writing orders or match results into Solutech  
- MT OSA / SOS analytics (reserved §11)  
- Van stock allocation / mobile cash van full WMS  
- Replacing Order Match (email PO) pipeline  

---

## 5. Pilot: who we start with

| Segment | Why first | Identification (decide in implementation) |
|---------|-----------|---------------------------------------------|
| **Wholesalers** | Large direct lines; clear ERP accounts; high retype cost | Acumatica class / channel / customer_group / name rules (e.g. CSWSALERS / wholesale tags) + GT channel |
| **Bishara Street** | Dense, known book; easy ops validation | Region / route / location tag, or curated customer list, or territory flag |

**Rule:** V1 UI default filter = **Pilot only**. Toggle “All matched GT” for power users later.  
**Exit pilot:** ≥ match rate target + SO create success rate (§12) → open rest of GT direct.

---

## 6. Customer merge & match

### 6.1 Principle

| Side | Identity | Role |
|------|----------|------|
| **SFA** | `sfa_shop_id`, `customer_code`, name, phone, geo | Field truth for *who was visited / ordered* |
| **Acumatica** | `acumatica_id` (Customer ID) | ERP truth for *who we bill / ship / credit* |
| **Sight link** | `customers.acumatica_customer_id` + `match_status` | Join key for orders → SO |

**Never overwrite** SFA identity fields when matching (SFA sync rule).  
**“Similar customer ID”** means: after match, UI and SO use **one Acumatica Customer ID**; SFA code remains for audit.

### 6.2 Match statuses

| `match_status` | Meaning | Can create SO? |
|----------------|---------|----------------|
| `unmatched` | No link | No |
| `suggested` | Auto score above soft threshold | No (confirm first) |
| `matched` | Confirmed (auto high-confidence or human) | **Yes** |
| `conflict` | Multiple strong candidates | No (resolve) |
| `ignored` | Not for ERP direct SO (e.g. pure cash outlet policy) | No |

### 6.3 Matching signals (priority order)

| Priority | Signal | Notes |
|----------|--------|-------|
| **1** | Exact **customer code** = Acumatica ID or known external code | Highest trust |
| **2** | Exact **phone** (normalized KE) | Secondary |
| **3** | Exact **KRA PIN** if both sides have it | Strong |
| **4** | Fuzzy **name** + same region / route / city | Score only → `suggested` |
| **5** | Manual map by GT / admin | Always allowed → `matched` |

Optional later: GPS radius vs customer address.

### 6.4 Auto vs human

| Case | Behaviour |
|------|-----------|
| Single candidate, exact code or exact phone | Auto → `matched` (log reason) |
| Multiple candidates or fuzzy-only | `suggested` or `conflict` → Match desk |
| Zero candidates | `unmatched` → create request / fix master data |

### 6.5 Match desk (UI)

```text
Filters: Pilot · status · region · rep
Table: SFA name · SFA code · shop id · suggested Acumatica · score · status · actions
Actions: Confirm · Link other · Unlink · Ignore · Open both masters
```

---

## 7. Product: Inventory ID + quantity

### 7.1 What an SO line needs

| Field | Source |
|-------|--------|
| **Inventory ID** | Acumatica inventory (required) |
| **Quantity** | SFA sales line quantity (required) |
| **UOM** | Map SFA UOM → Acumatica UOM (or base convert via `uom_quantities`) |
| **Unit price** | Prefer Acumatica price class for customer; fallback SFA value/qty if policy allows |
| **Warehouse** | Customer default / GT default / rep warehouse_code if trusted |

### 7.2 Product match

| Priority | Signal |
|----------|--------|
| 1 | SFA product code / barcode = Inventory ID |
| 2 | Explicit map table `sfa_product_id → inventory_id` (admin maintained) |
| 3 | Name fuzzy → suggest only (never auto for SO) |

| Product status | SO line |
|----------------|---------|
| Mapped | Eligible |
| Unmapped | Block line; show “map product” |
| Inactive / discontinued in ERP | Block with reason |

### 7.3 Multi-line SFA entry

SFA unique key is **`(entry_id, product_id)`**. One field order (entry) may become **one SO with many lines**. Group by `entry_id` (+ customer) in the inbox.

---

## 8. Direct order → SO flow

### 8.1 Happy path

```text
SFA sales_entries (synced)
        │
        ▼
Filter: GT team · pilot segment · entry date · not yet posted
        │
        ▼
Customer match_status = matched ──else──► Match desk
        │
        ▼
Every line product mapped ──else──► Product map
        │
        ▼
Dashboard: Direct order card / row
  · Customer (Acumatica ID + name)
  · Lines: Inventory ID · qty · UOM · value
  · Rep · entry time · SFA entry id
        │
        ▼
User: Review → Create SO
        │
        ▼
Acumatica SO create API (reuse patterns from FOL SO service where possible)
        │
        ▼
Store link: sfa_entry_id(s) → acumatica_order_nbr · status · created_by · created_at
        │
        ▼
Inbox row: Posted · open SO in Sight / ERP
```

### 8.2 Guardrails before Create SO

| Check | Fail behaviour |
|-------|----------------|
| Customer matched | Block |
| All selected lines mapped | Block unmapped lines |
| Qty > 0 | Block |
| Not already posted (idempotent) | Show existing SO # |
| Credit / on-hold customer (if flag available) | Warn or block per policy |
| Duplicate same entry same day | Soft warn |

### 8.3 Create modes (V1 decision)

| Mode | V1 default |
|------|------------|
| **Review then create** | **Yes** — one click after checklist |
| Auto-create on sync | **No** |
| Draft in Sight only (no ERP) | Optional if SO API down — label clearly |

### 8.4 Idempotency

- Key: `sfa_entry_id` (or set of entry_id+product for partial post)  
- Second create attempt returns **existing SO**, does not double-post  

---

## 9. Screens (GT area)

Align with `GT-implemetation.md` menus; extend **SFA Data** or add child items:

```text
General Trade
  Revenue and Orders          ← ERP performance (existing plan)
  SFA Data                    ← coverage / visits (sync KPIs)
  Direct Orders          NEW  ← this PRD inbox → Create SO
  Customer match         NEW  ← match desk (admin + GT head)
```

| Screen | Purpose |
|--------|---------|
| **Direct Orders** | List unposted / posted SFA entries for pilot; expand lines; Create SO |
| **Customer match** | Work unmatched / suggested / conflict |
| **Product map** (admin) | SFA product → Inventory ID table + bulk import |

**Role scope**

| Role | Direct Orders | Match desk |
|------|---------------|------------|
| Steve / GT head | Full pilot (then full GT) | Yes |
| ASM / rep | Own book / region | Suggest only / limited |
| Sales Ops | Full + Create SO | Yes |
| Super Admin | Full | Full |

---

## 10. Data model (additions — lean)

Prefer extending SFA module tables; do not duplicate customer masters.

| Table / columns | Purpose |
|-----------------|---------|
| `customers.acumatica_customer_id`, `match_status`, `matched_at`, `match_method`, `matched_by` | Customer link (hooks already planned) |
| `sfa_product_maps` | `sfa_product_id` → `inventory_id`, UOM map, active |
| `sfa_direct_order_posts` | `entry_id` · lines snapshot · `acumatica_order_nbr` · status · user · timestamps |
| Optional: `customers.pilot_segment` | `wholesale` \| `bishara` \| null (or derive live) |

**Products:** extend local SFA `products` with `inventory_id` / `product_match_status` **or** only use map table (prefer map table so SFA sync never overwrites ERP ids).

---

## 11. Future (back pocket) — MT & outlet performance

When GT direct is stable and SFA visits are trusted, **Modern Trade** adds diagnostic layers. **Do not build in V1**; design so data is already in the warehouse.

| Concept | Meaning | Primary SFA inputs | Question it answers |
|---------|---------|--------------------|---------------------|
| **Visit** | Did we call the outlet? | `customer_visits` | Presence / adherence |
| **OSA** (On-Shelf Availability) | Was product available / listed when visited? | Visit forms / stock check fields if present in BI | Why no order — stock gap? |
| **SOS** (Share of Shelf) | Relative presence vs competitors | Form metrics if available | Competitive position |
| **Order conversion** | Visit → order | visits + sales_entries | Productivity |
| **Why not performing** | Composite | coverage + OSA + SOS + order + ERP sell-in | Action for ASM |

**Design rule:** keep visit and sales fact tables clean; OSA/SOS as **later metrics services**, not hard-coded into Direct Orders.

---

## 12. Success metrics

| Metric | V1 target (pilot) |
|--------|-------------------|
| **Customer match rate** (pilot universe) | ≥ 80% `matched` within 2 weeks of pilot start |
| **Product map rate** (SKUs appearing in pilot sales) | ≥ 90% of line volume |
| **Time entry → SO** (median, matched+mapped) | Under **15 minutes** same day |
| **Double SO rate** | 0 (idempotent posts) |
| **Manual retype reduction** | Qual: GT ops confirms for Bishara / wholesale book |

---

## 13. Decisions (closed)

| ID | Decision |
|----|----------|
| **D1** | Depends on SFA warehouse; **no parallel Solutech pull** |
| **D2** | Pilot first: **Wholesalers + Bishara Street** |
| **D3** | SO create is **human-confirmed**, not silent auto |
| **D4** | Commercial ID on SO = **Acumatica customer ID** after match |
| **D5** | Lines require **Inventory ID + qty**; unmapped products block |
| **D6** | Idempotent post keyed by SFA `entry_id` |
| **D7** | Reuse Acumatica SO create patterns (e.g. FOL service style) where safe; GT payload separate |
| **D8** | OSA/SOS/visit diagnostics = **Phase 3**, not V1 scope |
| **D9** | Never write match or SO results back to Solutech |

---

## 14. Build list (priority)

| ID | Item | P |
|----|------|---|
| **B1** | Customer matcher job + match desk API/UI | P0 |
| **B2** | Pilot segment definition (config or tags) for Wholesalers + Bishara | P0 |
| **B3** | Product map table + admin UI | P0 |
| **B4** | Direct Orders inbox (group by entry; filters) | P0 |
| **B5** | Create SO from matched entry + post log (idempotent) | P0 |
| **B6** | Guardrails: unmatched / unmapped / already posted | P0 |
| **B7** | GT menu items + capability gates | P1 |
| **B8** | Bulk CSV import for product maps + customer matches | P1 |
| **B9** | Price / warehouse defaults from Acumatica customer | P1 |
| **B10** | Reporting: matched %, posted %, aging unposted | P1 |
| **B11** | MT OSA/SOS design spike (docs only until Phase 3) | P2 |

---

## 15. Acceptance (V1 pilot)

- [ ] SFA customer can be **auto- or manually matched** to Acumatica; status visible.  
- [ ] Matched pilot customers show **one commercial Customer ID** in Direct Orders.  
- [ ] SFA product maps to **Inventory ID**; qty from SFA line.  
- [ ] User can open a direct entry, see lines, pass checks, **Create SO** once.  
- [ ] Second create returns existing SO (no duplicate).  
- [ ] Unmatched customer or unmapped product **cannot** post.  
- [ ] Default list limited to **Wholesalers + Bishara** (or equivalent pilot filter).  
- [ ] Scoped GT users only see their book; Steve/ops see pilot full.  
- [ ] No writes to Solutech BI.  
- [ ] Audit: who matched, who created SO, when.

---

## 16. Open questions (resolve before build sprint)

| # | Question | Suggested default |
|---|----------|-------------------|
| Q1 | Exact pilot membership (list vs class rules)? | Config list + class codes for wholesale |
| Q2 | SO type / order type in Acumatica for GT direct? | Same as standard SO unless Finance specifies |
| Q3 | Price: ERP only vs allow SFA price? | **ERP price class** |
| Q4 | Partial post (subset of lines)? | V1: all eligible lines of entry, or selected lines with remainder “open” |
| Q5 | Bishara identification field? | Curated list or route/region tag — product + GT ops |

---

## 17. Related docs & code anchors

| Doc / area | Use |
|------------|-----|
| `sfa-sync-with-ai.md` | Warehouse, `customers` / `sales_entries`, match columns, non-write-back |
| `GT-implemetation.md` | GT menu, Steve XGT001, team scope |
| FOL SO create | Pattern for Acumatica SO create + link (`FolAcumaticaSalesOrderService`) — adapt, do not couple KP FOL |
| Acumatica customers | `acumatica_customers` + channel / class for pilot rules |
| Inventory | `acumatica_inventory_items` for Inventory ID validation |

---

## 18. One-page summary

| Keep (from SFA sync) | Add (this extension) |
|----------------------|----------------------|
| Read-only SFA warehouse | Customer match desk + statuses |
| `sales_entries` facts | Direct Orders inbox |
| Match columns on schema | Product → Inventory ID maps |
| GT team filter | Create SO from dashboard (confirm) |
| No Solutech write-back | Pilot: Wholesalers + Bishara Street |

**Success:** A GT user (or Sales Ops) sees a Bishara / wholesale order captured in SFA, confirms the matched Acumatica customer and mapped SKUs, and posts a **single correct SO** without retyping — same day.

**Later:** Same match foundation powers MT outlet diagnostics (visit · OSA · SOS · conversion) without redesigning the warehouse.


in acumatica use these customer category to pick customer name to match SFA customer name
- CSBSTREET
- CSDIST
- CSSTOCKPTS
- CSWSALERS

and now the whole database but subject to manual confirm by click of button or CSV uplaod

Or create a Seeder s