# Kim-Fay OrderWatch — Management Brief

**Subject:** Kim-Fay OrderWatch — System Overview & Module Guide

---

Dear Team,

I wanted to share a brief overview of **Kim-Fay OrderWatch** — our internal operations intelligence platform — and how the main modules work together to give us real-time visibility into orders, fulfillment, and customer communications.

---

## What is OrderWatch?

OrderWatch is a web-based dashboard that connects **Acumatica ERP** and **Microsoft Outlook** into a single view. It replaces manual Excel reporting and reduces the time spent chasing orders across email and ERP.

The platform automatically syncs data on a schedule, surfaces risks early (backorders, delayed deliveries, unmatched customer POs), and sends a **daily management briefing email** every Tuesday–Saturday at 7:00 AM (East Africa Time).

---

## How the System Works (at a glance)

1. **Data flows in automatically** — Sales orders, inventory, customers, and shipping zones are pulled from Acumatica. Customer PO emails are pulled from Outlook.
2. **The system processes and matches** — PO numbers are extracted from emails and matched to Acumatica sales orders. Fill rate, backorders, and delivery performance are calculated.
3. **Teams review and act** — Dashboards highlight what needs attention. Customer Service confirms email-to-order matches. Management receives the daily summary email.
4. **Alerts fire when thresholds are breached** — Notification rules can email specific people or roles when sync failures, SLA breaches, or other guardrails are triggered.

All scheduled jobs run in the background (no manual intervention required once configured).

---

## Platform Modules

### Overview

| Module | Purpose |
|--------|---------|
| **Dashboard** | Executive snapshot — order volumes by status, trends, fill-rate movement, and drill-down into problem orders. |
| **Orders** | Searchable list of all Acumatica sales orders with status, customer, value, and consultant assignment. |
| **Business Optimization** | Cross-functional view linking fill rate, backorders, delivery SLA, and revenue at risk — highlights top affected customers, products, and zones. |
| **AI Intelligence** | AI-generated operational briefing for any date range — trends, risks, and recommended focus areas. |
| **Customer Feed** | Account-level performance grouped by customer/branch — orders received, emails matched, completion time, and fill rate per account. |

### Operations

| Module | Purpose |
|--------|---------|
| **Credit Notes & More** | Tracks non-standard order types (credit notes, returns, etc.) separate from standard sales orders. |
| **Inventory** | Live stock levels synced from Acumatica across warehouses — supports shortage analysis driving backorders. |
| **Backorders** | Open/unfulfilled order lines with aging, value, and reason breakdown (e.g. stock-out). |
| **Fill Rate** | Measures fulfillment performance: *(Actual Sales Value ÷ Total Order Value) × 100*, broken down by customer group, department, and reason. |
| **Zones** | Shipping zone master data from Acumatica with delivery SLA targets (e.g. Nairobi & Mombasa = 24 hours; other regions configurable). |
| **Customers** | Customer master synced from Acumatica — linked to zones, categories, and order history. |
| **Sales Consultants** | Consultant performance view — orders and outcomes scoped to each rep's portfolio. |

### Workflow

| Module | Purpose |
|--------|---------|
| **Order Match** | Links customer PO emails to Acumatica sales orders. AI suggests matches; Customer Service accepts or rejects. Full audit trail. |
| **Mailbox** | Outlook inbox view — synced emails, PO extraction status, and ingestion decisions (include/exclude from matching). |

### System

| Module | Purpose |
|--------|---------|
| **Administration** | Central control panel — Acumatica connection, sync operations, cron jobs, notification rules, team members, roles, AI keys, and audit logs. |
| **Sales Order Imports** | Manual/CSV import path for sales orders (e.g. consultant-submitted orders). |
| **Profile** | User account settings and password management. |

---

## Automated Background Processes

These run on schedule without manual action:

| Process | Frequency | What it does |
|---------|-----------|--------------|
| Sales Order Sync | Every 2 hours | Pulls new/updated orders from Acumatica |
| Email Sync | Every 2 hours (alternating) | Pulls new customer PO emails from Outlook |
| Order Matching | Every 3 hours | Extracts PO numbers and proposes Acumatica matches |
| Order Status Sync | Every 30 minutes | Lightweight status refresh for open orders |
| Inventory Sync | Twice daily (8 AM & 12 PM) | Refreshes stock levels |
| Fill Rate Calculation | Daily (midnight + noon) | Recomputes fill-rate snapshots |
| Backorder Processing | Daily (12:30 AM) | Validates and classifies backorder lines |
| Sync Monitor Alerts | Every minute | Emails alerts on sync failures or guardrail breaches |
| **Daily Management Report** | **Tue–Sat, 7:00 AM** | **AI-powered email summarising yesterday's performance, MTD context, and risks** |
| System Health Check | Daily (6:00 AM) | Technical health report to the tech lead |

All jobs can also be triggered manually from **Administration → Cron Jobs**.

---

## User Roles & Access

Access is role-based so each team sees only what they need:

| Role | Typical access |
|------|----------------|
| **Administrator** | Full access — all modules, sync controls, user management |
| **Customer Service Manager** | Orders, matching, mailbox, operations views; limited admin |
| **Customer Service Agent** | Day-to-day order and mailbox workflow |
| **Sales Operations** | Operations dashboards, fill rate, backorders, zones |
| **Sales Consultant** | Own portfolio — orders and customers scoped to their rep code |
| **Executive** | Read-only overview — dashboard, business optimization, AI intelligence |

---

## Key Integrations

- **Acumatica ERP** — Sales orders, customers, inventory, shipping zones, fill rate, backorders
- **Microsoft Outlook (Graph API)** — Customer PO email ingestion and matching
- **AI (Claude)** — PO extraction, order-match suggestions, daily report commentary, and intelligence briefings

---

## What This Means for Management

OrderWatch gives us:

- **Real-time fulfillment health** instead of end-of-week Excel reports
- **Early warning** on backorders, SLA breaches, and unmatched customer POs
- **Accountability** — performance by customer, zone, consultant, and department
- **A daily briefing email** so leadership starts each working day with yesterday's numbers and AI commentary
- **Audit trail** — every email-to-order match is logged and reviewable

The system is live and syncing. If you would like a walkthrough of any specific module, or to adjust who receives the daily report or alert notifications, please let me know.

Best regards,  
[Your Name]  
[Your Title]