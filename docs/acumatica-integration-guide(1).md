# Acumatica Integration Guide
## Backorders · Sales Order Line Items · Inventory Import · Revenue Impact · Email Notifications

> **Version:** 23.200.001 | **Auth:** Cookie/Session | **Format:** JSON REST

---

## Table of Contents

1. [Authentication](#authentication)
2. [Backorders](#1-backorders)
3. [Sales Order Line Items](#2-sales-order-line-items)
4. [Inventory Import](#3-inventory-import)
5. [Fill Rate](#5-fill-rate)
6. [Revenue Lost — Fill Rate & Backorders](#6-revenue-lost--fill-rate--backorders)
7. [Most Affected Accounts by Backorder](#7-most-affected-accounts-by-backorder)
8. [Items vs Revenue Dashboard Panel](#8-items-vs-revenue-dashboard-panel)
9. [Operations Dashboard](#9-operations-dashboard)
10. [Email Notifications](#10-email-notifications)
11. [Implementation Pattern](#11-implementation-in-an-existing-system)
12. [Guardrails](#12-guardrails)

---

## Authentication

> Required before every endpoint call. Sessions expire after ~20 min of inactivity.

### Endpoint
```
POST /entity/auth/login
```

### Payload
```json
{
  "name": "admin",
  "password": "your_password",
  "company": "YourCompanyName",
  "branch": "MAIN"
}
```

### Logout (always call after automation)
```
POST /entity/auth/logout
```

---

## 1. Backorders

### What is a Backorder?
A backorder is created when a sales order line cannot be fully fulfilled from available stock. Acumatica splits the original order into:
- A **shipment** for the available quantity
- A **backorder** for the remaining open quantity (`OpenQty > 0`)

### Navigation in Acumatica UI
```
Distribution > Sales Orders > Sales Orders
  └── Filter by Status: Backorder
        └── Open Sales Order
              └── Shipping tab → Shipments grid
```

### Acumatica Menu Path
```
Main Menu
  └── Distribution
        └── Sales Orders
              └── Sales Orders  [SO301000]
                    └── [Filter] Status = "Backorder"
```

### API Endpoints

**Fetch all backorders:**
```
GET /entity/Default/23.200.001/SalesOrder?$filter=Status eq 'Backorder'
```

**Fetch open orders with unfulfilled lines:**
```
GET /entity/Default/23.200.001/SalesOrder?$filter=OpenQty gt 0&$expand=Details
```

**Fetch a specific backorder:**
```
GET /entity/Default/23.200.001/SalesOrder/{OrderNbr}?$expand=Details,Shipments
```

### Key Fields

| Field | Type | Description |
|---|---|---|
| `OrderNbr` | string | Sales order number |
| `Status` | enum | `Open`, `Backorder`, `Completed` |
| `OrderedQty` | decimal | Originally ordered quantity |
| `ShippedQty` | decimal | Already shipped |
| `OpenQty` | decimal | Remaining to ship (backorder qty) |
| `RequestedOn` | date | Customer's requested delivery date |
| `ScheduledShipmentDate` | date | Planned fulfillment date |
| `CustomerID` | string | Customer identifier |
| `ShipmentNbr` | string | Linked partial shipment reference |

---

## 2. Sales Order Line Items

### What are Line Items?
Each sales order contains a `Details` array — each element is a product or service line being sold, with its own quantity, price, warehouse, and fulfillment status.

### Navigation in Acumatica UI
```
Distribution > Sales Orders > Sales Orders
  └── Open Sales Order [SO301000]
        └── Details tab
              └── Line Items Grid
                    ├── Inventory ID
                    ├── Description
                    ├── Order Qty / Open Qty
                    ├── Unit Price / Ext. Price
                    └── Warehouse
```

### Acumatica Menu Path
```
Main Menu
  └── Distribution
        └── Sales Orders
              └── Sales Orders  [SO301000]
                    └── Details tab  ← Line Items live here
```

### API Endpoints

**Fetch sales order with line items:**
```
GET /entity/Default/23.200.001/SalesOrder/{OrderNbr}?$expand=Details
```

**Fetch all open sales orders with lines:**
```
GET /entity/Default/23.200.001/SalesOrder?$filter=Status eq 'Open'&$expand=Details
```

**Create a sales order with lines:**
```
POST /entity/Default/23.200.001/SalesOrder
```

**Update a line item:**
```
PUT /entity/Default/23.200.001/SalesOrder
```

### Create Payload
```json
{
  "CustomerID": { "value": "CUST001" },
  "OrderType": { "value": "SO" },
  "Details": [
    {
      "InventoryID": { "value": "ITEM-001" },
      "OrderQty": { "value": 5 },
      "UnitPrice": { "value": 100.00 },
      "WarehouseID": { "value": "MAIN" }
    }
  ]
}
```

### Example Response — Line Item
```json
{
  "LineNbr": { "value": 1 },
  "InventoryID": { "value": "ITEM-001" },
  "OrderQty": { "value": 10 },
  "OpenQty": { "value": 3 },
  "UnitPrice": { "value": 25.00 },
  "ExtPrice": { "value": 250.00 },
  "UOM": { "value": "EA" },
  "WarehouseID": { "value": "MAIN" },
  "TaxCategory": { "value": "TAXABLE" }
}
```

### Key Fields

| Field | Type | Description |
|---|---|---|
| `LineNbr` | int | Position in the order |
| `InventoryID` | string | Stock item code |
| `OrderQty` | decimal | Quantity ordered |
| `OpenQty` | decimal | Unfulfilled quantity |
| `UnitPrice` | decimal | Price per unit |
| `ExtPrice` | decimal | Line total (qty × price) |
| `DiscPct` | decimal | Discount percentage |
| `UOM` | string | Unit of measure (e.g. EA, BOX) |
| `WarehouseID` | string | Fulfillment warehouse |
| `SiteID` | string | Branch/location |
| `TaxCategory` | string | Tax classification |

---

## 3. Inventory Import

### Navigation in Acumatica UI
```
Main Menu
  └── Inventory
        ├── Stock Items  [IN202500]   ← Create/Edit items
        └── Transactions
              └── Inventory Adjustments  [IN304000]   ← Import quantities
```

### Sub-menu for Import
```
Inventory > Stock Items [IN202500]
  └── Import via API (PUT)
        └── Fields: InventoryID, Description, ItemClass, UOM, ValuationMethod

Inventory > Transactions > Inventory Adjustments [IN304000]
  └── Receipt type
        └── Details: InventoryID, Warehouse, Location, Qty, UnitCost
```

### API Endpoints

**Get a stock item:**
```
GET /entity/Default/23.200.001/StockItem/{InventoryID}
```

**Get all stock items:**
```
GET /entity/Default/23.200.001/StockItem
```

**Create or update a stock item (upsert):**
```
PUT /entity/Default/23.200.001/StockItem
```

**Import/adjust inventory quantities:**
```
PUT /entity/Default/23.200.001/InventoryAdjustment
```

### Stock Item Payload
```json
{
  "InventoryID": { "value": "ITEM-NEW" },
  "Description": { "value": "New Product" },
  "ItemClass": { "value": "FINISHED" },
  "DefaultUOM": { "value": "EA" },
  "ValuationMethod": { "value": "Average" },
  "IsStockItem": { "value": true },
  "SalesPrice": { "value": 50.00 },
  "DefaultWarehouseID": { "value": "MAIN" }
}
```

### Inventory Adjustment Payload
```json
{
  "Type": { "value": "Receipt" },
  "Date": { "value": "2026-06-23" },
  "Details": [
    {
      "InventoryID": { "value": "ITEM-001" },
      "Warehouse": { "value": "MAIN" },
      "Location": { "value": "R01S01" },
      "Qty": { "value": 100 },
      "UnitCost": { "value": 10.00 }
    }
  ]
}
```

### Key Fields — Stock Item

| Field | Type | Description |
|---|---|---|
| `InventoryID` | string | Unique item code (natural key) |
| `Description` | string | Item name/description |
| `ItemClass` | string | Classification (FINISHED, RAW, etc.) |
| `DefaultUOM` | string | Base unit of measure |
| `ValuationMethod` | enum | `Average`, `FIFO`, `Standard` ⚠️ immutable after transactions |
| `IsStockItem` | bool | `true` for stock, `false` for non-stock |
| `SalesPrice` | decimal | Default selling price |
| `DefaultWarehouseID` | string | Primary warehouse |

---

## 5. Fill Rate

### What is Fill Rate?
Fill rate measures how much of a customer's order was fulfilled from available stock at the time of shipment. It is distinct from **completion rate** (which only counts fully closed orders) because it captures partial fulfillment — an order shipped at 60% of quantity is invisible to completion rate but shows clearly in fill rate.

> **Formula:** `Fill Rate (%) = (ShippedQty ÷ OrderedQty) × 100`

### Fill Rate vs Completion Rate

| Metric | Measures | Blind spot |
|---|---|---|
| Completion Rate | Orders fully closed | Hides partial shipments |
| Fill Rate | Units shipped vs ordered | Requires line-level data |

### Thresholds

| Range | Status | Action |
|---|---|---|
| ≥ 95% | Healthy | Monitor only |
| 80–94% | At risk | Investigate backorder causes |
| < 80% | Critical | Escalate — likely stock-out or supplier issue |

### Where Fill Rate Appears in the Dashboard
- **Stat card** — overall fill rate across all open orders for the selected date range (MTD by default)
- **Cumulative table** — fill rate column with progress bar per month, sitting next to Completion Rate
- **Per-order rows** — individual bar per order, colour-coded green/amber/red by threshold
- **Trend chart** — dashed teal line on a right-side axis (70–100% scale) overlaid on the order volume chart

### API — Computing Fill Rate per Order

Fill rate is a **derived metric** — Acumatica does not return it directly. Compute it from line-level data:

```javascript
function computeFillRate(order) {
  const lines = order.Details || [];
  const totalOrdered = lines.reduce((sum, l) => sum + (l.OrderQty?.value || 0), 0);
  const totalShipped = lines.reduce((sum, l) => sum + (l.ShippedQty?.value || 0), 0);
  if (totalOrdered === 0) return null;
  return Math.round((totalShipped / totalOrdered) * 1000) / 10; // 1 decimal
}
```

### API — Fetching the Data Needed

```
GET /entity/Default/23.200.001/SalesOrder?$expand=Details
  &$select=OrderNbr,Status,CustomerID,Details/InventoryID,Details/OrderQty,Details/ShippedQty,Details/OpenQty
```

> Use `$select` to limit payload size — fetching all fields on expanded Details is expensive at scale.

### Fill Rate Fields Required per Line

| Field | Source | Notes |
|---|---|---|
| `OrderQty` | `Details[]` | Total units ordered on this line |
| `ShippedQty` | `Details[]` | Units already shipped (from confirmed shipments) |
| `OpenQty` | `Details[]` | `OrderQty - ShippedQty` — useful for cross-check |

### Fill Rate Guardrails

| # | Rule |
|---|---|
| 1 | Always check `OrderQty > 0` before dividing — zero-quantity lines (e.g. KES 0.00 orders) cause divide-by-zero. Return `null` not `0` for these. |
| 2 | Use `ShippedQty`, not `OrderQty - OpenQty`. `OpenQty` can include cancelled lines; `ShippedQty` is the authoritative fulfilled amount. |
| 3 | Compute fill rate at line level and roll up to order level — do not average order-level rates (it misweights large vs small orders). |
| 4 | Orders in `On Hold` or `Pending Approval` status have no confirmed shipments. Show fill rate as `N/A`, not `0%` — a `0%` implies failure when fulfilment hasn't started. |
| 5 | Backorder lines reduce fill rate even when a partial shipment was made. Do not exclude backorder lines from the denominator. |
| 6 | Refresh fill rate after every shipment confirmation event — it is not a static field and changes with each partial fulfilment. |

---

## 6. Revenue Lost — Fill Rate & Backorders

### Concept

Two distinct revenue-loss figures must be tracked and surfaced separately on the dashboard and in alert emails. They use different denominators and answer different questions:

| Metric | Formula | What it measures |
|---|---|---|
| **Revenue lost to fill rate** | `UnshippedQty × UnitPrice` per line, summed across all orders in range | Value of units ordered but not yet shipped — regardless of cause |
| **Revenue lost to backorders** | `OpenQty × UnitPrice` per line on orders with `Status = 'Backorder'` only | Value specifically held up by a confirmed backorder split |

> These two figures will overlap when a backorder has also only been partially shipped. Display them separately. Do not add them together — that double-counts partial backorders.

---

### 6a. Revenue Lost Due to Fill Rate

**Definition:** For every line in scope, revenue lost = `(OrderQty - ShippedQty) × UnitPrice`. This captures all under-delivery, whether caused by backorders, warehouse error, or order holds.

```javascript
function revenueLostFillRate(orders) {
  let total = 0;
  for (const order of orders) {
    if (['On Hold', 'Pending Approval'].includes(order.Status?.value)) continue;
    for (const line of (order.Details || [])) {
      const ordered  = line.OrderQty?.value  || 0;
      const shipped  = line.ShippedQty?.value || 0;
      const price    = line.UnitPrice?.value  || 0;
      if (ordered <= 0) continue;
      total += (ordered - shipped) * price;
    }
  }
  return Math.round(total * 100) / 100;
}
```

**API call to support this:**
```
GET /entity/Default/23.200.001/SalesOrder
  ?$expand=Details
  &$select=OrderNbr,Status,CuryID,Details/OrderQty,Details/ShippedQty,Details/UnitPrice
  &$filter=OrderDate ge '2026-06-01' and Status ne 'Completed'
```

---

### 6b. Revenue Lost Due to Backorders

**Definition:** For every line on a `Status = 'Backorder'` order, revenue at risk = `OpenQty × UnitPrice`. This isolates the value sitting in confirmed backorder queues.

```javascript
function revenueLostBackorders(orders) {
  let total = 0;
  for (const order of orders) {
    if (order.Status?.value !== 'Backorder') continue;
    for (const line of (order.Details || [])) {
      const open  = line.OpenQty?.value  || 0;
      const price = line.UnitPrice?.value || 0;
      if (open <= 0) continue;
      total += open * price;
    }
  }
  return Math.round(total * 100) / 100;
}
```

**API call to support this:**
```
GET /entity/Default/23.200.001/SalesOrder
  ?$filter=Status eq 'Backorder'
  &$expand=Details
  &$select=OrderNbr,CustomerID,CuryID,Details/InventoryID,Details/OpenQty,Details/UnitPrice
```

---

### 6c. Dashboard Stat Cards

Add two new stat cards to the executive summary row:

| Card label | Value | Color threshold |
|---|---|---|
| Revenue at risk (fill rate) | `KES X,XXX,XXX` | Amber if > KES 500k, Red if > KES 1M |
| Revenue at risk (backorders) | `KES X,XXX,XXX` | Amber if > KES 250k, Red if > KES 500k |

Thresholds are configurable via environment variables `FILLRATE_AMBER_KES`, `FILLRATE_RED_KES`, `BACKORDER_AMBER_KES`, `BACKORDER_RED_KES`.

---

## 7. Most Affected Accounts by Backorder

### Concept

Rank accounts by total `OpenQty × UnitPrice` across all their active backorder lines. Surface the top N accounts so operations and account management can prioritise outreach.

### Computation

```javascript
function mostAffectedAccounts(backorders, topN = 10) {
  const map = {};
  for (const order of backorders) {
    if (order.Status?.value !== 'Backorder') continue;
    const custId = order.CustomerID?.value;
    if (!custId) continue;
    if (!map[custId]) map[custId] = { customerId: custId, revenueLost: 0, openLines: 0, orderCount: 0 };
    map[custId].orderCount += 1;
    for (const line of (order.Details || [])) {
      const open  = line.OpenQty?.value  || 0;
      const price = line.UnitPrice?.value || 0;
      if (open > 0) {
        map[custId].revenueLost += open * price;
        map[custId].openLines  += 1;
      }
    }
  }
  return Object.values(map)
    .sort((a, b) => b.revenueLost - a.revenueLost)
    .slice(0, topN)
    .map(r => ({ ...r, revenueLost: Math.round(r.revenueLost * 100) / 100 }));
}
```

### Dashboard Panel — Most Affected Accounts

The panel renders as a grouped accordion table:

```
┌─────────────────────────────────────────────────────────────────────┐
│  Most Affected Accounts — Backorders           [Filter: All / MTD]  │
├────────────────────┬──────────┬────────────┬──────────┬─────────────┤
│ Account            │ Orders   │ Open Lines │ Fill Rate│ Rev at Risk │
├────────────────────┼──────────┼────────────┼──────────┼─────────────┤
│ ▶ CARREFOUR        │ 12       │ 34         │ 61%  🔴  │ KES 820,400 │
│ ▶ NAIVAS NAIROBI   │  8       │ 21         │ 74%  🟡  │ KES 540,100 │
│ ▶ QUICKMART        │  5       │ 11         │ 82%  🟡  │ KES 310,750 │
└────────────────────┴──────────┴────────────┴──────────┴─────────────┘
```

Clicking a row (▶) expands to show the sub-accounts and individual order lines for that main account group.

### Accordion Grouping Logic

Main accounts are the parent entities in your CRM/Acumatica customer hierarchy. Sub-accounts are branches, sites, or child customer IDs that roll up to the main account.

```javascript
function groupByMainAccount(affectedAccounts, customerHierarchy) {
  // customerHierarchy: { [subAccountId]: mainAccountId }
  const groups = {};
  for (const acct of affectedAccounts) {
    const main = customerHierarchy[acct.customerId] || acct.customerId;
    if (!groups[main]) groups[main] = { mainAccount: main, children: [], totals: { revenueLost: 0, openLines: 0, orderCount: 0 } };
    groups[main].children.push(acct);
    groups[main].totals.revenueLost += acct.revenueLost;
    groups[main].totals.openLines   += acct.openLines;
    groups[main].totals.orderCount  += acct.orderCount;
  }
  return Object.values(groups).sort((a, b) => b.totals.revenueLost - a.totals.revenueLost);
}
```

> If `customerHierarchy` is not available from Acumatica, use the first segment of `CustomerID` before a `-` or space as a grouping heuristic (e.g. `NAIVAS-NBI` and `NAIVAS-MSA` both group under `NAIVAS`).

---

## 8. Items vs Revenue Dashboard Panel

### Concept

This panel answers: **which items are responsible for the most backorder revenue loss, and how many units are stuck?** It surfaces the intersection of item volume (units) and financial impact (KES), letting operations teams prioritise stock replenishment by revenue consequence rather than volume alone.

### Computation

```javascript
function itemsVsRevenueLost(backorders) {
  const map = {};
  for (const order of backorders) {
    if (order.Status?.value !== 'Backorder') continue;
    for (const line of (order.Details || [])) {
      const itemId = line.InventoryID?.value;
      const open   = line.OpenQty?.value   || 0;
      const price  = line.UnitPrice?.value || 0;
      if (!itemId || open <= 0) continue;
      if (!map[itemId]) map[itemId] = { inventoryId: itemId, unitsStuck: 0, revenueLost: 0, affectedOrders: new Set() };
      map[itemId].unitsStuck    += open;
      map[itemId].revenueLost   += open * price;
      map[itemId].affectedOrders.add(order.OrderNbr?.value);
    }
  }
  return Object.values(map)
    .map(r => ({ ...r, affectedOrders: r.affectedOrders.size, revenueLost: Math.round(r.revenueLost * 100) / 100 }))
    .sort((a, b) => b.revenueLost - a.revenueLost);
}
```

### Dashboard Panel Layout

```
┌──────────────────────────────────────────────────────────────────────────┐
│  Items vs Revenue Lost — Backorders        [Sort: Revenue ▼ / Units ▼]  │
├────────────────────┬────────────┬──────────────┬────────────┬────────────┤
│ Item               │ Units Stuck│ Orders Affected│ Unit Price│ Rev Lost  │
├────────────────────┼────────────┼──────────────┼────────────┼────────────┤
│ TT-2PLY-WHITE-48   │ 2,400      │ 12           │ KES 620    │ KES 1.49M │
│ TT-3PLY-COSY-24    │   980      │  8           │ KES 890    │ KES 872k  │
│ SERV-DINNER-150    │ 1,100      │  5           │ KES 440    │ KES 484k  │
│ KT-ROLL-2PLY-6     │   750      │  6           │ KES 380    │ KES 285k  │
└────────────────────┴────────────┴──────────────┴────────────┴────────────┘
```

The panel supports two sort modes toggled by the user:
- **Sort by revenue lost** — default, shows highest financial impact first
- **Sort by units stuck** — shows highest volume backlog first (useful for operations)

A sparkline or small bar chart alongside each row visualises the proportion of that item's total ordered qty that is stuck in backorder.

---

## 10. Email Notifications

### Overview

Automated email alerts are sent on a configurable schedule (default: daily at 07:00 local time) and on event triggers (e.g. fill rate drops below threshold). All emails use a consistent template structure and reference the same computed metrics as the dashboard.

### Notification Types

| Trigger | Recipients | Subject line pattern |
|---|---|---|
| Daily summary | Operations team, executive list | `[Daily] Order Operations Summary — {date}` |
| Fill rate below amber (< 95%) | Ops manager | `[Alert] Fill rate at {X}% — action needed` |
| Fill rate below red (< 80%) | Ops manager + MD | `[URGENT] Fill rate critical: {X}% — {date}` |
| New backorder created | Account manager for customer | `[Backorder] {CustomerID} — {OrderNbr} requires attention` |
| Top 3 accounts at risk | Commercial director | `[Weekly] Highest backorder exposure accounts` |
| Revenue at risk exceeds threshold | MD + CFO | `[Alert] Revenue at risk: KES {amount} in backorders` |

---

### Email Payload Structure

```javascript
const emailPayload = {
  to: recipients,          // string[]
  subject: subjectLine,    // string
  html: buildEmailHtml({
    reportDate:         '2026-06-23',
    fillRate:           87.4,             // overall % for the period
    revenueLostFillRate: 1_340_000,       // KES — unfilled order value
    revenueLostBackorder: 820_000,        // KES — confirmed backorder value
    backorderCount:     23,               // active backorder orders
    topAccounts:        affectedAccounts.slice(0, 5),
    topItems:           itemsVsRevenue.slice(0, 5),
    dateRange:          { from: '2026-06-01', to: '2026-06-23' }
  })
};
```

---

### Email HTML Template — Key Sections

Build the HTML body with these blocks in order:

**1. Header bar** — date, period, overall fill rate badge (green/amber/red)

**2. Revenue at risk summary (2 stat cards)**
```
┌───────────────────────────┐  ┌───────────────────────────┐
│ Revenue at risk           │  │ Revenue at risk           │
│ (fill rate gap)           │  │ (backorders)              │
│ KES 1,340,000  🟡         │  │ KES 820,000   🔴          │
└───────────────────────────┘  └───────────────────────────┘
```

**3. Top 5 most affected accounts table** (CustomerID, open lines, rev at risk)

**4. Top 5 items with highest backorder revenue loss** (InventoryID, units, rev)

**5. Footer** — link to full dashboard, generated timestamp, unsubscribe link

---

### Sending via Node (SMTP / SendGrid)

```javascript
const nodemailer = require('nodemailer');

const transporter = nodemailer.createTransport({
  host:   process.env.SMTP_HOST,
  port:   587,
  secure: false,
  auth: {
    user: process.env.SMTP_USER,
    pass: process.env.SMTP_PASS
  }
});

async function sendOpsAlert(payload) {
  const info = await transporter.sendMail({
    from:    '"Ops Alerts" <ops-alerts@yourdomain.com>',
    to:      payload.to.join(', '),
    subject: payload.subject,
    html:    payload.html
  });
  console.log('Alert sent:', info.messageId);
}
```

> For SendGrid, replace `nodemailer` with `@sendgrid/mail` and swap `transporter.sendMail` for `sgMail.send`. The payload shape is identical.

---

### Scheduling (cron)

```javascript
const cron = require('node-cron');

// Daily at 07:00 Nairobi time (EAT = UTC+3)
cron.schedule('0 4 * * *', async () => {
  const data = await fetchDashboardMetrics();
  await sendDailySummary(data);
}, { timezone: 'Africa/Nairobi' });

// Check fill rate every 30 minutes during business hours
cron.schedule('*/30 6-18 * * 1-5', async () => {
  const rate = await getCurrentFillRate();
  if (rate < 80)      await sendUrgentAlert(rate);
  else if (rate < 95) await sendAmberAlert(rate);
}, { timezone: 'Africa/Nairobi' });
```

---

## 9. Operations Dashboard

### Overview
The Operations Dashboard provides a real-time view of order status, fulfilment health, and daily throughput. It is built on data pulled from the Acumatica Sales Order and Shipment endpoints and surfaces three layers of fill rate visibility: global stat card, monthly table, and per-order row.

### Stat Cards

| Card | Value | Source |
|---|---|---|
| Total orders | Count of all SO records in range | `SalesOrder?$filter=OrderDate ge ...` |
| Open orders | `Status eq 'Open'` | Filtered SalesOrder list |
| Completed | `Status eq 'Completed'` | Filtered SalesOrder list |
| Pending approval | `Status eq 'Pending Approval'` | Filtered SalesOrder list |
| Shipping | `Status eq 'Shipping'` | Filtered SalesOrder list |
| Rejected | `Status eq 'Rejected'` | Filtered SalesOrder list |
| On hold | `Status eq 'On Hold'` | Filtered SalesOrder list |
| **Fill rate** | `ΣShippedQty ÷ ΣOrderedQty × 100` | Computed from expanded Details |
| **Line items** | Count of all `Details[]` lines | Computed from expanded Details |
| Avg / day | Total orders ÷ active days in range | Derived |

### Dashboard — Acumatica Menu Path
```
Main Menu
  └── Distribution
        └── Sales Orders
              └── Sales Orders  [SO301000]
                    ├── [Filter] Date Range
                    ├── [Filter] Status (multi-select)
                    └── Details tab → line-level ShippedQty / OrderQty
```

### Cumulative Table Columns

| Column | Formula | Notes |
|---|---|---|
| Month | Calendar month | Grouped by `OrderDate` month |
| Total | Count of orders | — |
| Open | Count where `Status = Open` | — |
| Pending approval | Count where `Status = Pending Approval` | — |
| Shipping | Count where `Status = Shipping` | — |
| Completed | Count where `Status = Completed` | — |
| Rejected | Count where `Status = Rejected` | — |
| On hold | Count where `Status = On Hold` | — |
| Completion rate | `Completed ÷ Total × 100` | Order-level metric |
| **Fill rate** | `ΣShippedQty ÷ ΣOrderedQty × 100` | Line-level metric — more accurate |
| **Line items** | Count of all `Details[]` rows | Total SKU lines in the period |

### Per-Order Fill Rate in the Order List

Each order row in the dashboard shows:
- A progress bar (`ShippedQty ÷ OrderedQty`) coloured by threshold
- The percentage value to 1 decimal place
- `N/A` for orders with zero ordered quantity or where fulfilment has not started

### Trend Chart — Dual Axis

The order volume trend chart overlays fill rate on a **right-side Y axis** (range 70–100%) alongside the order volume lines on the left axis. This allows correlation of volume spikes with fill rate dips, which typically signal demand outpacing stock availability.

### Dashboard Guardrails

| # | Rule |
|---|---|
| 1 | Never display fill rate on orders with `Status = On Hold` or `Pending Approval` as a percentage — show `N/A`. These orders have no confirmed shipments and a `0%` misleads. |
| 2 | Always scope the dashboard query to a date range. An unbounded `GET /SalesOrder?$expand=Details` query will time out on large tenants. |
| 3 | Cache stat card values — do not re-query on every page render. Use a server-side cache with a 5-minute TTL, or a background refresh job. |
| 4 | The fill rate stat card and trend line must use the same denominator (all orders in range, not just completed). Mixing scope produces inconsistent numbers. |
| 5 | Round all displayed percentages to 1 decimal place. Raw float division (`91.333333...`) must be formatted before display to avoid visual noise. |
| 6 | Line item count (`Details[]` length) and fill rate must be fetched in the same API call (`$expand=Details`) to avoid double-hitting the server. |

---

## 11. Implementation in an Existing System

### Full Integration Pattern (JavaScript/Node)

```javascript
const BASE_URL = 'https://your-instance.acumatica.com';
let sessionCookie = null;

// ── 1. AUTHENTICATE ──────────────────────────────────────────
async function login() {
  const res = await fetch(`${BASE_URL}/entity/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'include',
    body: JSON.stringify({
      name: process.env.ACUMATICA_USER,
      password: process.env.ACUMATICA_PASS,
      company: process.env.ACUMATICA_COMPANY,
      branch: 'MAIN'
    })
  });
  if (!res.ok) throw new Error('Login failed');
  sessionCookie = res.headers.get('set-cookie');
}

// ── 2. BACKORDERS ────────────────────────────────────────────
async function getBackorders() {
  const res = await fetch(
    `${BASE_URL}/entity/Default/23.200.001/SalesOrder` +
    `?$filter=Status eq 'Backorder'&$expand=Details`,
    { headers: { Cookie: sessionCookie } }
  );
  return res.json();
}

// ── 3. SALES ORDER LINE ITEMS ─────────────────────────────────
async function getSalesOrderLines(orderNbr) {
  const res = await fetch(
    `${BASE_URL}/entity/Default/23.200.001/SalesOrder/${orderNbr}?$expand=Details`,
    { headers: { Cookie: sessionCookie } }
  );
  return res.json();
}

// ── 4. INVENTORY IMPORT ───────────────────────────────────────
async function importInventoryItem(item) {
  const res = await fetch(
    `${BASE_URL}/entity/Default/23.200.001/StockItem`,
    {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
        Cookie: sessionCookie
      },
      body: JSON.stringify(item)
    }
  );
  if (!res.ok) throw new Error(`Import failed: ${res.status}`);
  return res.json();
}

async function adjustInventoryQty(payload) {
  const res = await fetch(
    `${BASE_URL}/entity/Default/23.200.001/InventoryAdjustment`,
    {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
        Cookie: sessionCookie
      },
      body: JSON.stringify(payload)
    }
  );
  return res.json();
}

// ── 5. REVENUE LOST — FILL RATE ─────────────────────────────────
async function getRevenueLostFillRate(fromDate) {
  const res = await fetch(
    `${BASE_URL}/entity/Default/23.200.001/SalesOrder` +
    `?$expand=Details` +
    `&$select=OrderNbr,Status,CuryID,Details/OrderQty,Details/ShippedQty,Details/UnitPrice` +
    `&$filter=OrderDate ge '${fromDate}' and Status ne 'Completed'`,
    { headers: { Cookie: sessionCookie } }
  );
  if (!res.ok) throw new Error(`Fill rate query failed: ${res.status}`);
  const orders = await res.json();
  let total = 0;
  for (const order of orders) {
    if (['On Hold', 'Pending Approval'].includes(order.Status?.value)) continue;
    for (const line of (order.Details || [])) {
      const ordered = line.OrderQty?.value  || 0;
      const shipped = line.ShippedQty?.value || 0;
      const price   = line.UnitPrice?.value  || 0;
      if (ordered > 0) total += (ordered - shipped) * price;
    }
  }
  return Math.round(total * 100) / 100;
}

// ── 6. REVENUE LOST — BACKORDERS ────────────────────────────────
async function getRevenueLostBackorders() {
  const res = await fetch(
    `${BASE_URL}/entity/Default/23.200.001/SalesOrder` +
    `?$filter=Status eq 'Backorder'` +
    `&$expand=Details` +
    `&$select=OrderNbr,CustomerID,CuryID,Details/InventoryID,Details/OpenQty,Details/UnitPrice`,
    { headers: { Cookie: sessionCookie } }
  );
  if (!res.ok) throw new Error(`Backorder query failed: ${res.status}`);
  const orders = await res.json();
  let total = 0;
  for (const order of orders) {
    for (const line of (order.Details || [])) {
      const open  = line.OpenQty?.value  || 0;
      const price = line.UnitPrice?.value || 0;
      if (open > 0) total += open * price;
    }
  }
  return Math.round(total * 100) / 100;
}

// ── 7. MOST AFFECTED ACCOUNTS ────────────────────────────────────
async function getMostAffectedAccounts(topN = 10) {
  const res = await fetch(
    `${BASE_URL}/entity/Default/23.200.001/SalesOrder` +
    `?$filter=Status eq 'Backorder'` +
    `&$expand=Details` +
    `&$select=OrderNbr,CustomerID,Details/OpenQty,Details/UnitPrice`,
    { headers: { Cookie: sessionCookie } }
  );
  if (!res.ok) throw new Error(`Account query failed: ${res.status}`);
  const orders = await res.json();
  const map = {};
  for (const order of orders) {
    const custId = order.CustomerID?.value;
    if (!custId) continue;
    if (!map[custId]) map[custId] = { customerId: custId, revenueLost: 0, openLines: 0, orderCount: 0 };
    map[custId].orderCount += 1;
    for (const line of (order.Details || [])) {
      const open  = line.OpenQty?.value  || 0;
      const price = line.UnitPrice?.value || 0;
      if (open > 0) { map[custId].revenueLost += open * price; map[custId].openLines += 1; }
    }
  }
  return Object.values(map)
    .sort((a, b) => b.revenueLost - a.revenueLost)
    .slice(0, topN)
    .map(r => ({ ...r, revenueLost: Math.round(r.revenueLost * 100) / 100 }));
}

// ── 8. ITEMS VS REVENUE ──────────────────────────────────────────
async function getItemsVsRevenueLost() {
  const res = await fetch(
    `${BASE_URL}/entity/Default/23.200.001/SalesOrder` +
    `?$filter=Status eq 'Backorder'` +
    `&$expand=Details` +
    `&$select=OrderNbr,Details/InventoryID,Details/OpenQty,Details/UnitPrice`,
    { headers: { Cookie: sessionCookie } }
  );
  if (!res.ok) throw new Error(`Items query failed: ${res.status}`);
  const orders = await res.json();
  const map = {};
  for (const order of orders) {
    for (const line of (order.Details || [])) {
      const itemId = line.InventoryID?.value;
      const open   = line.OpenQty?.value   || 0;
      const price  = line.UnitPrice?.value || 0;
      if (!itemId || open <= 0) continue;
      if (!map[itemId]) map[itemId] = { inventoryId: itemId, unitsStuck: 0, revenueLost: 0, affectedOrders: new Set() };
      map[itemId].unitsStuck  += open;
      map[itemId].revenueLost += open * price;
      map[itemId].affectedOrders.add(order.OrderNbr?.value);
    }
  }
  return Object.values(map)
    .map(r => ({ ...r, affectedOrders: r.affectedOrders.size, revenueLost: Math.round(r.revenueLost * 100) / 100 }))
    .sort((a, b) => b.revenueLost - a.revenueLost);
}


async function logout() {
  await fetch(`${BASE_URL}/entity/auth/logout`, {
    method: 'POST',
    headers: { Cookie: sessionCookie }
  });
  sessionCookie = null;
}

// ── ORCHESTRATOR ──────────────────────────────────────────────
async function runIntegration() {
  try {
    await login();

    const backorders = await getBackorders();
    console.log(`Found ${backorders.length} backorders`);

    const orderLines = await getSalesOrderLines('SO-000123');
    console.log(`Lines on SO-000123:`, orderLines.Details?.length);

    const fromDate = new Date(new Date().getFullYear(), new Date().getMonth(), 1)
      .toISOString().split('T')[0]; // first of current month

    const [revFillRate, revBackorder, topAccounts, topItems] = await Promise.all([
      getRevenueLostFillRate(fromDate),
      getRevenueLostBackorders(),
      getMostAffectedAccounts(10),
      getItemsVsRevenueLost()
    ]);

    console.log(`Revenue at risk (fill rate gap): KES ${revFillRate.toLocaleString()}`);
    console.log(`Revenue at risk (backorders):    KES ${revBackorder.toLocaleString()}`);
    console.log(`Top affected account: ${topAccounts[0]?.customerId} — KES ${topAccounts[0]?.revenueLost.toLocaleString()}`);
    console.log(`Worst item by rev lost: ${topItems[0]?.inventoryId} — ${topItems[0]?.unitsStuck} units — KES ${topItems[0]?.revenueLost.toLocaleString()}`);

    await importInventoryItem({
      InventoryID: { value: 'NEW-ITEM' },
      Description: { value: 'Imported Product' },
      DefaultUOM: { value: 'EA' },
      ValuationMethod: { value: 'Average' },
      IsStockItem: { value: true },
      DefaultWarehouseID: { value: 'MAIN' }
    });

  } catch (err) {
    console.error('Integration error:', err);
  } finally {
    await logout();
  }
}
```

---

## 12. Guardrails

### API Guardrails

| # | Guardrail | Rule |
|---|---|---|
| 1 | **API version lock** | Always pin to `23.200.001`. Never use a dynamic/latest path — endpoint contracts change between versions. |
| 2 | **Session timeout** | Sessions expire ~20 min idle. Implement auto-re-login with retry on `401 Unauthorized`. |
| 3 | **Rate limiting** | Chunk bulk imports to 100–500 records/request. Add `await delay(500)` between calls. |
| 4 | **Field value wrapping** | Every field **must** be `{ "value": ... }`. Flat JSON is silently ignored — no error, no write. |
| 5 | **PUT is upsert** | `PUT` matches on natural key (`InventoryID`, `OrderNbr`). Validate existence before assuming create vs update. |
| 6 | **Selective expand** | Only use `$expand=Details` on single-record GETs. Expanding on list queries is costly. Filter first, expand after. |
| 7 | **Date format** | Always ISO 8601: `YYYY-MM-DD`. Never send locale-formatted dates (`23/06/2026` will fail silently). |
| 8 | **Dry run first** | Test any import with 1 record. Check response for `error` and `warnings` arrays before batch runs. |
| 9 | **Always logout** | Call `POST /entity/auth/logout` after every automation run to release server-side sessions. |
| 10 | **No parallel sessions** | Do not run parallel threads on the same session cookie. Queue serially or use one cookie per thread. |
| 11 | **Use $select with $expand** | When fetching orders with `$expand=Details`, always add `$select` to limit fields returned. Unrestricted expand on large orders returns megabytes of unused data and risks timeout. |
| 12 | **Handle empty arrays** | `Details` may return `[]` on orders with no lines (e.g. KES 0.00 orders). Always null-check before iterating — do not assume at least one line. |
| 13 | **Check HTTP status before parsing** | Always validate `response.ok` before calling `response.json()`. A 500 or 422 error body is not valid JSON in some Acumatica builds and will throw a parse error. |
| 14 | **Paginate large result sets** | Use `$top` and `$skip` for any list query that could return more than 500 records. Acumatica will truncate silently at its server-side page limit without warning. |
| 15 | **Log request IDs** | Capture the `X-Request-ID` response header on every API call. It is required when raising support tickets and dramatically reduces diagnosis time. |

### Business Logic Guardrails

| # | Area | Rule |
|---|---|---|
| 1 | **Backorders** | Never auto-cancel a backorder without checking `OpenQty`. Partial shipments may already exist against the line. |
| 2 | **Line items** | Modifying `OrderQty` requires the order to be in `Open` status. Always check `Status` before PUT. |
| 3 | **Inventory qty** | Never set `QtyOnHand` directly. Use `InventoryAdjustment` — direct overrides bypass costing and audit trails. |
| 4 | **Valuation method** | Set `ValuationMethod` at item creation. It cannot be changed after any transactions exist on the item. |
| 5 | **Warehouse required** | Every line item and adjustment must reference a valid `WarehouseID`. Omitting it causes silent failures. |
| 6 | **Credentials** | Never hardcode credentials. Always use environment variables or a secrets manager. |
| 7 | **Order status before edit** | Always read `Status` before any PUT on a Sales Order. Orders in `Pending Approval`, `On Hold`, or `Completed` states reject edits silently or return a 422. |
| 8 | **Do not delete orders** | Use status transitions (`On Hold`, `Cancelled`) rather than DELETE. Deleted orders leave orphaned shipment records and break audit trails. |
| 9 | **Currency consistency** | Acumatica stores amounts in the order's currency (e.g. KES). Never mix currency when aggregating totals across orders — check `CuryID` per order before summing. |
| 10 | **Line number stability** | `LineNbr` is positional and can change if lines are inserted or deleted. Never store `LineNbr` as a stable external reference — use `InventoryID` + `OrderNbr` as the composite key. |

### Fill Rate Guardrails

| # | Rule |
|---|---|
| 1 | Check `OrderQty > 0` before dividing. Zero-quantity lines cause divide-by-zero. Return `null`, not `0`. |
| 2 | Use `ShippedQty`, not `OrderQty - OpenQty`. `OpenQty` can include cancelled lines; `ShippedQty` is the authoritative fulfilled amount. |
| 3 | Compute fill rate at line level and roll up to order level. Do not average order-level rates — it misweights large vs small orders. |
| 4 | Orders in `On Hold` or `Pending Approval` have no confirmed shipments. Display `N/A`, not `0%`. |
| 5 | Backorder lines reduce fill rate even when a partial shipment was made. Do not exclude backorder lines from the denominator. |
| 6 | Refresh fill rate after every shipment confirmation event — it is not a static field. |
| 7 | Never display fill rate and completion rate as interchangeable. Add tooltips or labels that explain each metric's formula and scope wherever both appear together. |

### Revenue Loss Guardrails

| # | Rule |
|---|---|
| 1 | Never add `revenueLostFillRate` and `revenueLostBackorder` together. Backorder lines that have been partially shipped appear in both — summing them double-counts. Display the two figures separately and label each clearly. |
| 2 | Always filter out `Status = 'Completed'` orders from fill rate revenue loss. A completed order with `ShippedQty < OrderQty` means the remaining qty was cancelled, not lost. |
| 3 | Use `UnitPrice` from the line item, not the order header `OrderTotal ÷ TotalQty`. Per-line price is the only accurate basis for line-level revenue loss. |
| 4 | Always check `CuryID` before summing revenue across orders. An order in USD mixed into a KES total produces silent, catastrophically wrong figures. |
| 5 | Revenue at risk figures are point-in-time snapshots. Never cache them for more than 15 minutes on a live dashboard — partial shipments reduce them without warning. |
| 6 | When displaying `revenueLostFillRate`, label it clearly as "revenue not yet shipped" — not "revenue lost". The units may still ship; calling it lost is factually wrong and alarming to commercial teams. |
| 7 | Zero-price lines (`UnitPrice = 0`) should be excluded from revenue loss calculations. They are typically sample or internal transfer lines and skew per-item and per-account rankings. |

### Most Affected Accounts Guardrails

| # | Rule |
|---|---|
| 1 | Rank accounts by `OpenQty × UnitPrice` (revenue), not by backorder count. High-volume, low-value accounts would otherwise displace strategically important accounts. |
| 2 | If customer hierarchy data is unavailable, group by `CustomerID` prefix (up to the first `-` or space). Document this heuristic in code comments — it must not be promoted to a permanent data model. |
| 3 | Sub-account rows in the accordion must inherit the main account's fill rate colour threshold. Do not compute separate thresholds per sub-account — it creates inconsistency in a single expanded row. |
| 4 | Never display a customer's revenue-at-risk figure in an email without also showing the date range it covers. A bare `KES 820,000` without context is unactionable and may cause unnecessary escalation. |
| 5 | Account-level fill rate is the weighted average across all their backorder lines, not a simple average of their per-order fill rates. |

### Items vs Revenue Guardrails

| # | Rule |
|---|---|
| 1 | Default sort is by `revenueLost` descending. Never default to `unitsStuck` — high-volume, low-price items dominate volume sorts and can mislead prioritisation. |
| 2 | An item appearing in the top 5 by revenue loss but not by volume is a signal of a high-price item in short supply — flag it with a visual indicator on the dashboard (e.g. a price-high badge). |
| 3 | The sparkline showing "proportion of total ordered qty in backorder" must use the item's total `OrderQty` across all orders in the date range as its denominator — not `unitsStuck` alone. |
| 4 | Item IDs from `Details[].InventoryID` must not be enriched with description from memory. Always fetch `StockItem/{InventoryID}` for the display name, or pre-build a lookup table on page load. |
| 5 | Items with `OpenQty > 0` on `Status = 'Completed'` orders are data anomalies — log them separately and exclude from the revenue loss panel. Do not surface anomalies in the dashboard without a separate "data quality" alert. |

### Email Notification Guardrails

| # | Rule |
|---|---|
| 1 | Always include the date range covered in every email subject and body. A fill rate figure without a period is uninterpretable. |
| 2 | Never send a red alert email more than once per hour for the same metric. Implement deduplication with a short-circuit flag (`lastAlertSentAt`) in Redis or a simple flat file. |
| 3 | Round all KES amounts to the nearest 1,000 in email bodies. Raw figures like `KES 820,417.33` are harder to scan than `KES 820k` in an executive-facing email. |
| 4 | Do not include individual customer names or order numbers in group distribution emails. Send customer-specific backorder alerts only to the relevant account manager. |
| 5 | Test the email template against both dark-mode and light-mode email clients before production. Use inline CSS only — `<style>` blocks are stripped by Outlook and Gmail. |
| 6 | All recipient lists must be loaded from environment config, never hardcoded. Use `EMAIL_OPS_LIST`, `EMAIL_EXEC_LIST`, `EMAIL_MD`, `EMAIL_CFO` as env var keys. |
| 7 | The daily summary must not send if the Acumatica API call fails. Catch errors before building the email — a blank or partially populated email is worse than no email. |
| 8 | Include a one-line "recommended action" in every alert email based on the trigger: fill rate amber → "Review backorder queue"; fill rate red → "Escalate to supply chain"; revenue threshold → "Executive review required". |

---

## Quick Reference — Endpoint Summary

| Function | Method | Endpoint |
|---|---|---|
| Login | POST | `/entity/auth/login` |
| Logout | POST | `/entity/auth/logout` |
| Get backorders | GET | `/entity/Default/23.200.001/SalesOrder?$filter=Status eq 'Backorder'` |
| Get sales order + lines | GET | `/entity/Default/23.200.001/SalesOrder/{OrderNbr}?$expand=Details` |
| Get orders for fill rate | GET | `/entity/Default/23.200.001/SalesOrder?$expand=Details&$select=OrderNbr,Status,Details/OrderQty,Details/ShippedQty,Details/OpenQty` |
| Revenue lost — fill rate | GET | `/entity/Default/23.200.001/SalesOrder?$expand=Details&$select=OrderNbr,Status,CuryID,Details/OrderQty,Details/ShippedQty,Details/UnitPrice&$filter=Status ne 'Completed'` |
| Revenue lost — backorders | GET | `/entity/Default/23.200.001/SalesOrder?$filter=Status eq 'Backorder'&$expand=Details&$select=OrderNbr,CustomerID,CuryID,Details/InventoryID,Details/OpenQty,Details/UnitPrice` |
| Create sales order | POST | `/entity/Default/23.200.001/SalesOrder` |
| Get stock item | GET | `/entity/Default/23.200.001/StockItem/{InventoryID}` |
| Upsert stock item | PUT | `/entity/Default/23.200.001/StockItem` |
| Adjust inventory qty | PUT | `/entity/Default/23.200.001/InventoryAdjustment` |

---

## Formula Quick Reference

```
Fill Rate (%) = (ShippedQty ÷ OrderedQty) × 100

Revenue Lost — Fill Rate  = Σ (OrderQty - ShippedQty) × UnitPrice
                            across all non-Completed, non-Hold lines

Revenue Lost — Backorders = Σ OpenQty × UnitPrice
                            across all lines where Status = 'Backorder'

⚠ Do NOT sum these two figures together — backorder lines overlap with fill rate lines.

Thresholds — Fill Rate:
  ≥ 95%    →  Green   (healthy)
  80–94%   →  Amber   (at risk)
  < 80%    →  Red     (critical)

Thresholds — Revenue at Risk (Backorders):
  < KES 250k   →  Green
  KES 250–500k →  Amber
  > KES 500k   →  Red

N/A when:
  OrderedQty = 0
  Status = On Hold
  Status = Pending Approval
```

---

*Generated: June 2026 | Acumatica REST API v23.200.001*
*Sections: Authentication · Backorders · Sales Order Lines · Inventory Import · Fill Rate · Revenue Lost · Most Affected Accounts · Items vs Revenue · Operations Dashboard · Email Notifications · Implementation · Guardrails*
