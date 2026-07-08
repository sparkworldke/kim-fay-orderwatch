# Product Requirements Document (PRD): Order Fill Rate, Backorder & Delivery SLA Dashboard

**Version:** 1.1 (Updated with Time to Deliver SLA, Zones & Guardrails)  
**Date:** July 06, 2026  
**Author:** Grok (xAI) for Kimfay / Kim-Fay Operations  
**Business Context:** Kim-Fay (Fay Tissues, Huggies, Duracell, Dove, etc.) – Multi-region distribution (Nairobi focus + Coast/MSA)

---

## 1. Executive Summary
Build a comprehensive **Operations Intelligence Dashboard** in **React + Laravel** that pulls live data from Acumatica. It covers:

- **Fill Rate** performance
- **Backorders** tracking
- **Time to Deliver (TTD) / Delivery SLA** compliance with region-specific targets

**New in v1.1:**
- Dedicated **Time to Deliver** tab with SLA breakdowns (Nairobi & MSA = **24 hours** target; Other regions configurable).
- **Delayed Orders** count and impact analysis.
- **Most Affected Zones** identification (Nairobi sub-zones + Coast regions).
- Strong **Guardrails** for data quality, SLA definitions, and alerting.

**Primary Goals:**
- Real-time visibility into fulfillment health.
- Pinpoint bottlenecks in delivery performance by zone/region.
- Proactive alerts on SLA breaches.
- Replace manual Excel reporting.

---

## 2. Key Metrics & Definitions

### Fill Rate (Existing + Enhanced)
- **Formula:** (Actual Sales Value / Total Order Value) × 100
- Breakdown by Customer Group, Department, Reason (Stock Out dominant).

### Time to Deliver (TTD) / Delivery SLA (New Core Feature)
- **SLA Targets (Configurable in Admin):**
  - **Nairobi**: 24 hours from order confirmation/ship date.
  - **MSA (Mombasa/Coast)**: 24 hours.
  - **Other Regions** (e.g., upcountry, Western, Rift Valley): 48–72 hours (default; admin configurable per region or customer group).
- **Delayed Order**: Any order where actual delivery/ship date > SLA target.
- **Metrics:**
  - Total orders in period
  - Orders On-Time (%)
  - **Orders Delayed** (count + % + value impact)
  - Average TTD (hours/days)
  - SLA Compliance Trend

### Zones & Affected Areas (New)
- **Nairobi Zones** (derive or map from customer master/branch):
  - Examples: Westlands, CBD, Kasarani, Thika Road, Lavington, Ngara, Diamond Plaza, Two Rivers, Sarit, Junction, etc.
  - Group into: Central Nairobi, West Nairobi, East Nairobi, North Nairobi, South Nairobi.
- **Coast / MSA Zones**:
  - Nyali, Kilimani (Mombasa), Mombasa Island, Changamwe, etc. + broader Coast branches.
- **Most Affected Zone**: Highest % of delayed orders or highest delayed value in the period.
- Drill-down: Click zone → list of delayed orders + reasons.

**Zone Codes for Mapping (Seeding Data)**:
- Z001 - Westlands (Nairobi)
- Z002 - CBD (Nairobi)
- Z003 - Ngong (Nairobi)
- Z004 - Thika (Nairobi)
- Z005 - Mombasa Rd (Nairobi)
- Z012 - Mombasa (Coast)
- Z006–Z011: Other regions (Eastern, Mountain, Lake, etc.) – lower priority for now
- Additional codes for Kasarani, Lavington, Nyali, etc. (Nairobi/Coast focus)

**Database Migration Note**: Add `zone_code` (or zone_id) column to relevant tables (customers/orders) with a lookup/seeding migration containing the above mapping.

### Backorders (Existing)
- Count, value, aging, by reason/department.

---

## 3. Dashboard Structure (Tabs)

### Tab 1: Overview (Executive Summary)
- High-level KPIs across all modules.
- Quick links to other tabs.
- Alerts banner (e.g., "12 zones breaching SLA today").

### Tab 2: Fill Rate
- KPIs, trends, breakdowns by reason/department/customer group.
- Detailed table with drill-down.

### Tab 3: Backorders
- Live backorder list, aging, value by reason.
- Top affected customers/products.

### Tab 4: Time to Deliver (TTD) / Delivery SLA **(New Major Tab)**
**Sub-sections / Filters:**
- Region selector: All / Nairobi / MSA-Coast / Other Regions
- SLA Target indicator (prominent badge: "Nairobi & MSA: 24hrs | Others: 48-72hrs")

**Key Visuals & Metrics:**
- **KPI Cards:**
  - Total Orders
  - On-Time Orders (%)
  - **Delayed Orders** (Count + % + Total Value)
  - Average Delivery Time
- **Charts:**
  - SLA Compliance Trend (line chart over time)
  - Delayed Orders by Day/Week
  - Distribution of Delivery Times (histogram)
- **Most Affected Zones Table/Chart** (Top 5-10 zones by % delayed or delayed value):
  - Zone | Total Orders | Delayed Orders | % Delayed | Delayed Value | Avg Delay (hrs) | Primary Reason
  - Highlight top affected (red/amber).
- **Detailed Orders Table** (filterable by zone/region):
  - Columns: Order #, Date, Customer, Zone/Region, Ordered Value, Delivery Date, SLA Target, Hours Delayed, Status, Reason (if available)
- **Actions:**
  - Export delayed orders list
  - "Investigate Zone" button → deep dive modal

**Nairobi vs MSA Specific Views** (toggle or separate cards):
- Nairobi 24hr performance
- MSA 24hr performance
- Comparison (side-by-side)

### Tab 5: Analytics & Root Cause (Combined)
- Cross-tab analysis: Fill Rate + TTD correlation
- Delayed orders by Product / Customer Group / Department
- Heatmap: Zone × Reason (for delays)

### Tab 6: Settings / Admin (Guardrails Configuration)
- Define SLA targets per region
- Zone mapping maintenance
- Alert thresholds (e.g., >15% delayed in any zone triggers notification)
- Data sync status & manual refresh

---

## 4. Data Requirements from Acumatica

**Core Fields Needed (extend existing integration):**
- Order: Reference Nbr, Date, Customer ID/Name/Group, Product Code/Description, Ordered Qty/Value
- Fulfillment: Actual Qty/Value, Undershipped, Status, Reason, Department
- **Delivery/TTD Specific (New):**
  - Order Confirmation / Ship Date
  - Actual Delivery Date or Invoice/Shipment Date (to calculate TTD)
  - Promised Delivery Date (if available)
- **Location/Zone (New):**
  - Customer Region (Nairobi / Coast / Other)
  - Customer Zone / Branch / Area (e.g., "Westlands", "Nyali", "Kasarani")
  - Or derive via lookup table from Customer Name / Address / existing master data

**Data Model Additions (Laravel):**
- `delivery_sla_config` table (region, sla_hours, active)
- `zone_mapping` (customer_id or pattern → zone, region)
- Enhanced `acumatica_orders` with `delivery_date`, `hours_to_deliver`, `sla_met` (boolean), `zone`, `region`

**Guardrails on Data:**
- Only include orders with valid delivery/ship date.
- Exclude cancelled orders or specific types (configurable).
- Flag orders with missing delivery data ("Data Incomplete").
- Minimum order value threshold for SLA tracking (e.g., ignore < KES 5,000 if needed).

---

## 5. Guardrails & Business Rules (Critical)

1. **SLA Definition Guardrails**
   - All SLA targets stored in DB and editable only by authorized roles (Admin/Operations Manager).
   - Default: Nairobi & MSA = 24 hours; Others = 48 hours (with override capability).
   - Clear documentation in UI: "SLA clock starts at Order Confirmation / Ship Date".

2. **Delayed Order Definition**
   - Calculated as: `delivery_date > (ship_date + sla_hours)`
   - Edge cases handled:
     - Same-day deliveries = On Time if within hours.
     - Weekends/holidays: Configurable exclusion or adjusted SLA.
     - Partial shipments: Track per line or order level (decide at implementation).

3. **Zone Identification Guardrails**
   - Use master data mapping (maintainable table).
   - Fallback: Pattern matching on Customer Name (e.g., contains "Nyali" → MSA Nyali zone).
   - Unknown zones grouped as "Unmapped – Review Needed" with alert.

4. **Data Quality Guardrails**
   - Sync validation: Reject/import only if key fields present.
   - Daily data freshness check (last successful sync timestamp visible everywhere).
   - Anomaly detection: Sudden spike in delayed orders → flag for review.
   - Historical backfill: Ability to re-process past periods.

5. **Alerting & Threshold Guardrails**
   - Configurable thresholds:
     - Zone-level: > X% delayed → Email/Slack alert to regional lead.
     - Overall: Daily summary if delayed orders > Y.
   - No false positives: Minimum sample size (e.g., ignore zones with < 10 orders).

6. **Access & Security Guardrails**
   - Role-based:
     - Ops Manager: Full access + zone config.
     - Nairobi Lead: Nairobi + MSA views only.
     - Coast Lead: MSA views only.
   - Audit log for all SLA/zone config changes.

7. **Performance & Technical Guardrails**
   - Cache heavy aggregations (daily).
   - Pagination + server-side filtering on large tables.
   - Rate limiting on Acumatica API calls.

---

## 6. Technical Implementation Notes

**Laravel Backend:**
- Jobs for daily TTD calculation + zone assignment.
- API endpoints:
  - `/api/ttd/summary?region=nairobi`
  - `/api/ttd/delayed-orders`
  - `/api/zones/most-affected`
- Admin panel (Filament or custom) for SLA config & zone mapping.

**React Frontend:**
- Tab navigation (React Router or state-based).
- Reusable components: KPI Card, Zone Impact Table, Delayed Orders DataTable (TanStack Table).
- Charts: SLA trend + Zone bar charts.
- Filters global + tab-specific.
- Modal for order drill-down.

**Deployment Alignment:**
- Continue with your Cloudflare + TanStack + Laravel stack.
- Add Redis for caching SLA calculations.

---

## 7. Phased Rollout (Recommended)

**Phase 1 (MVP – 3 weeks):**
- Fill Rate + Backorders tabs (existing)
- Basic TTD tab for Nairobi & MSA (24hr SLA)
- Delayed orders count + simple zone breakdown (top affected)
- Core guardrails (SLA config, data validation)

**Phase 2 (4 weeks):**
- Full zone mapping & "Most Affected Zone" deep analysis
- Other regions SLA + comparison views
- Alerting system
- Advanced analytics tab

**Phase 3:**
- Predictive elements (forecast delayed zones)
- Mobile optimization
- Integration with WhatsApp/Email alerts for ops team

---

## 8. Open Questions / Decisions Needed
1. Exact start of SLA clock? (Order creation vs confirmation vs ship date)
2. How to handle partial deliveries / multi-line orders?
3. Preferred visualization for "Most Affected Zones" (map vs table vs both)?
4. Holiday/weekend SLA adjustment policy?
5. Minimum order threshold for TTD tracking?

---

**Next Steps**
- Approve this PRD v1.1
- Provide sample Acumatica data with delivery dates / zones (or confirm fields available)
- I can then:
  - Generate detailed DB schema + Laravel migrations
  - Create React component stubs
  - Build wireframes (via image gen if needed)
  - Start coding the backend sync for TTD

This update directly addresses your request for **multiple tabs**, **24hr SLA for Nairobi & MSA**, **delayed orders count**, **most affected zones**, and **guardrails**.

Ready to proceed? Let me know which part to tackle first! 🚀

---

*File saved to: `/home/workdir/artifacts/Order-Fill-Rate-Backorder-Dashboard-PRD.md`*