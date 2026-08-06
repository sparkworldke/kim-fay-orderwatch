# Mobile / Smartphone — Site-Wide Considerations

**Product:** Kim-Fay Sight  
**Scope:** All authenticated app surfaces (OrderWatch, Sales Intelligence, KP, GT, Production, Executive, Admin)  
**Status:** Draft PRD  
**Stack notes:** React + TanStack Router/Query · shadcn sidebar/sheet · existing PWA (`manifest.webmanifest`, service worker, install prompt)  

---

## 1. Goal

Make Sight feel like a **phone app**, not a shrunken desktop:

1. **Super responsive** on every page (phone → phablet → tablet → desktop).  
2. Prefer **cards / stacked lists** over wide tables on small screens.  
3. **Touch-first** targets, safe areas, and one-thumb navigation.  
4. Keep **field roles** productive offline-ish (PWA) for portfolio, GT, FOL, orders glance.

Desktop power users keep dense tables; phone users get **app-like views** of the same data.

---

## 2. Breakpoints (standardize)

| Token | Width | Layout intent |
|---|---|---|
| **xs / default** | &lt; 640px | Phone portrait — single column, cards, bottom-friendly actions |
| **sm** | ≥ 640px | Large phone / small landscape — 2-col KPI grids |
| **md** | ≥ 768px | Tablet — sidebar can expand; hybrid table/card |
| **lg+** | ≥ 1024px | Desktop — full sidebar, multi-column tables |

Use Tailwind `sm:` / `md:` / `lg:` consistently; avoid page-specific one-off widths.

---

## 3. App-like shell (site-wide)

### 3.1 Navigation

| Desktop | Mobile |
|---|---|
| Persistent sidebar | **Drawer** (existing `SidebarTrigger` + sheet) |
| Multi-level groups expanded | Accordion groups; one section open preferred |
| Hover tooltips | Long-press optional; labels always visible in drawer |

**Rules:**

- Logo + page title visible in header on mobile (`md:hidden` logo pattern already started).  
- Drawer closes after navigating to a leaf route.  
- Active item clearly highlighted.  
- Deep trees (KP CRM, DTC, Production) use nested accordion, not horizontal overflow menus.

### 3.2 Header

- Compact height (~48–56px).  
- Primary: menu trigger · title · account menu.  
- Secondary actions (sync, fullscreen, theme) in **overflow** on phone — not a crowded icon row.  
- Impersonation badge must wrap or shorten on narrow screens.

### 3.3 Floating / fixed UI

| Element | Mobile rule |
|---|---|
| AI assistant FAB | Stay above home indicator; offset from bottom nav if any (`bottom-20` pattern already) |
| Chat panel | Full-width sheet on phone, not fixed 380px floating card |
| Toasts | Top or bottom safe inset; don’t cover primary CTA |
| PWA install prompt | Bottom sheet; dismissible; one-time |

### 3.4 Bottom actions (optional V2)

For high-frequency field pages (My Portfolio, FOL, GT Revenue): sticky **bottom action bar** (Call / New FOL / Refresh) with safe-area padding.

---

## 4. Content patterns: cards over tables

### 4.1 Principle

> On viewports **&lt; md**, replace wide data tables with **card lists** (or stacked “definition rows”).  
> On **md+**, keep tables (or table + optional card toggle).

TanStack Table (or existing tables) should support a **`viewMode`**: `table | cards` driven by breakpoint (and optional user toggle on tablet).

### 4.2 Card anatomy (standard)

```text
┌─────────────────────────────────────┐
│ Title (customer / SO / SKU)    badge│
│ Subtitle (id · status · date)       │
│ ─────────────────────────────────── │
│ Metric row: KES · qty · days        │
│ ─────────────────────────────────── │
│ [ Primary action ]  [ ⋯ more ]      │
└─────────────────────────────────────┘
```

- One primary action visible; rest in overflow.  
- Tap card body → detail route (not only tiny links).  
- Use `MaskedKES` consistently for money.

### 4.3 What becomes cards on phone

| Surface | Mobile presentation |
|---|---|
| Operations Dashboard SO list | Card per SO: number, customer, amount, qty; expand accordion for lines |
| My Portfolio customers | Card: name, buying status, days inactive, sales MTD, next action |
| Backorders | Card per line or per inventory group |
| Fill rate / not delivered | Card per order or SKU |
| Orders index | Card per order |
| KP dormant / items not ordered | Card list |
| Team / consultants | Card per rep |
| Executive segment chips | 2×3 grid of chips (already number tiles) |
| GT Revenue and Orders | KPI cards + card list by rep/region |
| Admin tables | Cards or horizontal scroll **only if unavoidable**; prefer filter + card |
| Production inventory | Card per SKU (status color bar) |

### 4.4 Tables when kept

If a table remains on small screens:

- Horizontal scroll with sticky first column (identity).  
- Hide low-priority columns (`hidden md:table-cell`).  
- Min touch height 44px for rows.  
- Never rely on hover-only actions.

---

## 5. Page-type guidelines

### 5.1 KPI / dashboard pages

- KPI strip: **2 columns** on phone, 4 on `lg`.  
- Charts: full width, height ~180–220px; simplify legends.  
- Filters: collapsible “Filters” sheet/panel (not 6 dropdowns in a row).

### 5.2 Filter bars (site-wide pattern)

```text
[ Filters ▾ ]  [ Period ]  [ Search ]
```

Opening **Filters** → bottom sheet or full-screen sheet with stacked fields and Apply / Clear.

### 5.3 Forms (contacts, FOL, PCR, admin)

- Single column.  
- Labels above inputs.  
- Required `*` always visible.  
- Date pickers native-friendly.  
- Submit sticky at bottom of sheet/page.  
- Avoid multi-column `sm:grid-cols-2` for critical fields on xs (optional 2-col from `sm`).

### 5.4 Detail / accordion (SO lines, portfolio)

- Accordion triggers full-width, large tap target.  
- Nested tables inside accordion → **inner cards** on phone.  
- Brand-scoped amount/qty readable without horizontal scroll.

### 5.5 Executive View

- Already numbers-first: stack totals → segment chip grid (2×3) → trend → gaps.  
- Segment chips min height 64px, tappable.  
- Trend full width after tap.

### 5.6 Maps / calendar / FOL calendar

- Full-width calendar; day list below month on phone.  
- Avoid side-by-side calendar + list until `md`.

---

## 6. Typography, density, touch

| Rule | Spec |
|---|---|
| Body | ≥ 14px effective on phone (avoid `text-[8px]` dashboard density on mobile — scale up under `max-md`) |
| Dense desktop dashboards | Allowed on `md+` only; phone uses comfortable density |
| Tap targets | ≥ 44×44px |
| Spacing | 12–16px card padding; 8px gaps in lists |
| Contrast | Status colors + text label (not color alone) |
| Safe area | `env(safe-area-inset-*)` on fixed header/footer/FAB |

**Dashboard exception:** Operations Dashboard currently uses very small type for density — on mobile, switch to **comfortable card density** (section §4), not a microscopic table.

---

## 7. Performance on mobile networks

| Practice | Why |
|---|---|
| Respect caching PRD (references, filter-options) | Fewer round-trips on 4G |
| Paginate card lists | Avoid 500-row DOM |
| Lazy-load SO line accordion | Same as desktop P0 |
| Images / logos compressed | PWA install + header |
| Avoid auto-refresh storms | Manual refresh + staleTime |
| Skeleton loaders | Perceived performance |

---

## 8. PWA / “install as app”

Already present: manifest, SW, install prompt.

| Requirement | Spec |
|---|---|
| Add to Home Screen | Keep install prompt; iOS share instructions |
| Standalone display | No browser chrome; test header/FAB safe areas |
| Orientation | `any` (portrait primary for field) |
| Offline | Soft: shell + last cached portfolio/filter options; hard offline orders sync out of scope V1 |
| Icons | Prefer square 192/512 maskable icons (improve on current wide logo-only icon) |

---

## 9. Role-based mobile priority

| Role | Phone-critical journeys |
|---|---|
| Sales consultant / KP | Portfolio priorities, call customer, dormant, FOL submit |
| GT field | Revenue glance, customer/outlet list, SFA later |
| HOD / Steve | Team rollup cards, not full admin |
| Executive | Executive one-pager only |
| Ops / warehouse | Backorders + inventory cards |
| Admin | Prefer desktop; mobile = monitor + light approvals |

---

## 10. Accessibility & input

- Support dynamic type / large system font without breaking cards.  
- `inputmode` for phone/email/numeric.  
- Autocomplete attributes on login.  
- Don’t trap focus inside off-screen drawers.  
- `tel:` / `mailto:` links for contact actions on portfolio/FOL.

---

## 11. What “done” means per page (checklist)

Use this when reviewing any page:

- [ ] Usable at 360×740 without horizontal page scroll (except intentional table scroll region).  
- [ ] Primary task completable with thumb.  
- [ ] Filters accessible in ≤ 2 taps.  
- [ ] No hover-only actions.  
- [ ] Loading and empty states readable.  
- [ ] Amounts/status not clipped.  
- [ ] Nav drawer works and closes on navigate.  
- [ ] Works in PWA standalone on iOS + Android Chrome.

---

## 12. Implementation approach

### P0 — Shell + worst offenders

1. Global mobile density tokens (scale up micro text under `md`).  
2. Filter bottom-sheet pattern reused.  
3. Operations Dashboard day/SO lists → cards on phone.  
4. Portfolio customer list → cards.  
5. AI assistant full-screen sheet on xs.

### P1 — High-traffic modules

6. Orders, backorders, not-delivered, dormant → cards.  
7. FOL list + submit form mobile polish.  
8. Executive view chip grid polish.  
9. Sticky primary CTAs where needed.

### P2 — Site-wide table adapter

10. Shared `ResponsiveDataView` (table on md+, cards on xs) for TanStack/table UIs.  
11. Admin pages adopt adapter.  
12. PWA icons + safe-area audit.

### P3 — Field pack

13. Optional bottom nav for consultant subset (Home / Portfolio / FOL / More).  
14. Offline shell + cached references.

---

## 13. Anti-patterns (do not ship)

- Desktop table with `text-[8px]` forced onto phone.  
- Six filters in one horizontal row on xs.  
- Modals wider than viewport.  
- Fixed elements covering iOS home indicator.  
- Tap targets only on icon glyphs without padding.  
- “Works on my iPad” as the only QA.

---

## 14. QA matrix (minimum)

| Device class | Browsers |
|---|---|
| Android phone 360–412 CSS px | Chrome |
| iPhone SE / 13 size | Safari |
| PWA standalone | Both |
| Tablet 768 | Chrome / Safari |

Critical paths: login → executive or portfolio → open order/customer → FOL or call action → logout.

---

## 15. Related PRDs

| Doc | Mobile angle |
|---|---|
| `executive-view.md` | Stacked numbers; large chips |
| `kp-dashboard-prd.md` | Priorities + card customer list |
| `GT-implemetation.md` | Field GT menus; card revenue lists |
| `partner-brands-show-amounts.md` | SO accordion → inner cards on phone |
| `customer-details.md` | Contact forms single column |
| `productcacing.md` | Faster mobile loads |

---

## 16. Acceptance (site-wide)

- [ ] Every main nav destination is usable on a 360px-wide phone.  
- [ ] Phone uses **card/list app patterns** for major indexes; desktop keeps tables.  
- [ ] Filters open in a sheet/stack, not a squeezed toolbar.  
- [ ] Touch targets ≥ 44px; safe areas respected.  
- [ ] PWA install + standalone navigation work.  
- [ ] Field roles can complete top tasks without pinch-zoom.  
- [ ] No page requires landscape-only to function.  
