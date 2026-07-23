# PRD: Kim-Fay Genius — AI Insights Reliability & Per-Consultant Coaching

**Status:** Draft v0.1  
**Date:** 2026-07-23  
**Product:** Kim-Fay Sight (OrderWatch)  
**Surfaces:** `/app/ai-intelligence` (global), new **Kimfay Genius** tab (per sales consultant)  
**Related:** `AiIntelligenceController`, `AiIntelligenceInsightService`, `AiConnectorService`, `AiChatController`, daily report AI, `docs/AI Cards and Formulas.md`  

**Brand name:** **Kimfay Genius** (user-facing spelling; internal code may use `kimfay_genius`)

---

## 1. Executive summary

Users are **failing or getting unusable results** when they click **Generate insights** on AI Intelligence. Separately, sales consultants need **personalised AI coaching** on *their* portfolio—not only an org-wide executive briefing.

This PRD covers three workstreams:

| # | Workstream | Outcome |
|---|---|---|
| **A** | **AI model reliability** | Fix generation errors; clear provider config; resilient timeouts; honest error UI; optional xAI (Grok) provider. |
| **B** | **Kimfay Genius (per consultant)** | Tab listing consultants; one AI brief **locked to 1 generation per week** per consultant; portfolio-scoped data only. |
| **C** | **Background generation** | Long LLM calls run as **queued jobs** so HTTP does not 504; UI polls status. |

---

## 2. Current state (ground truth)

### 2.1 What exists

| Piece | Location | Behaviour |
|---|---|---|
| AI Intelligence page | `src/routes/app.ai-intelligence.tsx` | Loads metrics always; AI on **Generate** |
| Metrics API | `GET ai/intelligence` | DB aggregations only — no LLM |
| Generate API | `POST ai/intelligence/generate` | **Synchronous** LLM call inside request |
| Insight service | `AiIntelligenceInsightService` | OpenAI `gpt-4o-mini` or Anthropic Haiku via `AiConnectorService` |
| Keys | Admin AI connector / env `OPENAI_API_KEY`, `ANTHROPIC_API_KEY` | Prefer OpenAI then Anthropic |
| Cache | `ai_intelligence_briefings` | One row per **date range** (global, not per user) |
| Fallback | Template text if no key or LLM fails | Often looks like “success” but `ai_status` = failed/unavailable |
| Chat assistant | `AiChatController` | Separate path; also OpenAI hard-coded URL |
| Prompt logs | `AiPromptLog` | Success/fail recorded |

### 2.2 Why generation “errors” (likely causes)

| Cause | Symptom | Evidence in code |
|---|---|---|
| **No API key** | Silent template “insights”, no real AI | `resolveKey()` → null → `fallback(..., 'unavailable')` still saved as briefing |
| **Invalid / expired key** | RuntimeException / HTTP 4xx | OpenAI/Anthropic error path; may surface as mutation error or fallback |
| **Timeout / gateway 504** | Frontend error, partial hang | 60s HTTP timeout + large `json_encode($payload)` in user message; Cloudflare/nginx often 60–100s |
| **Payload too large** | Provider 400 / context limit | Full metrics payload pretty-printed into the prompt |
| **JSON parse failure** | Fallback after throw | `parse()` requires pure JSON |
| **Only OpenAI/Anthropic** | No xAI/Grok path | `AiConnectorService` providers = `openai`, `anthropic` only |
| **False “cached success”** | User thinks AI worked | Failed generation still `updateOrCreate`s briefing body from fallback |
| **No queue** | Concurrent generates fight; UX blocks | Controller calls `$this->insights->generate($payload)` inline |

### 2.3 What does **not** exist

- Per-consultant AI brief  
- Weekly generation quota  
- Background job + status polling for intelligence  
- “Kimfay Genius” branding / tab of consultant names  
- First-class **xAI / Grok** provider in connector  
- Structured error codes returned to UI (`ai_error` is dropped in `extractInsightBody`)

---

## 3. Goals

1. **Reliable generation** — When a key is valid, users get real model insights; when not, a clear, actionable error (not a fake executive summary).  
2. **Kimfay Genius** — Managers/admins open a tab of **consultant names**, generate or view that consultant’s weekly AI coaching brief.  
3. **1 generation per week** — Hard lock per consultant (and optional org-wide lock policy); no silent regenerate abuse.  
4. **Background processing** — Generate returns quickly; job runs on queue; UI shows queued → running → ready/failed.  
5. **Portfolio safety** — Genius briefs only use that consultant’s customers/orders (same portfolio rules as Sales Consultant Dashboard PRD).

### Non-goals (this phase)

- Replacing the chat assistant’s full product surface (can share provider layer only).  
- Real-time streaming tokens in UI (nice-to-have P2).  
- Auto-emailing Genius briefs (optional P2).  
- Training custom models.

---

## 4. Workstream A — Fix AI model / generation reliability

### 4.1 Functional requirements

| # | Requirement | Priority |
|---|---|---|
| FR-A1 | **Unified LLM client** used by Intelligence, Genius, Daily Report AI, Chat (shared): provider, model, base URL, timeout, retries. | P0 |
| FR-A2 | Support providers: **OpenAI**, **Anthropic**, **xAI (Grok)** (`XAI_API_KEY`, base `https://api.x.ai/v1`, OpenAI-compatible). Prefer configurable order in admin. | P0 |
| FR-A3 | Admin **health check** button: small completion request; store `health_status` + last error. | P0 |
| FR-A4 | On missing key: **do not** write a “success-looking” briefing; return `422`/`503` with `code: AI_KEY_MISSING` and admin deep-link. | P0 |
| FR-A5 | On provider HTTP error: return `code: AI_PROVIDER_ERROR`, provider message (sanitised), log full body server-side. | P0 |
| FR-A6 | **Shrink prompt payload**: send a compact metrics summary (top N customers, key KPIs, projections)—not full pretty-printed dump. Cap payload size. | P0 |
| FR-A7 | Increase LLM HTTP timeout to configurable **120s** (env); generation itself must be **async** (Workstream C) so edge gateways do not 504. | P0 |
| FR-A8 | Surface `ai_status`, `ai_error`, `provider`, `model` on API response and UI badge (Success / Fallback / Failed / Queued). | P0 |
| FR-A9 | Retry once on timeout / 429 with backoff. | P1 |
| FR-A10 | Model names configurable in settings (`openai_model`, `anthropic_model`, `xai_model`), not hard-coded only. | P1 |
| FR-A11 | Preserve template **offline briefing** only when user opts into “Generate without AI” or when `AI_ALLOW_TEMPLATE_FALLBACK=true` for disaster mode—**default off for explicit Generate**. | P0 |

### 4.2 UI requirements (global AI Intelligence)

- Generate button disabled while job running.  
- Error panel: human message + “Check Administration → AI Connector”.  
- Show last successful generation time; distinguish **template fallback** with warning banner.  
- Never show template text as if it were model output without labelling.

### 4.3 Acceptance criteria (A)

- [ ] With valid key, generate succeeds and `ai_status=success`, `provider` set.  
- [ ] With no key, API fails clearly; UI shows setup guidance; no fake summary.  
- [ ] With invalid key, error message from provider (sanitised) appears.  
- [ ] Large date ranges still succeed (compact prompt).  
- [ ] Prompt log stores status + error for failed runs.

---

## 5. Workstream B — Kimfay Genius (per-consultant tab)

### 5.1 Product intent

**Kimfay Genius** is the AI coach for **one sales consultant at a time**:

- What’s selling in their book  
- What’s at risk (backorders, dormant, declining accounts)  
- What they should do this week  
- Predictions grounded in **their** 3-month history + run-rate  

Not a second org-wide executive report.

### 5.2 Information architecture

```
/app/ai-intelligence
  Tabs:
    [ Executive / Company ]     ← existing global briefing
    [ Kimfay Genius ]           ← NEW
```

**Kimfay Genius tab:**

```
┌────────────────────────────────────────────────────────────┐
│ Kimfay Genius                                              │
│ AI coaching for each sales consultant · 1 brief / week     │
├──────────────┬─────────────────────────────────────────────┤
│ Consultants  │  Selected: Jane Wanjiku (P412)              │
│ ───────────  │  Week of 21 Jul 2026 · Status: Ready        │
│ Jane W.  ●   │  [Generate] disabled if lock active         │
│ John K.  ○   │                                             │
│ …            │  Executive coaching summary…                │
│              │  Portfolio highlights…                      │
│ Search box   │  Risks & dormant…                           │
│              │  Predicted orders…                          │
│              │  Actions this week…                         │
└──────────────┴─────────────────────────────────────────────┘
```

- Left: **consultant names** (active consultants with `rep_code` and/or portfolio).  
- Badge: Ready / Queued / Failed / Locked until {date}.  
- Right: brief content or empty CTA.

### 5.3 Who can use Genius

| Role | Access |
|---|---|
| **Administrator / Super admin** | All users with a sales book; can generate if lock allows |
| **HOD / manager / executive** | Reports with a sales book **plus self** when they also sell (rep-code / `is_consultant` / assignments) |
| **Sales Consultant** | **Self only** |
| **Dual role** (manager/executive who is also a consultant) | Always appears in their own Genius list; portfolio metrics use **personal sales book** (rep-code + assignments), **not** org-wide executive data scope |
| Others without a sales book and without team | Empty list |

**Sales book identity:** `is_consultant`, role `Sales Consultant`, non-empty `rep_code`, or explicit customer assignments — independent of job title.

Permission: `ai.genius.view` / `ai.genius.generate` (or map onto existing AI + KP permissions).

### 5.4 Data scope for each brief

Build portfolio metrics via shared **SalesPortfolioService** (see Sales Consultant Dashboard PRD):

- Customer counts (active / dormant)  
- MTD revenue, order counts, status mix  
- Backorder lines + revenue at risk  
- Fill rate (if available)  
- Top customers, fastest decline, went quiet  
- Items not ordered (top opportunities, **value only when 3m average exists**)  
- Simple projections from that portfolio only  

**Guardrail:** Never include other consultants’ customers in the prompt.

### 5.5 Weekly lock — 1 generation per week

| Rule | Detail |
|---|---|
| **Quota unit** | `(consultant_user_id, week_start)` |
| **Week definition** | ISO week **or** Nairobi **Monday 00:00 → Sunday 23:59:59** (document; recommend **Monday start, Africa/Nairobi**). |
| **Limit** | **1 successful AI generation** per consultant per week. |
| **Failed jobs** | Do **not** consume the weekly slot (allow retry). Template-only offline mode does not consume either. |
| **Regenerate** | Blocked until next week unless role has `ai.genius.force_regenerate` (super admin break-glass; audit log). |
| **UI** | “Next generation available {weekday date}”. Button disabled with tooltip. |
| **API** | `429` or `422` with `code: AI_GENIUS_WEEKLY_LOCK`, `unlock_at`. |

### 5.6 Insight schema (Genius)

Return structured JSON (similar to global, portfolio-flavoured):

```json
{
  "executive_summary": "...",
  "portfolio": { "summary": "...", "highlights": [] },
  "risks": { "summary": "...", "highlights": [] },
  "predictions": { "summary": "...", "highlights": [] },
  "actions": ["...", "...", "..."],
  "week_start": "2026-07-21",
  "consultant": { "id": 12, "name": "…", "rep_code": "P412" }
}
```

Prompt rules: KES only; numbers from payload only; actionable for a field sales coach; no board jargon overload.

### 5.7 Storage

New table e.g. `ai_genius_briefings`:

| Column | Purpose |
|---|---|
| `id` | PK |
| `consultant_user_id` | FK users |
| `week_start` | date (Monday) |
| `insights` | JSON |
| `metrics_snapshot` | JSON (optional, for audit) |
| `ai_status` | success \| failed \| queued \| running |
| `provider` / `model` | |
| `error_message` | nullable |
| `job_id` / `queue_uuid` | nullable |
| `generated_at` | |
| `generated_by_user_id` | actor |
| unique(`consultant_user_id`, `week_start`) | enforces weekly row |

### 5.8 Acceptance criteria (B)

- [ ] Consultant list loads for admin/HOD with correct scope.  
- [ ] Generate creates at most one success per consultant per week.  
- [ ] Second generate same week returns lock error (unless force).  
- [ ] Consultant self-view only sees self.  
- [ ] Prompt/log never contains out-of-portfolio customer IDs (sample test).  
- [ ] Failed generation allows retry same week.

---

## 6. Workstream C — Background generation

### 6.1 Why

Sync `POST …/generate` is the main cause of **gateway timeouts** and user-facing errors on heavy weeks.

### 6.2 Flow

```
User clicks Generate
  → API validates lock + key health
  → Creates briefing row status=queued
  → Dispatches GenerateAiIntelligenceJob / GenerateKimfayGeniusJob
  → Returns 202 { job_id, status: queued }

Worker
  → status=running
  → build metrics → LLM → parse
  → status=success|failed, store insights/error

UI
  → poll GET …/jobs/{id} or briefing endpoint every 2–3s
  → show spinner → ready / failed
```

### 6.3 Requirements

| # | Requirement | Priority |
|---|---|---|
| FR-C1 | Both **global intelligence** and **Genius** use queue jobs. | P0 |
| FR-C2 | Idempotent: duplicate click while queued/running returns same job. | P0 |
| FR-C3 | Job timeout ≥ 180s; retries 1–2 on transient provider errors. | P0 |
| FR-C4 | Document ops: `php artisan queue:work` (or Horizon) **must** run on VPS. | P0 |
| FR-C5 | Optional artisan: `orderwatch:ai-genius-weekly` cron to pre-generate for all active consultants Sunday night (still 1/week). | P1 |
| FR-C6 | Admin can see failed jobs + re-queue. | P1 |

### 6.4 Acceptance criteria (C)

- [ ] Generate API returns in &lt; 2s with 202 when queued.  
- [ ] With worker down, UI shows “Queued — waiting for worker” not silent fail.  
- [ ] Completed job visible after poll without full page reload.

---

## 7. Guardrails

| # | Guardrail |
|---|---|
| G1 | Portfolio scope enforced server-side for Genius; never trust client consultant id without authz. |
| G2 | Weekly lock enforced server-side (unique index + transactional check). |
| G3 | No API keys in frontend; no key values in prompt logs (mask). |
| G4 | Do not invent metrics; LLM instructed + server validates structure. |
| G5 | Fail closed on missing key for explicit Generate (no fake success). |
| G6 | Sanitize provider error strings before UI (no key material). |
| G7 | Rate-limit generate endpoints per user (e.g. 10/hour) in addition to weekly Genius lock. |
| G8 | Impersonation: Genius generate attributed to actor; portfolio of target if admin views as consultant. |
| G9 | Cost control: compact prompts; max tokens capped; weekly lock is primary cost brake for Genius. |
| G10 | Audit: every generate attempt logged (who, consultant, week, status, provider). |

---

## 8. What’s already building vs what needs to be done

### 8.1 Already in product (reuse)

| Building block | Status | Use for |
|---|---|---|
| AI Intelligence UI + date range | ✅ | Company tab |
| Metrics data service | ✅ | Compact for prompt |
| Insight JSON schema (exec summary / sections / actions) | ✅ | Extend for Genius |
| AiConnector (OpenAI/Anthropic keys in admin) | ✅ Partial | Extend xAI + health |
| AiPromptLog | ✅ | All generates |
| Portfolio / rep-code / org scope | ✅ Partial | Genius data |
| Queue infrastructure (Laravel jobs elsewhere) | ✅ Partial | Wire AI jobs |
| Sales Consultant Dashboard PRD | 📄 Spec | Align metrics definitions |

### 8.2 Needs to be built

| Item | Workstream |
|---|---|
| Honest error handling (no fake success) | A |
| Compact prompt builder | A |
| xAI provider + configurable models | A |
| Unified LLM client | A |
| Surface `ai_error` in API + UI | A |
| Kimfay Genius tab + consultant list | B |
| `ai_genius_briefings` + weekly lock | B |
| Portfolio-scoped Genius metrics builder | B |
| Permissions | B |
| Queue jobs + poll endpoints | C |
| Worker/runbook docs | C |
| Optional weekly bulk cron | C |
| Tests (lock, scope, fail paths) | A/B/C |

### 8.3 Suggested implementation order

1. **A0** — Return real errors + stop saving fallback as success; show `ai_error` in UI (**unblocks “I’m getting errors” diagnosis**).  
2. **C0** — Queue global generate (fixes 504s).  
3. **A1** — Compact payload + timeout + health check + xAI.  
4. **B1** — Schema + Genius generate job + weekly lock.  
5. **B2** — Genius UI tab + consultant list.  
6. **B3** — Align metrics with portfolio dashboard definitions.  
7. **C1** — Optional Sunday batch for all consultants.

---

## 9. API sketch

### Global (enhanced)

| Method | Path | Notes |
|---|---|---|
| `GET` | `ai/intelligence` | Metrics + latest briefing status |
| `POST` | `ai/intelligence/generate` | `202` + `job_id` (async) |
| `GET` | `ai/intelligence/jobs/{id}` | Status poll |

### Kimfay Genius

| Method | Path | Notes |
|---|---|---|
| `GET` | `ai/genius/consultants` | List with week status badges |
| `GET` | `ai/genius/consultants/{user}` | Current week brief + lock info |
| `POST` | `ai/genius/consultants/{user}/generate` | Enqueue if lock allows |
| `GET` | `ai/genius/jobs/{id}` | Poll |

### Admin

| Method | Path | Notes |
|---|---|---|
| Existing | AI connector store/status | Add xAI + health test |

---

## 10. Ops / configuration

```env
# Provider keys (server only)
OPENAI_API_KEY=
ANTHROPIC_API_KEY=
XAI_API_KEY=

AI_PROVIDER_ORDER=openai,xai,anthropic
AI_OPENAI_MODEL=gpt-4o-mini
AI_XAI_MODEL=grok-4.5
AI_ANTHROPIC_MODEL=claude-haiku-4-5-20251001
AI_HTTP_TIMEOUT_SECONDS=120
AI_ALLOW_TEMPLATE_FALLBACK=false

# Genius
AI_GENIUS_WEEK_START=monday
AI_GENIUS_TZ=Africa/Nairobi
```

**VPS checklist**

```bash
php artisan queue:work --queue=default,ai --timeout=180
# ensure supervisor/systemd keeps worker alive
```

---

## 11. Test plan

1. No key → generate fails with `AI_KEY_MISSING`; UI copy correct.  
2. Invalid key → `AI_PROVIDER_ERROR`; prompt log failed.  
3. Valid key → success; insights non-empty; provider set.  
4. Global generate async: 202 then poll to success.  
5. Genius: first generate week → success; second → weekly lock.  
6. Genius fail then retry same week → allowed.  
7. Force regenerate (super admin only) audited.  
8. Consultant cannot read/generate another consultant.  
9. HOD cannot generate outside reports.  
10. Prompt size under configured max for heavy date ranges.  
11. Regression: metrics-only GET still never calls LLM.

---

## 12. Success metrics

- Generate success rate (provider) ≥ 95% when key healthy.  
- p95 time-to-202 for generate &lt; 2s; p95 time-to-ready &lt; 90s with worker.  
- Zero “silent template as AI” incidents.  
- ≥ 50% of active consultants have a Genius brief within 2 weeks of launch (if batch cron enabled).  
- Support tickets for “AI insights error” drop week-over-week after A0+C0.

---

## 13. Open questions

1. Confirm brand spelling in UI: **Kimfay Genius** vs **Kim-Fay Genius**.  
2. Week boundary: Monday (Nairobi) OK?  
3. Should consultants see Genius self-serve, or managers only?  
4. Default provider preference: OpenAI vs xAI Grok for cost/quality?  
5. Pre-generate all consultants Sunday night vs on-demand only?  
6. Share Genius brief to consultant via email? (P2)

---

## 14. Document history

| Version | Date | Notes |
|---|---|---|
| 0.1 | 2026-07-23 | Initial PRD — fix AI generation errors; Kimfay Genius per-consultant tab; 1/week lock; background jobs; build vs todo map |
