# HorecaOS — Product Requirements Document

**Version:** 1.0 · April 2026
**Status:** Draft — pending stakeholder sign-off
**Client:** KimFay East Africa (primary) · Farmers Choice · Quality Meat Packers
**Stack:** ReactJS 18 · Laravel 11 · Flutter 3 · MySQL 8 · Redis
**UI Inspiration:** Stripe Dashboard — data-first, minimal chrome, generous whitespace
**Delivery:** Phase 1 MVP in 8 weeks · Full platform in 36 weeks

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Objectives & Success Metrics](#2-objectives--success-metrics)
3. [Scope](#3-scope)
4. [Target Users & Personas](#4-target-users--personas)
5. [Functional Requirements](#5-functional-requirements)
   - 5.1 [Meeting Management & PJP](#51-meeting-management--pjp)
   - 5.2 [Order Management](#52-order-management)
   - 5.3 [Payment Management & Collections](#53-payment-management--collections)
   - 5.4 [Price Change Request Workflow](#54-price-change-request-workflow)
   - 5.5 [FOL Dispenser Lifecycle](#55-fol-dispenser-lifecycle)
6. [Non-Functional Requirements](#6-non-functional-requirements)
7. [UI Design Principles — Stripe Inspired](#7-ui-design-principles--stripe-inspired)
8. [Product Catalogue — HORECA Presentation](#8-product-catalogue--horeca-presentation)
9. [Delivery Roadmap](#9-delivery-roadmap)
10. [Platform Guardrails](#10-platform-guardrails)
11. [Commercial Packages](#11-commercial-packages)
12. [Integration Registry](#12-integration-registry)
13. [Open Questions](#13-open-questions--decisions-required)
14. [Sign-Off](#14-document-sign-off)

---

## 1. Executive Summary

HorecaOS is an end-to-end B2B field-sales and operations platform for FMCG, food manufacturing, and hygiene brands operating in the East Africa HORECA channel. It replaces manual journey planning, WhatsApp-based ordering, and spreadsheet payment tracking with a unified **web application** (managers and admin) and a **Flutter mobile app** (field sales reps).

The platform is built on four pillars drawn directly from the KimFay Professional Workflow & Requirements Document (April 2026):

- **Field Execution** — automated Planned Journey Plans, GPS check-in, structured meeting capture
- **Order Management** — LPO-based ordering with intelligent suggestions and fulfilment visibility
- **Revenue Protection** — payment aging, collection push, credit customer management
- **Governance** — price change request approvals and Free-on-Loan (FOL) dispenser asset lifecycle

The primary go-live client is **KimFay East Africa**. The architecture is multi-tenant SaaS from Phase 4, enabling Farmers Choice, Quality Meat Packers (QMP), and other HORECA brands to onboard on the same platform.

---

## 2. Objectives & Success Metrics

### 2.1 Business Objectives

- Achieve a minimum of **4 verified customer meetings per rep per day** through the automated PJP engine
- Reduce order booking time from field visits by **60%** versus current WhatsApp/phone process
- Reduce overdue payment days outstanding (DSO) by **20%** within 6 months of go-live
- Achieve **100% audit-trail coverage** on price changes and FOL approvals
- Enable onboarding of Farmers Choice and QMP as tenant clients within **12 months** of Phase 1 launch

### 2.2 Key Performance Indicators

| KPI | Baseline | 6-Month Target | Owner |
|---|---|---|---|
| Meetings per rep per day | 2.1 avg | ≥ 4.0 | Sales Ops |
| Order booking time (field) | ~18 min | ≤ 7 min | Product |
| Payment DSO (credit customers) | 42 days | ≤ 34 days | Finance |
| FOL dispenser approval cycle | 5–7 days | ≤ 2 days | Ops |
| Price change turnaround | 3–5 days | Same day | Sales Mgmt |
| GPS check-in accuracy | Manual | ≥ 98% verified | Engineering |

---

## 3. Scope

### 3.1 In Scope

- Web Application — manager dashboard, approval workflows, reporting, ERP configuration
- Mobile Application (Flutter) — field rep interface, offline-first, GPS-enabled
- REST API — shared backend for web and mobile, webhook support for ERP triggers
- ERP Integration layer — Sage Business Cloud, SAP Business One, Odoo 17 (configurable)
- 5 Core Workflow Modules — Meeting, Order, Payment, Price Change, FOL Dispenser
- Product Catalogue — HORECA-grade product presentation with SKU variants and pricing tiers
- Multi-tenant SaaS architecture (Phase 4 — Farmers Choice, QMP onboarding)

### 3.2 Out of Scope (v1.0)

- Direct e-commerce storefront for end customers
- Accounting / bookkeeping functionality (delegated to ERP)
- Driver dispatch or last-mile logistics routing
- WhatsApp chatbot ordering interface (post-v1 backlog)
- Custom mobile hardware (barcode scanners, POS terminals)

### 3.3 Assumptions

- Client has an existing ERP system with a documented API or data export format
- Field reps carry Android or iOS smartphones running OS ≥ Android 10 / iOS 15
- Network connectivity in the field is intermittent — offline-first is a hard requirement, not a nice-to-have
- Customer master data (names, contacts, credit limits, payment terms) lives in the ERP and is the system of record

---

## 4. Target Users & Personas

| Persona | Role | Primary Device | Core Need |
|---|---|---|---|
| **Field Rep** | Sales representative visiting HORECA customers | Mobile (Flutter) | Capture meetings, log orders, collect payments on the go — offline-capable |
| **Line Manager** | Area / regional sales manager | Web + Mobile | Approve price changes, monitor rep activity, review FOL requests |
| **Senior Management** | GM, Sales Director | Web dashboard | P&L visibility, KPI dashboards, trend reports, exception alerts |
| **Admin / Finance** | Credit controller, finance team | Web | Payment aging, credit customer management, ERP data reconciliation |
| **Operations** | Warehouse / logistics ops | Web | FOL dispenser asset tracking, fulfilment status, maintenance scheduling |

---

## 5. Functional Requirements

---

### 5.1 Meeting Management & PJP

> **Module:** Field Execution · **Delivery:** Phase 1 · **Priority:** P0

Automates the Planned Journey Plan, replacing manual scheduling with a system-driven daily visit list optimised by territory and priority.

#### Features

| Feature | Description | Priority | Phase |
|---|---|---|---|
| PJP engine | Auto-generate daily visit schedule per rep, targeting ≥ 4 meetings | P0 | 1 |
| GPS check-in | Verify rep location at customer site on meeting start | P0 | 1 |
| Meeting output capture | 4 structured questions per meeting; supports text, photo, and voice note | P0 | 1 |
| Action item tracking | Log, assign, and follow up on action items from prior meetings | P1 | 1 |
| Route optimisation | Google Maps route sequencing to minimise travel time | P1 | 2 |
| Meeting performance report | Daily/weekly: meetings done vs target, action close rate | P0 | 1 |
| Automated scheduler | Manager sets customer visit frequency (weekly / fortnightly / monthly) | P1 | 2 |

#### 🛡️ Guardrails — Meeting & GPS

> **GPS check-in is a critical control point. All four measures below are mandatory for Phase 1 launch.**

- Cross-validate device GPS with cell-tower triangulation; flag if delta > 200m
- Enforce minimum **3-minute dwell time** at location before meeting can be marked complete
- Manager alert triggered automatically when GPS coordinates are implausible (speed > 120 km/h between two check-ins)
- All GPS coordinates stored with device timestamp, server timestamp, and accuracy radius for full audit
- Location data collected **only during configured working hours** (default: 07:00–18:00 Mon–Sat; configurable by admin)
- Reps must receive written notice that location is tracked during working hours — **consent logged in system before first app use**
- Location never shared with customers or third parties; visible only to the rep's line manager and admin

---

### 5.2 Order Management

> **Module:** Commercial · **Delivery:** Phases 1–2 · **Priority:** P0

Covers the full order lifecycle from intelligent suggestion through to fulfilment tracking, with LPO as the primary booking mechanism.

#### Features

| Feature | Description | Priority | Phase |
|---|---|---|---|
| LPO order booking | Rep books order against a Local Purchase Order; supports partial quantities | P0 | 1 |
| Order follow-up | Automated status updates to rep and manager at 24h and 48h | P0 | 2 |
| Fulfilment visibility | Show order progress: confirmed → picking → dispatched → delivered | P0 | 2 |
| Back-order handling | Flag SKUs on back-order with ETA; prevent rep from promising delivery | P0 | 2 |
| Account-on-hold detection | Pull credit hold status from ERP; block new orders and alert rep | P0 | 2 |
| Dynamic quantity edits | Customer requests quantity change up to dispatch; rep approves | P1 | 2 |
| Sales metrics dashboard | Opportunities, lead closings, VAS attach rate, range-selling score | P1 | 3 |
| AI order suggestion engine | Suggestions based on purchase history and seasonality | P1 | 4 |

#### 🛡️ Guardrails — Orders

- Account-on-hold status pulled from ERP in **real time** at order creation — not cached
- Back-order SKUs must display a **system-generated ETA** — reps must not manually enter delivery dates
- Order quantity changes after dispatch trigger a new approval step; cannot be edited silently
- All order events (creation, edit, cancellation) are timestamped and attributed to the rep — no anonymous edits
- VAT codes must be validated against the ERP product master before order submission; **orders with missing tax codes are blocked, not queued**
- Multi-currency orders (KES, USD, UGX, TZS) must store both the transaction currency and the exchange rate at booking time

---

### 5.3 Payment Management & Collections

> **Module:** Finance · **Delivery:** Phase 2 · **Priority:** P0

Provides structured tools for tracking outstanding payments, managing credit customers, and pushing collections through automated and manual mechanisms.

#### Features

| Feature | Description | Priority | Phase |
|---|---|---|---|
| Payment aging dashboard | Real-time aging buckets: current, 30, 60, 90+ days | P0 | 2 |
| Credit customer management | Credit limits, payment terms, utilisation % synced from ERP | P0 | 2 |
| Collection push — in-app | Rep logs a promise-to-pay date and amount | P0 | 2 |
| Collection push — SMS | Automated payment reminder SMS via Africa's Talking / Twilio | P1 | 2 |
| M-Pesa integration | Customer pays via M-Pesa Daraja API; auto-reconciles against invoice | P1 | 3 |
| Overdue escalation | Auto-escalate to manager if payment overdue > 14 days past terms | P0 | 2 |
| Payment audit trail | Every promise, payment, and escalation timestamped and immutable | P0 | 2 |

#### 🛡️ Guardrails — Payments

- Every payment event written to an **append-only audit log** — no UPDATE or DELETE permitted on payment tables
- Promise-to-pay dates must be confirmed by the customer contact (name + phone captured); reps cannot log unverified promises
- M-Pesa reconciliation must match on **both amount and transaction reference** — partial matches flagged for manual review, never auto-applied
- Overdue escalation logic defined in system config — not hardcoded; finance team must be able to change thresholds without a code deploy
- SMS reminders require **opt-in consent** captured at customer onboarding; unsubscribe handled via reply keyword

---

### 5.4 Price Change Request Workflow

> **Module:** Governance · **Delivery:** Phase 3 · **Priority:** P1

A structured, approval-gated workflow for customer-initiated price change requests, with full margin visibility and ERP write-back on approval.

#### Workflow Steps

| Step | Actor | Action | System Guardrail |
|---|---|---|---|
| 1 | Customer | Requests price change | Request can only be submitted against an active account; blocked accounts cannot initiate |
| 2 | Sales Rep | Submits PCR with justification | Mandatory: customer, SKU, current price, proposed price, business justification. System auto-calculates margin impact. |
| 3 | Line Manager | Reviews margin impact | Margin floor breach alert if proposed price falls below agreed threshold. Cannot override without senior approval. |
| 4 | Line Mgr / Sr. Mgmt | Approves or escalates | Within manager's authority threshold → manager approves. Above threshold → auto-escalated. SLA timer: 24h before escalation alert. |
| 5 | System (ERP) | Writes new price with effective date | ERP update includes: new price, effective date, approver name, approval timestamp. Immutable audit record created. |
| 6 | System | Notifies all parties | Push notification + email to rep and customer. Rejection includes mandatory reason field. |

#### 🛡️ Guardrails — Price Changes

- Approval authority thresholds (KES value) stored **server-side only** — never in JWT, mobile bundle, or client-side config
- **MFA mandatory** for all roles with approval authority (line manager and above)
- Margin impact is calculated server-side at submission — rep-entered prices are not trusted without system validation
- Every price change carries an immutable audit trail: who submitted, who reviewed, who approved/rejected, timestamps, and margin delta at each step
- ERP price write-back uses a **staging buffer with a 5-minute validation window** before committing — allows rollback if ERP rejects
- Duplicate submissions (same customer + SKU within 48h) are flagged and require manager acknowledgement before proceeding
- Rejected requests must include a **mandatory reason field** — "Rejected" with no context is not valid

---

### 5.5 FOL Dispenser Lifecycle

> **Module:** Asset Management · **Delivery:** Phase 3 · **Priority:** P1

Governs the placement of company-owned dispensers at customer sites on a free-on-loan basis, from initial request through volume tracking to recall.

#### Workflow Steps

| Step | Actor | Action | Guardrail |
|---|---|---|---|
| 1 | Customer | Requests dispenser on FOL basis | Only accounts with ≥ 3 months trading history and good standing can apply |
| 2 | Sales Rep | Submits FOL request with volume commitment | Minimum monthly purchase commitment is mandatory; system enforces floor value |
| 3 | Manager | Reviews against volume, credit, asset availability | Real-time stock count check against asset register; credit standing from ERP |
| 4 | Manager | Approves or rejects | Rejection requires mandatory reason. Approved requests auto-generate loan agreement PDF for e-signature. |
| 5 | Operations | Allocates, installs, registers asset | Dispenser serial number mandatory. Installation photo required before asset goes live in register. |
| 6 | System | Monthly volume vs. commitment review | Auto-flag if actual purchases < 80% of committed volume for 2 consecutive months |
| 7 | Manager | Corrective action or recall | Recall: rep notified → customer notified → collection scheduled → asset status updated |

#### 🛡️ Guardrails — FOL Dispensers

- Asset register is the **single source of truth** for dispenser location, status, and custodian — no offline spreadsheets
- Installation photo and serial number are **mandatory before** the asset status changes to "active" in the register
- Volume commitment threshold (80%) and consecutive months trigger (2) are configurable by admin without code deploy
- Loan agreement PDF must be signed (e-signature or physical upload) before dispenser is delivered — unsigned agreements block dispatch
- Dispenser recall workflow must complete within **14 days** of trigger — system escalates to senior management if overdue
- Maintenance visit scheduling triggers automatically every 90 days per active dispenser — ops team receives a work order

---

## 6. Non-Functional Requirements

| Category | Requirement | Target | Rationale |
|---|---|---|---|
| Performance | API response time (p95) | < 400 ms | Field reps on 3G connections need fast responses |
| Performance | Dashboard initial load | < 2.5 s on 10 Mbps | Manager UX benchmark |
| Availability | Platform uptime | ≥ 99.5% monthly | Business-critical ordering system |
| Offline | Mobile offline capability | 100% core workflows | Field rep connectivity is unreliable |
| Offline | Sync conflict resolution | Last-write-wins + manager override | Prevent data loss on reconnect |
| Security | Authentication | OAuth 2.0 + MFA for managers | Protect financial and customer data |
| Security | Data at rest | AES-256 encryption | Regulatory and client requirement |
| Security | Data in transit | TLS 1.3 minimum | All API and web traffic |
| Data residency | Hosting region | AWS af-south-1 (Johannesburg) | Kenya/EA customer data compliance |
| Scalability | Concurrent users | 500 simultaneous (Phase 1) | KimFay field team + managers |
| Mobile | Platform support | Android 10+ / iOS 15+ | Covers 95%+ of EA devices in use |
| Accessibility | WCAG compliance | Level AA (web dashboard) | Enterprise procurement requirement |

---

## 7. UI Design Principles — Stripe Inspired

The HorecaOS interface is inspired by Stripe's dashboard aesthetic: data-first, minimal chrome, generous white space, and a strong typographic hierarchy. The design should feel native to a professional B2B context — not a consumer app.

### 7.1 Design Tokens

| Token | Value | Usage |
|---|---|---|
| `--color-ink` | `#0A2540` | Deep navy — primary text, headings, table headers |
| `--color-accent` | `#635BFF` | Stripe purple — CTAs, active nav, hyperlinks, chart highlights |
| `--color-mid` | `#425466` | Secondary body text and supporting labels |
| `--color-muted` | `#8792A2` | Placeholder, helper text, disabled states |
| `--color-bg` | `#F6F9FC` | Page background — off-white tint, never pure white |
| `--color-success` | `#0EA67F` | Positive KPIs, paid status, approved badges |
| `--color-warning` | `#E2872B` | Pending states, at-risk alerts, FOL under-review |
| `--color-danger` | `#DF1B41` | Overdue payments, rejected approvals, recall alerts |
| `--radius-card` | `8px` | All cards, modals, table cells |
| `--radius-badge` | `4px` | Status badges and inline tags |

### 7.2 Layout Rules

- **Navigation** — left sidebar (web), bottom tab bar (mobile). Max 7 items. Active item uses accent colour underline, not a filled pill.
- **Content width** — max 1200px centred. Sidebar 240px fixed. Right gutter 24px min.
- **Tables** — 0.5px hairline borders (`#E0E6EB`), alternating row fill (`#F6F9FC` / white), header row ink with white text.
- **Cards** — white background, 0.5px border, 8px radius, 16px internal padding. No drop shadows on cards in list context.
- **Typography** — Inter as web font. H1 36px / 500, H2 28px / 500, body 16px / 400, label 13px / 500 uppercase.
- **Status badges** — colour-coded pill: green (paid/approved), amber (pending/review), red (overdue/rejected), purple (draft). Max 2 words.
- **Empty states** — always show an actionable message and a primary CTA. Never show a blank table.
- **Loading states** — skeleton loaders (not spinners) for table rows and KPI cards.

### 7.3 Dashboard Layout — Key Screens

```
┌─────────────────────────────────────────────────────────────────┐
│  SIDEBAR (240px)          │  MAIN CONTENT (max 960px)           │
│                           │                                     │
│  ● Overview               │  ┌──────┐ ┌──────┐ ┌──────┐ ┌────┐ │
│  ○ Orders                 │  │ KPI  │ │ KPI  │ │ KPI  │ │KPI │ │
│  ○ Payments               │  └──────┘ └──────┘ └──────┘ └────┘ │
│  ○ Field Activity         │                                     │
│  ○ FOL Dispensers         │  ┌─────────────────┐ ┌───────────┐  │
│  ○ Price Changes          │  │   Orders table  │ │  Sidebar  │  │
│  ○ Reports                │  │   (paginated)   │ │  widgets  │  │
│                           │  └─────────────────┘ └───────────┘  │
│  ─────────────────────    │                                     │
│  Account settings         │                                     │
└─────────────────────────────────────────────────────────────────┘
```

### 7.4 Mobile Rules (Flutter)

- Bottom navigation: 4 tabs — Home (PJP today), Orders, Payments, More
- Meeting capture — full-screen card flow, one field per screen, large touch targets (min 48dp)
- Offline indicator — persistent amber banner when device is offline; auto-dismisses on reconnect
- GPS chip — always visible in top-right corner during active meeting; green = locked, amber = acquiring
- Camera integration — photo capture for meeting evidence, dispenser installation, and cheque collection

---

## 8. Product Catalogue — HORECA Presentation

The product catalogue presents SKUs with HORECA-specific context (use case, venue type, minimum order, FOL eligibility) rather than as a generic price list.

| Category | Product | SKU Variants | HORECA Use Case | FOL Eligible |
|---|---|---|---|---|
| Tissue & Hygiene | Fay Toilet Tissue | 2-ply 400s · 1-ply 850s · Jumbo roll 500m · Interleaved | Hotel rooms, staff restrooms, conference centres | ✅ Jumbo / Interleaved |
| Kitchen & Table | Kitchen Towels | Kitchen roll 2-ply · Auto-cut roll · C-fold interleaved | Commercial kitchens, catering, QSR | ✅ Auto-cut dispenser |
| Kitchen & Table | Serviettes | 1/4-fold cocktail · 1/8-fold dinner · Airlaid premium | Restaurants, banquets, in-flight catering | — |
| Guest Amenities | Facial Tissue | 2-ply box 100s · Flat pack 200s | Hotel rooms, lounges, spa | — |
| Guest Amenities | Pocket Handkerchiefs | Pack of 10 · Single pocket pack | Minibar, concierge gifting | — |
| Guest Amenities | Hand Towels | C-fold · Multifold · Roll | Hotel bathrooms, gym, spa | ✅ Dispenser |
| Food Packaging | Aluminium Foil | 18µm / 24µm · 30cm / 45cm width | Kitchens, bakeries, catering | — |
| Food Packaging | Cling Film | 45cm standard · 60cm catering · Pre-cut sheets | Food storage, deli counters | — |
| Cleaning | Wet Wipes — Food Safe | Antibacterial 72s · Sanitising 100s | Kitchen surfaces, food prep, tables | — |
| Protein (Partner) | Beef Cuts — QMP | Primal · Portion-controlled · Minced | Hotels, restaurants, fast food | — |
| Protein (Partner) | Sausages — Farmers Choice | Pork · Beef · Chicken · Vegan | QSR, catering, supermarket deli | — |

> **Catalogue guardrail:** every product requires a minimum of: product name, SKU code, HORECA use case, unit of measure, minimum order quantity, and VAT code before it can be published to the rep's catalogue view.

---

## 9. Delivery Roadmap

| Phase | Timeline | Deliverables | Gate Criteria |
|---|---|---|---|
| **Phase 1** — Foundation & MVP | Weeks 1–8 | User auth + RBAC · Customer master ERP sync · PJP engine · Meeting capture (GPS, 4 questions, action items) · Flutter mobile MVP · Basic LPO order booking · Web dashboard shell | < 5 open P0 bugs · GPS check-in accuracy ≥ 98% · Signed UAT |
| **Phase 2** — Orders & Payments | Weeks 9–18 | Full order lifecycle · Back-order + account-on-hold handling · Payment aging dashboard · Credit customer management · Collection push (in-app + SMS) · Product catalogue · Route optimisation · Automated performance reports | Order booking time ≤ 7 min in UAT · Payment aging matches ERP |
| **Phase 3** — Approvals & Assets | Weeks 19–28 | Price change request workflow + ERP write-back · FOL dispenser request / approval / asset register / recall · Volume commitment monitoring · ERP integration (Sage / SAP / Odoo) · M-Pesa Daraja payment integration · PDF export for agreements · Mobile camera integration | 100% approval events in audit log · ERP sync round-trip < 5 min |
| **Phase 4** — Intelligence & Scale | Weeks 29–36 | AI order suggestion engine · Predictive payment risk scoring · Multi-tenant SaaS (Farmers Choice + QMP) · Custom tenant branding · CRM module (surveys, returns, NPS) · WhatsApp Business notifications · Advanced analytics (cohort, range-selling heatmaps) | Farmers Choice UAT sign-off · QMP UAT sign-off |

> Phase gate policy: each phase requires signed UAT sign-off, < 5 open P0 bugs, and performance benchmarks met before Phase N+1 commences.

---

## 10. Platform Guardrails

> These are non-negotiable constraints that must be addressed before each phase goes live. They protect data integrity, user trust, and business continuity.

---

### 🔐 10.1 Security & Access

> **Must pass security review before Phase 1 go-live.**

- RBAC enforced on **every API endpoint** — no client-side role gating
- Price change approval thresholds stored server-side only; never in JWT or mobile app bundle
- **MFA mandatory** for all roles with approval authority (line manager and above)
- API keys and ERP credentials stored in encrypted secrets manager (AWS Secrets Manager / HashiCorp Vault) — never in `.env` files committed to repository
- Penetration test required before Phase 1 launch and after each ERP integration goes live
- Session tokens expire after 8 hours of inactivity; refresh tokens invalidated on password change
- All failed login attempts logged; account locked after 5 consecutive failures

---

### 🗄️ 10.2 Data Integrity

> **Immutable records required for all financial and approval events.**

- Every price change, FOL approval/rejection, and payment event written to an **append-only audit log** — no UPDATE or DELETE permitted on these tables
- **ERP is master of record** for: customer data, pricing, inventory, payment status
- **Platform is master of record** for: meeting data, FOL asset register, approval workflows
- ERP conflict resolution: ERP wins on customer credit status and pricing; platform wins on meeting and FOL asset data. Conflicts flagged to admin within 15 minutes
- Database backups: automated daily snapshot retained for 30 days; point-in-time recovery to 5-minute granularity
- **RPO target: 1 hour · RTO target: 4 hours** — agree with client before Phase 1 launch

---

### 📡 10.3 Offline & Sync

> **Offline-first is a P0 requirement, not a feature.**

- Mobile app must support while offline: meeting capture, order booking, payment promise logging, product catalogue browsing
- Offline queue must survive app restart and phone reboot — use Hive or SQLite for local persistence, not in-memory state
- Sync conflict strategy: if a rep edits data offline and the same record changes on the server, surface a **merge UI to the rep** — never silently overwrite
- Sync status always visible in mobile app header: `Synced 2 min ago` / `Offline — 3 items pending`
- Maximum offline queue age: 72 hours — items older than this flagged for manager review on sync

---

### 📍 10.4 GPS & Location

> **Prevent check-in fraud and protect rep data.**

- Cross-validate device GPS with cell-tower triangulation; flag if delta > 200m
- Enforce minimum **3-minute dwell time** at location before meeting can be marked complete
- Flag implausible speed: > 120 km/h between two consecutive check-ins triggers manager alert
- Location data collected **only during configured working hours**; never in background outside working hours
- Location never shared with customers or third parties; visible only to line manager and admin
- Reps must receive written notice and **log consent** before first app use

---

### 🔌 10.5 ERP Integration

> **Protect the production ERP from platform errors.**

- All ERP write operations (price updates, order creation) go through a **staging buffer with 5-minute validation window** before committing
- ERP writes are **idempotent** — duplicate requests must not create duplicate records
- ERP integration failures must not break the platform UI — graceful degradation with `ERP sync pending` status shown
- **Circuit breaker pattern**: if ERP API fails > 5 consecutive times, platform queues requests and alerts admin; does not retry indefinitely
- Separate ERP sandbox environment required for all development and UAT testing
- ERP sync logs retained for 90 days and accessible to admin without engineering involvement

---

### 📱 10.6 App Store & Mobile Deployment

> **Build this into the project schedule. Do not set a go-live date before stores approve the app.**

- Android Play Store review: 3–7 business days for new apps; **budget 10 days in schedule**
- Apple App Store review: 3–14 business days; Apple requires privacy policy and data collection disclosure
- Internal testing: TestFlight (iOS) and Internal Test Track (Android) from Week 6 — 2 weeks before store submission
- App bundle ID and signing certificates must be under the **client's developer account**, not the agency's
- App must comply with Google Play's [financial app data handling policy](https://play.google.com/intl/en_us/about/developer-content-policy/) and Apple's App Store Review Guidelines §5.1 (Privacy)

---

### ⚠️ 10.7 Things You May Have Missed

| Risk / Gap | Mitigation |
|---|---|
| **Data residency** | Host all customer PII and financial data in AWS af-south-1 (Johannesburg). Document in privacy policy. |
| **VAT / tax on orders** | Orders exported to ERP must carry correct VAT codes per product category. Agree tax logic with finance before Phase 1. |
| **Multi-currency** | KimFay may invoice in USD, KES, UGX, TZS. Store transaction currency and exchange rate at booking time. |
| **Rep device management** | Agree who pays for smartphones and define an MDM (Mobile Device Management) policy before rollout. |
| **User offboarding** | When a rep leaves, their meetings, orders, and GPS history must transfer to their replacement. Define the workflow. |
| **Customer notification consent** | Customers receive SMS/push. Capture opt-in consent and store it. Required for data protection compliance. |
| **PDF export of agreements** | Managers and finance need printable price change approvals and FOL loan agreements. Build PDF export in Phase 2. |
| **Disaster recovery** | Define RPO (1h) and RTO (4h) with client. Document the fallback process (WhatsApp ordering) for platform downtime. |
| **Change management** | Budget for a 2-day onboarding workshop per region before Phase 1 go-live. Adoption risk is as high as technical risk. |
| **SLA definition** | Define what happens when the platform is down during a sales day — reps need a documented fallback process. |
| **Swahili / Arabic localisation** | Determine if the UI needs localisation for field reps outside Nairobi. Not in v1.0 scope unless confirmed. |
| **Farmers Choice / QMP data isolation** | Decide: shared tenant with logical separation, or fully isolated databases. Affects Phase 4 architecture significantly. |

---

## 11. Commercial Packages

| Feature / Module | Starter | Growth | Enterprise |
|---|---|---|---|
| **Users** | Up to 10 | Up to 50 | Unlimited |
| **Web dashboard** | ✅ | ✅ | ✅ |
| **Mobile app (Flutter)** | 5 reps | 25 reps | Unlimited |
| **Meeting management + PJP** | ✅ | ✅ | ✅ |
| **Order management (LPO)** | ✅ | ✅ | ✅ |
| **Payment tracking** | Basic | Full aging | Full + M-Pesa |
| **Price change workflow** | — | ✅ | ✅ |
| **FOL dispenser lifecycle** | — | ✅ | ✅ |
| **Product catalogue** | Standard | HORECA full | Custom per tenant |
| **ERP integration** | — | 1 system | Unlimited |
| **AI order suggestions** | — | — | ✅ |
| **Multi-tenant SaaS** | — | — | ✅ |
| **Custom branding** | — | — | ✅ per tenant |
| **Support SLA** | Email (48h) | Priority (4h) | Dedicated + 1h |
| **Onboarding** | Self-serve | Remote | On-site (2 days) |
| **Monthly price (USD)** | $299 | $799 | Custom |
| **Annual discount** | 10% | 15% | Negotiated |
| **Setup fee (one-time)** | $500 | $2,000 | $5,000+ |

> All prices exclude ERP integration build cost, which is scoped and quoted separately based on the ERP system and API availability.

---

## 12. Integration Registry

| Integration | Type | Phase | Purpose |
|---|---|---|---|
| Sage Business Cloud | ERP (bidirectional) | 3 | Customer master, pricing, inventory, payment status |
| SAP Business One | ERP (bidirectional) | 3 | Enterprise client ERP — same connector pattern as Sage |
| Odoo 17 | ERP (bidirectional) | 3 | Open-source ERP option for smaller clients |
| Salesforce CRM | CRM (write) | 3 | Push meeting notes, order activity, and account health scores |
| HubSpot CRM | CRM (write) | 3 | Alternative CRM for clients not on Salesforce |
| M-Pesa Daraja API | Payments (bidirectional) | 3 | Customer payment collection and reconciliation |
| Equity Bank API | Payments (read) | 4 | Bank payment reconciliation for non-M-Pesa customers |
| Africa's Talking / Twilio | SMS (outbound) | 2 | Payment reminders, order status, approval notifications |
| WhatsApp Business API | Messaging (outbound) | 4 | Customer-facing notifications and order confirmations |
| Google Maps Platform | Maps / Routing | 1 | GPS check-in, route optimisation, PJP map view |
| Firebase Cloud Messaging | Push notifications | 1 | Real-time alerts to mobile app |
| Postmark | Transactional email | 1 | Approval notifications, weekly reports, system alerts |
| AWS S3 | File storage | 1 | Meeting photos, dispenser installation images, PDF agreements |
| Custom REST / SOAP | Any ERP | 3 | Fallback connector for non-standard ERP systems |

---

## 13. Open Questions & Decisions Required

| # | Question | Owner | Status |
|---|---|---|---|
| 1 | Which ERP does KimFay EA currently use? (Determines Phase 3 integration approach) | KimFay Finance + IT | **Open** |
| 2 | What is the price change approval threshold (KES value) for line manager vs. senior management? | Sales Director | **Open** |
| 3 | What is the minimum purchase volume commitment for FOL dispenser eligibility? | Commercial team | **Open** |
| 4 | Which SMS provider — Africa's Talking or Twilio? | Engineering | **Open** |
| 5 | Will Farmers Choice and QMP share a single tenant or require full data isolation? | Product + Legal | **Open** |
| 6 | What working hours should GPS tracking be active? (Default proposed: 07:00–18:00 Mon–Sat) | HR + Legal | **Open** |
| 7 | Who owns the developer accounts for App Store and Play Store submissions? | Client IT | **Open** |
| 8 | Is there a requirement for Swahili UI localisation in addition to English? | Product | **Open** |

---

## 14. Document Sign-Off

This PRD requires sign-off from the following stakeholders before Phase 1 development commences.

| Stakeholder | Role | Date | Signature |
|---|---|---|---|
| | Product Owner (KimFay) | | |
| | Sales Director | | |
| | Finance / Credit Control | | |
| | Head of IT / Technology | | |
| | Platform Lead (Agency) | | |

---

*HorecaOS PRD v1.0 — April 2026 · Confidential — KimFay Professional*
