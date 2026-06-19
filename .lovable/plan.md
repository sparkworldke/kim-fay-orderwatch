# Kim-Fay OrderWatch — Build Plan

An internal operations dashboard (Power BI / Retool / SAP Fiori feel). No marketing surfaces. Login → dashboard.

## Scope of this build (v1, demo data only)

Frontend-only build using mock data. No real Outlook / Acumatica / OpenAI integration yet — those modules render realistic seeded data and stubbed flows so the UX is fully demonstrable. Backend (Lovable Cloud + real OTP, integrations, cron) can be added in a follow-up once UX is approved.

## Design language

- Enterprise data-dense UI: compact spacing, 13–14px body, tabular numerics.
- Sidebar nav (collapsible icon mode) + sticky top header with global search, refresh status, notifications bell, user menu, dark-mode toggle.
- Palette pulled from the Kim-Fay logo: brand blue (`#1E73D8` / deep `#0A4DA8`) as primary, silver/slate neutrals, semantic green/amber/red for SLA states. Both light and dark themes defined as semantic tokens in `src/styles.css` (oklch).
- Typography: Inter for UI, JetBrains Mono for numbers/IDs. (Distinctive enough for enterprise without being playful.)
- Components: shadcn/ui, TanStack Table for all grids, Recharts for charts, lucide icons, sonner toasts, skeletons + empty states everywhere.
- Uploaded Kim-Fay logo wired in as a Lovable Asset and shown in login + sidebar header.

## Routing (TanStack Start, file-based)

```
src/routes/
  __root.tsx              shell + providers + Sonner + theme
  index.tsx               redirect → /auth or /app
  auth.tsx                login (email → OTP, mock)
  _app.tsx                authenticated layout (sidebar + header + Outlet)
  _app.index.tsx          /app  → Dashboard
  _app.orders.tsx
  _app.discrepancies.tsx
  _app.customers.tsx
  _app.ai-insights.tsx
  _app.reports.tsx
  _app.notifications.tsx
  _app.administration.tsx (tabs: Mailboxes, Acumatica, OpenAI, Customer Rules, SLA Rules, Roles, Permissions, Notification Rules, Audit Logs)
```

Auth gate: `_app.tsx` `beforeLoad` checks a mock session in `localStorage` (`kf_session`); unauthenticated → redirect to `/auth`. Role stored on session and exposed via a `useAuth()` hook so role-restricted nav items hide appropriately.

## Auth flow (mock, ready to swap for real OTP later)

1. `/auth` — email input, Kim-Fay logo, tagline "Every Order. Accounted For.", subtle gradient backdrop.
2. Submit → simulated send, toast "OTP sent to {email}", switch to 6-digit OTP input (shadcn input-otp), 10-min countdown, resend link.
3. Verify → any 6 digits accepted in demo; role inferred from email prefix (`admin@`, `csm@`, `agent@`, `ops@`, `exec@`) or defaults to Administrator. Session persisted, redirect to `/app`.

## Pages

**Dashboard (`/app`)** — KPI card grid (8 cards: Orders Received Today, Orders Captured, Capture Rate %, Revenue At Risk, Revenue Captured, Outstanding, Critical, AOV) with delta vs yesterday. Charts row: Order Volume Trend (area), Revenue Trend (bar), Capture Rate Trend (line), SLA Compliance Trend (line), Revenue At Risk Trend (area). Widgets: Outstanding Orders table, Critical Orders list, Recent Activity feed, Recent Escalations, AI Recommendations panel.

**Orders** — TanStack Table: PO #, Customer, Email Subject, Email Received, SO #, Order Value (KES), Status badge (Matched/Missing/Delayed/Duplicate/Escalated), SLA badge (On Track/Warning/Breached), Assigned, Updated. Faceted filters, global search, saved views (localStorage), CSV export, column visibility, row click → side drawer with email preview + SO details + timeline.

**Discrepancies** — Kanban board with 4 columns (Outstanding / Warning / Critical / Escalated). Cards show PO, customer, value, age. Actions: Assign, Comment, Resolve, Escalate (dialogs, toast feedback, optimistic move).

**Customers** — Table of detection rules for Naivas, Quickmart, Carrefour, Chandarana, Eastmatt, Magunas, Khetias, Mathai. Edit dialog with subject pattern, PO regex, SLA target (hours), alert threshold, status toggle.

**AI Insights** — Tabbed sections (Executive Summary, Operational Summary, Revenue Risk Analysis, Root Cause Analysis, CS Performance, Recommendations, Trend Analysis). Each tab: timestamped report card ("Generated 12:00, next run 15:00"), narrative bullets, supporting mini-charts. All text seeded; "Regenerate" button is a stub.

**Reports** — Daily / Weekly / Monthly tabs, date range picker, export buttons (Excel/CSV/PDF — CSV works, others show toast "queued").

**Notifications** — Inbox layout with category filter (Alerts, Escalations, Revenue Risk, System), read/unread, bulk actions.

**Administration** — Vertical tabs for each section. Mailboxes/Acumatica/OpenAI show connection status cards + form (read-only demo). Customer/SLA/Notification rules are editable tables. Roles & Permissions matrix. Audit log table.

## Demo data

`src/lib/demo-data.ts` generates ~250 orders across the 8 customers with realistic Kenyan supermarket PO formats (e.g. `NVS-PO-44213`, `QM/2026/00871`), KES values weighted to produce the 4 priority bands, SLA states, 14-day history for charts. Deterministic via seeded RNG so charts/KPIs are consistent across renders.

## Technical notes

- Theme tokens, brand colors, gradients, and shadows defined in `src/styles.css` under `@theme inline` (no hardcoded color classes in components).
- Dark mode: `class="dark"` toggled on `<html>`, persisted in localStorage.
- TanStack Query for any future async; for now data comes from sync demo modules.
- All tables use one shared `<DataTable>` wrapper around TanStack Table with filters/pagination/column visibility.
- No backend, no Lovable Cloud in this pass. When you're ready I'll wire real Email-OTP auth, a Postgres schema for orders/customers/rules/audit, scheduled Outlook+Acumatica sync, and an OpenAI insights job.

## Out of scope (call out for next phase)

- Real Microsoft Graph / Acumatica / OpenAI integrations and cron schedules
- Real OTP email delivery + session/JWT
- Persistence (currently localStorage for session + saved views)
- Excel / PDF export rendering

Confirm and I'll build.
