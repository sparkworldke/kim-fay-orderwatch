# PRD — WhatsApp Notifications & Chatbot for OrderWatch

## 1. Summary

Give OrderWatch a WhatsApp channel with two parts:

1. **Push notifications** — the system proactively messages selected users when something they care about changes (order status, revenue vs. target, stock status, FOL approval steps).
2. **Pull chatbot** — a user can message the bot at any time and, after identity verification, get a short menu of live read-only reports (order status, brand status, today's revenue, stock levels).

This doc also covers the "nudge users to add their WhatsApp number" onboarding flow, and closes with a security model for chatbot identity verification, since "verify number + employee ID" as originally scoped has a spoofing/guessing weakness that's cheap to fix up front.

## 2. Background — what already exists

Worth knowing before scoping this, since it changes the size of the build:

- **WhatsApp delivery already exists**, but only for OTP and only via the **Meta Cloud API** driver — `WhatsAppOtpService` (`backend/app/Services/WhatsAppOtpService.php`), driver config in `config/services.php` (`WHATSAPP_DRIVER`, `WHATSAPP_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID`). It's currently invoked from the profile phone-number-update flow, not login. **This PRD replaces that driver with a Baileys-backed one** (§3) so OTP, notifications, and the chatbot all go through the same WhatsApp session — `WhatsAppOtpService` keeps its existing interface (`sendOtp()`), only the driver underneath changes.
- **`users.whatsapp_number` and `users.employee_number` columns already exist.** No new user-table migration needed to store the number.
- **A generic `NotificationRule` model already exists** (`app/Models/NotificationRule.php`) with `channels` (currently `email`, `in_app`), plus `emailRecipients` (direct per-user opt-in) and `roleRecipients` (per-role). Adding `whatsapp` as a third channel on this same model is the natural extension point — this should not become a parallel, second notification-preferences system.
- **FOL is a KP-team-only workflow** — it already has a defined multi-stage approval process with notification codes: `draft → submitted → in_approval → (rejected | approved through stages) → ready_for_invoicing → so_linked → invoiced → fulfilled`, with stage-level decisions (`stage_approved`, `rejected`, `final_approved`) at the KP department's HOD and CCO/COO stages. Email notification codes N1 (pending approval), N2 (stage approved), N6 (rejected) already exist — WhatsApp is a new delivery channel for events the system already detects, not new business logic. Any FOL notification/chatbot audience below is implicitly scoped to KP staff (requestors, KP HOD, CCO/COO) — not company-wide.
- **Order status enum**: `Open, Completed, Cancelled, Back Order, Credit Hold, On Hold, Rejected, Shipping, Pending Approval`.
- **Stock status** is already a 3-tier model driven by MSI ratio: `critical` (<50% of MSI), `at-risk` (50–99%), `healthy` (≥100%) — exact wording is in `STATUS_LABEL`/`STATUS_LEGEND` (`src/utils/Stock/status.ts`) and should be reused verbatim in bot copy so the message matches what the user sees on the Inventory page.
- **Revenue vs. target is currently a personal (per Sales Consultant) metric only** — `target`, `revenue_mtd`, `variance_to_pace`, `expected_revenue_to_date`, `achieved_pct` per rep. There is **no separate "business/company target" aggregate** today — a manager's "team" view is a rollup of individual reps' numbers, not a distinct company-wide target. The PRD's "Business" tier (item 3 below) needs this to be built or explicitly scoped as "team rollup," not assumed to exist.
- **Role-based revenue masking already has precedent**: `config('departments.mask_revenue_roles')` currently hides revenue from `Customer Service Agent`. The same config array is the natural place to encode "who can see whose revenue" for WhatsApp, rather than inventing a new permission.
- **Roles**: Administrator, Customer Service Manager, Customer Service Agent, Sales Operations, Sales Consultant, Executive, HOD, Technician Manager, Technician. **org_level**: `c_suite`, `executive`, `operations` (org-wide), `hod` (subtree/team visibility), `gap` (no access — fail closed).

## 3. Baileys architecture & operational reality

Baileys (`@whiskeysockets/baileys`) talks to WhatsApp over the same protocol as WhatsApp Web/multi-device — it pairs to a real WhatsApp number (via QR code or an 8-digit pairing code) rather than going through Meta's Business Platform. That shapes the build:

- **Standalone Node.js service.** Baileys is a Node library, and this codebase is Laravel + React — it needs its own small long-running service (not a request/response endpoint) that holds the socket connection open. Laravel talks to it over an internal HTTP API or a queue (send message, get delivery/read receipts, get incoming messages); the Node service pushes incoming messages back to Laravel via webhook.
- **One dedicated business number.** Pair a single number to the service — not a shared/personal phone — so pairing state, ban risk, and message history all sit in one place the business controls. Treat this number as infrastructure (document who owns the SIM, who can re-pair it).
- **Persistent session/auth state.** Baileys stores multi-device credentials (`creds.json`-equivalent) after pairing; losing that store means re-pairing from scratch (scan a new QR code). Back it up and treat it like a secret — anyone holding it can send as that number.
- **No template approval needed.** Unlike the Meta Cloud API, Baileys sends freeform text both directions with no pre-approval step — faster to iterate on copy, but also means there's no Meta-side moderation layer catching bad outbound content before it sends.
- **Real, accepted risk: this is unofficial.** Automating a WhatsApp number this way is outside WhatsApp's Terms of Service — the number can be banned, with no formal appeal path, and risk scales with send volume and how "bot-like" the traffic looks (identical mass blasts, no delays, no replies to real messages). Given the explicit choice to use Baileys, mitigate rather than ignore this:
  - Ramp up gradually — start with a handful of users/notification types, not the full catalogue at once.
  - Space sends out (jitter/delay between messages) instead of firing a batch instantly.
  - Only message numbers that opted in and were OTP-verified (§4) — never cold-send.
  - Have a fallback channel (existing email digests) ready to carry the same content if the number ever gets banned or the session drops for an extended period — don't let WhatsApp become the *only* delivery path for anything time-sensitive.
  - Monitor connection-state events (`connection.update` → `close`/`loggedOut`) and alert ops immediately so a dropped session gets re-paired same-day, not discovered a week later when someone asks why no one got their digest.
- **`WhatsAppOtpService` keeps its public interface** (`sendOtp()`), gaining a `baileys` driver that calls the Node service instead of Meta's REST API — callers (`ProfileController`, and the new login-OTP flow) don't need to change.

The rest of this PRD assumes Baileys as the sole WhatsApp transport for OTP, notifications, and the chatbot.

## 4. Onboarding — "add your WhatsApp number"

- On login, if `users.whatsapp_number` is null, show a dismissible banner prompting the user to add it (Profile page already has the phone-number-update flow with OTP verification — reuse it, don't build a second one).
- **Verification is mandatory, not optional**: the number is only stored as `whatsapp_number` (and only becomes eligible to receive notifications / operate the chatbot) after the existing OTP flow confirms the user controls that number. This closes the "verify number + employee ID" gap from the original brief — identity is established once, at registration time, with a real one-time code, not by asking the bot to trust whatever employee ID a message claims.
- Reminder frequency: don't nag every login forever. Suggest: show on every login until added, cap re-prompts at, say, once per session after the number is added-but-unverified, and stop entirely once verified or the user dismisses with "don't ask again" (store a `whatsapp_reminder_dismissed_at` timestamp or reuse the `first_login_onboarding` pattern already in the codebase for a similar nudge).

## 5. Feature 1 — Automatic Notifications

### 5.1 Access control model

Reuse `NotificationRule` (add `whatsapp` to its `channels` enum) rather than building a parallel preferences table:
- **Per-role default subscriptions** via `roleRecipients` (e.g. the KP department's HOD gets FOL-stage notifications — FOL is a KP-team-only workflow, not company-wide, so this rule must be scoped to the `kp` department, not all HODs org-wide).
- **Per-user opt-in/opt-out** via `emailRecipients`-equivalent (rename conceptually to just "recipients", channel-agnostic).
- **Privilege-scoped content**, not just privilege-scoped delivery: the *same* "Revenue vs Target" notification renders differently depending on `org_level`/`mask_revenue_roles` — a rep gets their own number, an HOD/Executive gets their subtree/company rollup, a masked role (Customer Service Agent) doesn't get this notification at all.

### 5.2 Notification catalogue

| Event | Trigger (existing data) | Audience |
|---|---|---|
| Order status change | `AcumaticaSalesOrder.status` transitions (e.g. → `Back Order`, → `On Hold`, → `Credit Hold`) | Assigned Sales Consultant; CS Manager for Credit Hold |
| Revenue vs. target | Daily cron comparing `revenue_mtd` vs `expected_revenue_to_date` (pace) | Rep (personal); HOD/Executive (subtree rollup) — **note: company-wide target aggregate doesn't exist yet, see §2** |
| Stock status | SKU crosses a status boundary (`healthy`→`at-risk`→`critical`) on next inventory sync | Production dept roles + whoever owns that brand/category |
| FOL approval step | `FolRequest` stage transition (submitted, stage_approved, rejected, final_approved) | Requestor always; current stage's approver (KP department's HOD, then CCO/COO) when it lands on them — **FOL is KP-team-only**, this never reaches HODs/approvers outside KP |
| Backorder report | Daily snapshot from `BackorderMetricsService::summarize` — open lines/orders/SKUs, revenue at risk, aging buckets (0-7/8-14/15-30/30+ days), top reason codes | Administrator, Customer Service Manager, Sales Operations — same roles already gated to edit backorder reasons/exports today |
| Fill rate report | Daily snapshot from `FillRateCalculator` — fill rate % (shipped/ordered on Completed orders), revenue not shipped, out-of-stock line count, KP vs CS segment split | Administrator + manager roles — same `admin.or.manager` gate already used on fill-rate export routes |

### 5.3 Additional notifications worth adding (answering "think of any other features")

Grounded in modules that already exist in this codebase, so these are channel additions, not new features:

- **Backorder aging** — a backorder crossing an age threshold (e.g. 7/14/30 days unresolved), or the `missing_reason_exception` flag tripping (aged >7 days with no reason recorded). The aging fields and this flag already exist (`add_backorder_aging_fields` migration; `BackorderLineTransformer.php`).
- **Fill rate drop** — fill rate crosses a status boundary (≥95% healthy → 80-94% at_risk → <80% critical), mirroring the stock-status tiering pattern above. Thresholds already exist in `FillRateCalculator.php`.
- **Price Change Request status** — PCR approved/rejected, notify the requestor (mirrors the FOL pattern exactly).
- **Commission statement ready** — a rep's commission statement is finalized/approved (Commission models already exist).
- **Dormant customer alert** — a KP account crosses the dormancy threshold (KP Dormant Customers module already exists) — notify the owning rep before it becomes a lost-account problem.
- **FOL technician install reminder** — a scheduled install is due today/tomorrow (FOL Calendar already exists) — high-value for technicians specifically since they're less likely to be watching the web app all day.
- **Daily personal digest** (opt-in, one message, sent once at a fixed time e.g. 7:30am Nairobi) rolling up: orders shipped yesterday, backorders still open, today's revenue pace, any stock-critical SKUs in the rep's brands. This is the single highest-value addition — most of the individual event notifications above are also worth batching into this digest for users who don't want a running stream of pings.
- **Daily management digest** (Administrator/manager audience) — the existing `DailyManagementReportMail`/`DailyExecutiveReportService` already computes fill-rate % and backorder revenue-at-risk/top-reasons for the prior day; a WhatsApp version of this is a new delivery channel on data that's already produced daily, not new computation.

### 5.4 Non-functional requirements

- **Quiet hours**: no sends outside business hours (reuse the existing Nairobi business-hours convention from `po-load-time.ts`, 08:15+) except for genuinely urgent items (e.g. Credit Hold), if any are deemed urgent enough to interrupt.
- **Rate/volume limiting**: cap per-user messages per day (e.g. batch same-type events instead of one message per row) — a SKU flapping between at-risk/critical every sync would otherwise spam.
- **Opt-out**: every notification type must be independently toggleable per user, not all-or-nothing.
- **Send pacing**: stagger outbound sends (small random delay between messages) rather than firing a whole cron batch at once — reduces how "bot-like" traffic looks to WhatsApp and smooths load on the single Baileys session (§3).
- **Session-down fallback**: if the Baileys session is disconnected/logged out, queue or reroute time-sensitive notifications (e.g. Credit Hold, FOL pending-approval) to the existing email channel rather than silently dropping them until someone re-pairs the number.

## 6. Feature 2 — Chatbot

### 6.1 Identity & security model

Original brief: "accept any word to start, then verify number and employee ID against the database." As scoped, this has a weakness: `employee_number` is very likely sequential/short and effectively guessable, so "phone number + employee ID typed into chat" is a low-entropy pairing check that could let someone probe for valid combinations, or let a phone that's changed hands (resigned staff, shared phone) keep answering as the old employee.

Revised model, using the onboarding flow from §4:
1. A user's WhatsApp number is only ever trusted after the one-time **OTP-verified pairing** done in-app (Profile → WhatsApp number).
2. When a message arrives, the bot looks up the sending number against `users.whatsapp_number` (verified numbers only). Unverified/unknown numbers get a single reply pointing them to the app to add and verify their number — the bot **never asks for an employee ID over chat** as a substitute for verification.
3. Optional defense-in-depth: for a first message in a new session (e.g. after N days idle), ask for a quick re-confirmation (last 4 digits of employee ID) before showing anything revenue-related, to cover the "phone changed hands but number not yet re-registered" edge case. This is a secondary check, not the primary identity mechanism.
4. Every chatbot query still passes through the same privilege scoping as §5.1 — "My Revenue Today" for a masked role returns nothing, "Order Status" only returns orders the user is entitled to see, etc. The bot must call the same authorization-scoped services the web app uses, not a shortcut query.

### 6.2 Menu (from the original brief, unchanged)

Once verified, a fixed keyword or number-based menu:
- Order Status
- Brand Status
- My Revenue Today
- Stock Level Report
- Backorder Report *(Administrator / CS Manager / Sales Operations only — same gate as the web page)*
- Fill Rate Report *(Administrator / manager roles only — same gate as the web page)*

All are read-only lookups against existing endpoints (`OperationsController` for backorders/fill-rate, per §5.2) — no new business logic, just a text-formatted rendering of data the API already returns. The last two only appear in the menu for users whose role passes the existing gate — same principle as §6.1's privilege scoping, not a visible-but-blocked option.

### 6.3 Additional chatbot features worth adding

- **"My open FOLs"** — status of FOL requests the user submitted or is the current approver for (KP staff only); lets the KP HOD/CCO/COO approve/reject flow start from WhatsApp (see below) instead of just checking status.
- **Quick-approve via reply** — for the person who is the *current* FOL approval stage, let a reply like "APPROVE 1234" or "REJECT 1234 <reason>" action the approval directly from WhatsApp. High value (approvals are often the bottleneck step), but needs care: require the same permission check as the web approval action, and log the WhatsApp-originated approval in the audit trail exactly like a web action.
- **"Help" / menu-recall** — repeat the menu on any unrecognized input rather than failing silently, so the bot is forgiving of "any word to start" as originally specified.
- **Session timeout** — after some idle period, require the menu to be re-shown (not re-verification) so a long-idle chat doesn't silently reuse stale context.

## 7. Open questions

1. **Which number becomes the bot number?** Needs a real, dedicated SIM/WhatsApp account the business controls (not someone's personal phone), plus a named owner responsible for re-pairing it if the session drops.
2. **Where does the Baileys Node service run and who keeps it alive?** It's a new piece of always-on infrastructure alongside the existing Laravel/PHP stack — needs a host, a process supervisor (so it restarts itself on crash), and monitoring for disconnects.
3. **Session credential backup/recovery** — who holds the paired-session backup, and what's the recovery runbook if it's lost (re-pairing means a fresh QR scan, and a window with no WhatsApp delivery until that happens)?
4. **Ban/incident response** — if the number gets flagged or banned, what's the fallback (email, per §3) and how fast can a replacement number be paired and re-communicated to users?
5. Does "Revenue vs Target — business" need a real company-wide target to be built, or is "team rollup of individual rep targets" sufficient for v1? (§2 — no such aggregate exists today.)
6. Should FOL approval-by-WhatsApp-reply be in v1, or deferred to a v2 once plain notifications are proven out?
7. Quiet hours / urgency exceptions — which notification types, if any, should bypass business-hours throttling?
8. Data residency / retention — decide how long inbound/outbound message logs are kept (Baileys gives full control over this since nothing is retained by a third party the way Meta would) and who can access chat history for support/audit purposes.

## 8. Suggested rollout phases

1. **Phase 1** — Stand up the Baileys Node service, pair the dedicated business number, and prove the onboarding/verification flow end-to-end (new `baileys` driver behind `WhatsAppOtpService`; no new notifications yet). Validates the plumbing, session stability, and gets numbers collected/verified.
2. **Phase 2** — One-way notifications: start with the single highest-value item, the daily personal digest (§5.3), plus FOL stage notifications (data/logic already exists, just add the channel). Roll out to a small pilot group first to observe session/account health under real send volume before widening.
3. **Phase 3** — Remaining notification types (order status, stock status, revenue) with per-type opt-in, widened to all users once the pilot is stable.
4. **Phase 4** — Chatbot menu (read-only).
5. **Phase 5** (optional) — Chatbot quick-actions (approve/reject via reply).
