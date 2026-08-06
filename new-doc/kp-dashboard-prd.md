# My Portfolio — Sales Consultant Dashboard (Compressed)

**Product:** Kim-Fay Sales Intelligence / KP  
**Feature:** My Portfolio  
**Users:** Sales consultants (primary), sales managers (team view)  
**Status:** Draft — prioritised for sales value  

## Goal

In under five seconds a consultant should know:

1. Who to contact today  
2. Who is at risk / dormant  
3. How sales track vs target  
4. What backorders need chasing  

---

## Must have (build first)

### 1. Today’s Priorities

Ranked list of max **5** actions, under the summary.

**Priority order (revenue first):**

1. High-value **at-risk / dormant** accounts (call)  
2. High-value **backorders** (review / chase)  
3. **Target shortfall** this month (focus)  
4. Missing **primary contact** (only if it blocks calling)  

Each row: short reason + one button (`Call Customer`, `Review Backorders`, …).

**Done when:** consultant sees the next call within 5 seconds.

### 2. Buying status (not ERP status)

| Status | Rule |
|---|---|
| **Buying** | Ordered in last 44 days |
| **At Risk** | No order 45–89 days |
| **Dormant** | No order 90+ days |
| **New** | Never ordered |

- Use last **successful** order (ignore cancelled).  
- Show **days inactive** next to status.  
- ERP Active / On hold stays on account detail only (show credit/hold badge if it blocks orders).  
- Colour + text (not colour alone). Thresholds configurable later.

### 3. Core KPI cards (3–4 only)

| Card | Shows |
|---|---|
| **Sales This Month** | MTD KES + % vs prior period |
| **Target Progress** | Actual / target / % / remaining / bar (or “No target”) |
| **Follow-ups** | Count at risk + dormant + new without first order |
| **Backorders** | Lines · customers · KES pending |

No fill-rate on this page.

### 4. Customer table — decision columns only

| Column | Why |
|---|---|
| Account | Who |
| Buying status | Health |
| Last order + days inactive | Urgency |
| Sales MTD | Value |
| Backorders | Risk |
| Next action | What to do |

Primary actions visible: `Call Customer`, `View Account`, `Create Order`, `Review Backorders`.

Search: name + customer ID. Filter: buying status, has backorders. Sort + paginate.

### 5. Sales language (lightweight pass)

| Avoid | Use |
|---|---|
| My book / Personal book | My Portfolio |
| Revenue MTD | Sales This Month |
| Target vs Pace | Target Progress |
| Early warning — slowing down | Customers at Risk |
| Open full dormant list | View Customers at Risk |
| `0` / `—` contacts | Missing primary contact → `Add Contact` |

---

## Good to have (after must-haves)

| Item | Notes |
|---|---|
| **Reorder opportunities** | Priority line + link; not a top KPI |
| **Month-end forecast** | Secondary metric only |
| **Primary contact column** | Name / phone; missing highlighted |
| **Extra search** | Contact, phone, email, class |
| **Extra filters** | Class, last-order period, has contact, sales value, owner |
| **Secondary metrics row** | Total customers, buying customers, order count |
| **Secondary actions menu** | History, notes, schedule, statements, edit |
| **Full priority list page** | Expand beyond top 5 |
| **Request Target** | When no target set |
| **Open-order follow-up** | Only if customer-impacting (delay / credit / partial) |
| **One-tap phone / WhatsApp** | When contact exists |
| **Call outcome** | So same name doesn’t reappear forever |
| **Team view polish** | Manager priorities across reps |

---

## Page order

1. Title + portfolio scope (Me / Team)  
2. Period filter  
3. **Today’s Priorities**  
4. KPI cards  
5. (Optional) secondary metrics  
6. Customer table  

---

## Acceptance (must-have only)

1. Top priorities visible in ≤5 seconds, each with an action.  
2. Table shows buying status, inactivity, sales MTD, backorders.  
3. Buying status ≠ ERP technical status.  
4. Four (or fewer) primary KPI cards; no fill-rate mixed in.  
5. Labels and primary actions are sales-clear.  

---

## Out of scope for V1

- Full CRM redesign  
- Supply-chain fill-rate analytics on this page  
- Equal ranking of data-cleanup vs revenue rescue  
- Mobile-perfect 9-column table (desktop first; thin columns on small screens)  
