# Executive One-Page View — Product Spec

**Product:** Kim-Fay Sight  
**Feature:** Executive Command Page (single screen, numbers-first)  
**Route:** `/app/executive`  
**Default home:** For users in the **Executive** access set (see §2)  
**Status:** Draft PRD — open questions decided (§13)  
**Tone:** Board / C-suite — few words, big numbers, one click to trend  

---

## 1. Goal

One **intuitive page** that answers for leadership:

1. **How much** did we sell (value + orders)?  
2. **Where** (segment split)?  
3. **How is the month trending?**  
4. **Where are we failing to deliver** (production / supply gaps)?  
5. **What needs attention today** (risk, not a full ops console)?

No dense tables on load. Numbers → click → trend or drill.

**This page is the Executive View for Executives** — not the default home for sales consultants, CS, or technicians.

---

## 2. Audience & access

### 2.0 Who gets Executive View

| Gets `/app/executive` as primary home | Does not |
|---|---|
| Role / org tier **Executive**, **C-suite**, or named list below | Sales consultants, CS agents, technicians, pure ops clerks |
| Super Admin | (They may open it; home can stay Administration if preferred) |
| `commercialtechlead@kimfay.com` | — |

Sidebar: show **Executive View** only when `capability.executive_view` (or role ∈ Executive set).

### 2.1 Named executives (full company view)

| Person | Role | Employee # | Email | Default lens |
|---|---|---|---|---|
| **Rajdeep Singh Bains** | CEO | P301 | `rbains@kimfay.com` | Company-wide |
| **Hartaj Singh Bains** | Sales & Marketing Director | P302 | `hbains@kimfay.com` | Company-wide commercial |
| **Vignesh Ramachandran** | CCO | P320 | (existing Sight) | All commercial channels |
| **Miraj Shantilal Jhankhariya** | COO | P231 | (existing Sight) | Ops / production gaps emphasis order |
| **Divya Sudhir Jumani** | Executive | **C1144** | **`djumani@kimfay.com`** | Company-wide |
| **Manan Anilkumar Shah** | CFO | P424 | (existing Sight) | Company-wide (same KPIs) |

### 2.2 Platform / support (full company view)

| Account | Emp # | Email | Notes |
|---|---|---|---|
| **Super Admin** | — | (admin accounts) | Full view + Administration link |
| **Commercial Tech Lead** | P415 (Titus) | **`commercialtechlead@kimfay.com`** | Full executive view; Sight ops / alerts |

### 2.3 GT head (not on HR employee list)

| Person | Role | Employee # | Email | Notes |
|---|---|---|---|---|
| **Steve Ananda** | Head of General Trade | **`XGT001`** (generated) | **`salesstrategy@kimfay.com`** | No payroll employee number in Active staff / HODs. **Assign synthetic `XGT001`** in Sight for hierarchy, assignments, and reporting. Reports to CCO (P320). GT team reports to Steve (not Purity). |

**Synthetic employee numbers:** prefix **`X`** = Sight-only identity (not payroll). Never invent a `P`/`C` code that could collide with HR.

### 2.4 HODs (same layout, division highlight)

HODs see the **same page**, with company pulse + **“Your focus”** on their domain:

| HOD | Focus chip / block |
|---|---|
| Susan (P025) | KP |
| Purity (P496) | MT1 / MT2 |
| **Steve (`XGT001`)** | **GT** |
| Anne (P086) | Partner / trading gaps |
| Production / Procurement / Fleet | Production gaps row |
| Finance under CFO | Neutral (full pulse) |

**Rule:** Executives + Super Admin + commercialtechlead + Steve (as GT HOD with full commercial read on this page) = **unscoped company numbers**.  
Other HODs = same numbers + highlight (read-only on all segments).

---

## 3. Page principles

| Principle | Implementation |
|---|---|
| **Numbers only on land** | No charts until click; no long tables |
| **One date control** | From / To (default: MTD). All tiles use it |
| **Segment is king** | Overall + chips for MT1, MT2, GT, E-commerce, DTC/DTB, KP |
| **Click = story** | Click overall or a segment → **trend graph** for selection + period |
| **Ops second row** | Production / supply gaps as plain KPIs, not a second app |
| **Fast** | Prefer cached aggregates (see caching PRD); target &lt; 2s warm |

---

## 4. One-page layout (wireframe)

```text
┌─────────────────────────────────────────────────────────────────────────┐
│  EXECUTIVE VIEW                          [ From ▾ ] [ To ▾ ]  [MTD] [↻] │
│  Kim-Fay Sight · company pulse                                           │
├─────────────────────────────────────────────────────────────────────────┤
│  REVENUE & ORDERS                                                        │
│  ┌──────────────────────┐  ┌──────────────────────┐                      │
│  │  TOTAL REVENUE        │  │  TOTAL ORDERS          │                      │
│  │  KES  X.XX M         │  │  NNN                 │                      │
│  │  ▲/▼ % vs prior      │  │  ▲/▼ % vs prior      │                      │
│  │  (click → trend)     │  │  (click → trend)     │                      │
│  └──────────────────────┘  └──────────────────────┘                      │
│                                                                          │
│  BY SEGMENT  (each chip is clickable → trend for that segment)           │
│  ┌────┐ ┌────┐ ┌────┐ ┌────┐ ┌────┐ ┌────┐                               │
│  │MT1 │ │MT2 │ │ GT │ │Ecom│ │DTC │ │ KP │                               │
│  │KES │ │KES │ │KES │ │KES │ │KES │ │KES │                               │
│  │ord │ │ord │ │ord │ │ord │ │ord │ │ord │                               │
│  └────┘ └────┘ └────┘ └────┘ └────┘ └────┘                               │
│  Optional bar: share of total % under each chip                          │
├─────────────────────────────────────────────────────────────────────────┤
│  TREND (hidden until click; or pinned after first click)                 │
│  ┌────────────────────────────────────────────────────────────────────┐  │
│  │  Title: “KP · Revenue” or “Company · Orders”                         │  │
│  │  Line/area: daily within selected dates (dynamic)                    │  │
│  │  Toggle: Revenue | Orders                                            │  │
│  └────────────────────────────────────────────────────────────────────┘  │
├─────────────────────────────────────────────────────────────────────────┤
│  PRODUCTION & SUPPLY GAPS                                                │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐       │
│  │Backorder │ │RaR KES   │ │Fill rate │ │Critical  │ │Not deliv.│       │
│  │lines     │ │at risk   │ │(period)  │ │SKUs      │ │qty/value │       │
│  └──────────┘ └──────────┘ └──────────┘ └──────────┘ └──────────┘       │
│  Click any → deep link to Backorders / Fill Rate / Production (scoped)   │
├─────────────────────────────────────────────────────────────────────────┤
│  ATTENTION (max 5 lines — optional row)                                  │
│  · Top gap reason · Top open SO status risk · FOL pending (if KP) · …   │
└─────────────────────────────────────────────────────────────────────────┘
```

**HOD highlight:** soft border or “Your focus: KP” badge above the matching segment chip.

---

## 5. Revenue & Orders (core)

### 5.1 Definitions (**decided**)

| Metric | Definition |
|---|---|
| **Revenue / sales value** | **Ordered value** on in-scope sales orders for the period: prefer sum of SO **order totals** (header), consistent with Operations Dashboard and portfolio “ordered value — not cash collected”. Exclude cancelled / rejected headers. Currency **KES**. *Not* AR cash; *not* shipped-only (shipped stays for fill-rate / gaps). |
| **Orders** | Count of distinct **SO** documents in period (same SO-type and GLT exclusion rules as Operations Dashboard). |
| **Prior compare** | **MoM for MTD** (selected month vs previous calendar month). For custom ranges: prior window of equal length ending the day before From. |
| **Segment** | Customer sales channel classification: **MT1, MT2, GT, E-commerce, DTC/DTB, KP**. |

### 5.2 Segment chips

| Segment | Maps to existing product |
|---|---|
| MT1 / MT2 | Sales Intelligence Modern Trade |
| GT | General Trade (channel + future GT module) |
| E-commerce | SI channel ECOMMERCE |
| DTC/DTB | Calltronix / DTC module |
| KP | KP Professional / KP Cumulative |

Each chip shows **KES + order count** for the selected dates.  
**Click** → trend panel filters to that segment.  
**Click overall total** → company trend.

### 5.3 Trend panel (on demand)

- X-axis: days in **selected From–To** (dynamic).  
- Series: Revenue and/or Orders (toggle or dual axis).  
- Title states selection: e.g. `MT1 · 1–6 Aug 2026`.  
- No clutter: max one comparison series (prior year optional later).

---

## 6. Production gaps (second block)

Keep **numbers only**; COO and production HODs care most; everyone sees company health.

| KPI | Meaning | Deep link |
|---|---|---|
| **Open backorder lines** | Active shortage lines | `/app/backorders` |
| **Revenue at risk (KES)** | Open qty × price | Backorders |
| **Fill rate %** | Period fill (ordered vs shipped rules) | `/app/fill-rate` |
| **Critical SKUs** | Stock status critical (manufactured + partner as available) | `/app/production` or inventory |
| **Not delivered** | Products in stock not delivered / undelivered value | `/app/products-not-delivered` |

Optional micro-line under block: **Top 1 reason code** (from executive metrics service) — still one number + short label.

---

## 7. What the system offers → how it appears here

| Sight domain | On executive page |
|---|---|
| OrderWatch dashboard | Total orders / status health via Attention if needed |
| Sales Intelligence channels | Segment chips MT/GT/Ecom/KP |
| KP Operations / portfolio | KP chip + optional FOL pending count for CCO/Susan |
| GT (Steve) | GT chip |
| DTC/DTB | DTC chip |
| Production / inventory / MSI | Production gaps block |
| Backorders / fill rate / not delivered | Production gaps KPIs |
| Partner brands | Included in revenue where channel maps; stock critical in gaps |
| AI Intelligence / Genius | Not on this page (link in header optional) |
| Administration | Super Admin only footer link |
| Daily executive email | Same metrics family as this page (reuse services) |

---

## 8. Role behaviour summary

| Viewer | Sees |
|---|---|
| CEO, Hartaj, CCO, COO, Divya (C1144 / djumani@) | Full company · all segments · full gaps |
| Super Admin, commercialtechlead@ | Same + admin shortcut |
| HOD KP (Susan) | Full pulse · **KP chip emphasized** |
| HOD MT (Purity) | Full pulse · **MT1/MT2 emphasized** |
| HOD GT (Steve · XGT001 · salesstrategy@) | Full pulse · **GT chip emphasized** |
| HOD Partner Brands (Anne) | Full pulse · gaps for trading stock emphasized if data allows |
| HOD Production / Procurement | Full pulse · **Production gaps** row first/emphasized |
| Other HODs | Full pulse · neutral emphasis |

No separate app per executive — **one page**, different highlight.

---

## 9. Interaction flow

```text
Open Executive View
  → defaults MTD, company totals + 6 segment chips + production gaps
Click “KP” chip
  → trend graph opens for KP revenue/orders in date range
Change From/To
  → all numbers + open trend recompute
Click “Revenue at risk”
  → navigate to Backorders (exec can return via back)
```

---

## 10. Non-functional

| Requirement | Target |
|---|---|
| Load | Prefer Redis domain cache for closed days / short TTL for MTD |
| Mobile | Single column stack: totals → segments → trend → gaps |
| Masking | Executives see amounts; if any viewer has mask_revenue, hide KES |
| Empty segment | Show `—` / 0, not hide chip |

---

## 11. Implementation phases

| Phase | Deliver |
|---|---|
| **P0** | Page shell, date range, company revenue + orders, 6 segment chips, click → trend |
| **P1** | Production gaps KPIs + deep links |
| **P2** | HOD emphasis; Executive set default home → `/app/executive` |
| **P3** | Attention row; seed Steve + Divya identities |
| **P4** | Align daily executive email with these definitions |

### Identity seed checklist

| User | employee_number | email | notes |
|---|---|---|---|
| **Steve Ananda** | **XGT001** | salesstrategy@kimfay.com | GT head; reports_to CCO; Sight-only emp # |
| **Divya Sudhir Jumani** | **C1144** | djumani@kimfay.com | Executive; full company view |
| Executive named list | as above | as above | capability `executive_view`; home = Executive View |

---

## 12. Acceptance criteria

- [ ] Numbers-only pulse for revenue, orders, segments, production gaps.  
- [ ] Segments: **MT1, MT2, GT, E-commerce, DTC/DTB, KP** (GT chip always visible, even at 0).  
- [ ] Trend uses selected date range after click.  
- [ ] **Executive View is for Executives** — default home for Executive/C-suite named set + commercialtechlead; not for consultants.  
- [ ] **Divya** = C1144 / djumani@ full access.  
- [ ] **Steve** = XGT001 / salesstrategy@ GT head; team reports to him.  
- [ ] Super Admin can open page; CEO/Hartaj/CCO/COO/Divya/CFO as listed.  
- [ ] HODs: same layout + division highlight; their default home can stay domain app.  
- [ ] Revenue = **ordered SO value**, not cash or shipped-only.

---

## 13. Decisions (closed)

| # | Topic | Decision |
|---|---|---|
| 1 | **Divya** | **Divya Sudhir Jumani** · **C1144** · **`djumani@kimfay.com`** · Executive · full company view |
| 2 | **Steve** | No payroll # · generate **`XGT001`** · **`salesstrategy@kimfay.com`** · GT head · reports to CCO · GT staff report to Steve not Purity |
| 3 | **Executive View audience** | **For Executives** (named C-suite/Executive list + commercialtechlead + optional Super Admin). Not default home for rank-and-file |
| 4 | **Revenue** | **Ordered SO totals (KES)**; exclude cancelled/rejected. Cash/shipped not used on this row |
| 5 | **Default home** | Executive set → **`/app/executive`**. OrderWatch Dashboard remains in nav |
| 6 | **HOD home** | HODs keep domain default (e.g. KP portfolio); open Executive View from nav for company pulse |
| 7 | **Super Admin home** | May stay Administration; must reach Executive View from nav |
| 8 | **GT chip at zero** | Always show; 0 allowed; tooltip “No GT-classified orders in range” |
| 9 | **Synthetic emp #s** | Prefix **`X`** = Sight-only (never fake a P/C payroll code) |

---

## 14. Related PRDs

- `GT-implemetation.md` — Steve **XGT001** / salesstrategy@; GT menus  
- `productcacing.md` — cache for executive aggregates  
- `kp-dashboard-prd.md` — consultant detail  
- Daily executive email / `ExecutiveReportMetricsService`  
