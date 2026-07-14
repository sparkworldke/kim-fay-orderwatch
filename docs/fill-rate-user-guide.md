# Fill Rate Module — User & Maintenance Guide

**Last updated:** 8 Jul 2026  
**Audience:** Operations managers, customer service leads, and system administrators

---

## Overview

The Fill Rate module in OrderWatch tracks how completely sales orders are delivered. Data is synced from **Acumatica** on a schedule; in project documentation this module is also referred to as the **Tally** fill-rate feed. There is **no separate Tally ERP connector** — Acumatica is the source of truth and OrderWatch stores computed snapshots locally.

---

## Daily Workflow

1. **Review the Fill Rate list** — filter by date range, shipping zone, status (healthy / critical), or reason code.
2. **Open an order** — click the order number or customer link to inspect line-level detail.
3. **Assign reasons** — capture why lines were not fully shipped using the reason taxonomy (parent → sub-reason).
4. **Export to Excel** (managers only) — download the workbook for leadership review or offline analysis.

---

## Excel Export

### Who can export

Fill-rate Excel export is restricted to **Administrator** and **Customer Service Manager** roles. Other roles can view the on-screen list but cannot download the workbook.

### How to export

1. Open **Fill Rate** in OrderWatch.
2. Set your date range and any filters (zone, reason, status).
3. Click **Export to Excel**.

### Workbook sheets

| Sheet | Purpose |
|-------|---------|
| **Guide** | Sheet index, brand rules, KP/CS rules, and column definitions |
| **Summary** | Executive KPIs: total lost sales, manufactured vs trading split, SO shortfall counts, top SKUs |
| **Fill Rate** | Order-level fill rate rows for the filtered period |
| **Product Lines** | Line-level detail with reason codes and lost-sales value |
| **SOs Not Fully Delivered** | Orders below 100% fill rate, sorted by value shortfall |
| **Missing Price Values** | Lines with zero or missing unit price (data-quality flag) |
| **Reason Summary** | Root-cause contribution by reason |
| **Customer Summary** | Top customers by lost sales |
| **Product Summary** | Top products by lost sales |

### Summary sheet KPIs

- **Total Lost Sales** — sum of unfilled line value across the export.
- **Manufactured Goods / Trading (Partners) Goods** — split by product business category (see below).
- **SOs Not Fully Delivered** — count of incomplete orders vs total in range.
- **Revenue Shortfall** — order-level revenue not shipped.
- **Lines w/ Missing Price** — lines needing price correction in Acumatica.
- **SKUs Affected** — distinct inventory IDs with lost sales.

---

## KP vs CS Classification

Orders and customers are split into two commercial sectors for reporting:

| Segment | Rule |
|---------|------|
| **KP (Kimfay Professional)** | Customer class starts with `KP` (case-insensitive) |
| **CS (Consumer Sales)** | All other customer classes |

There is no “unclassified” bucket — every customer falls into KP or CS. The UI label **Kimfay Professional** is used instead of “Key Partner” in customer-facing screens.

The Excel **Summary** sheet includes a KP/CS sector split and a root-cause breakdown mapped to each segment.

---

## Manufactured vs Trading (Product Category)

Product lines are classified for the manufactured / trading split:

- **Manufactured** — Kimfay-produced goods (determined from inventory metadata and brand classifier).
- **Trading (Partners)** — partner / distributed goods.

Classification appears in product listings (Brand, Posting Class, Sub Trading Group, Supplier) and in the Excel Summary manufactured vs trading tiles.

---

## Reason Taxonomy

Partial-delivery reasons use a **parent → sub-reason** hierarchy (33 seeded reasons). Reasons can be:

- **Captured from Acumatica** during sync (when present on the SO payload).
- **Set manually** in OrderWatch on backorder or fill-rate lines.

Common parent groups include procurement stock-outs, delivery delays, customer-requested changes, and pricing issues. Use the in-app reason picker to select the closest sub-reason; add notes when the standard code needs context.

### Maintenance commands

```bash
# Seed or refresh the reason taxonomy
php artisan so-reasons:seed-taxonomy

# Audit how well reasons are being captured on recent orders
php artisan audit:so-reason-capture
```

---

## Inventory Classification (Brand / Supplier)

Product sidebars and listings show **Brand**, **Posting Class**, **Sub Trading Group**, and **Supplier** from `acumatica_inventory_items`. If these fields are sparse after Acumatica sync, populate them from the BI export:

```bash
cd backend
php artisan inventory:seed-from-bi --path="../docs/data/Stock Items BI(Data).csv"
```

Use `--dry-run` first to preview creates/updates/rejects without writing to the database.

---

## Acumatica Sync (= Tally Module)

Scheduled jobs pull sales orders, lines, inventory, and shipping zones from Acumatica. The fill-rate calculator then:

- Computes fill-rate % per line and order.
- Flags critical vs healthy status.
- Derives delivery SLA breach for metro zones (24-hour rule).
- Parses workflow reason fields where available.

**Stakeholder note:** If the original spec required a *separate* Tally ERP integration, that has **not** been built. Current behaviour satisfies “sync fill-rate data into OrderWatch (Tally module)” via Acumatica. Confirm with business owners whether any additional Tally system still needs a direct API.

---

## Related Roles & Pages

| Page | Notes |
|------|-------|
| Fill Rate | Main module; filters, export, product sheet |
| Backorders | Related open-qty view; shared reason codes |
| Inventory | SKU detail panel; `?sku=` deep link from product cells |
| Sales Consultants | Rep-level customer tables with fill rate and revenue lost |

---

## Support Checklist

- [ ] Date range includes `computed_at` / `order_date` for snapshots.
- [ ] User has admin or manager role for Excel export.
- [ ] Brand/supplier columns empty → run `inventory:seed-from-bi`.
- [ ] Reason gaps → run `audit:so-reason-capture` and review Acumatica SO fields.
- [ ] Flaky test DB issues → shipping zones `Z001`–`Z005` are seeded by migration; tests must upsert, not blind insert.