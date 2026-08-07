# KP Unified Meetings and Activities Module

## Summary

Evolve Meetings into the shared activity spine for Phone, Email, Meeting, and Visit activities. Preserve the current Meetings UI as a filtered view while adding structured questionnaires, follow-up management, and tiered reporting for consultants, managers, HODs, Executives, and C-Suite.

## Visibility Model

- Consultants see their own activities, assigned customers, and personal follow-ups.
- Managers see their own data plus users in their reporting hierarchy.
- HODs see department or sector activity, consultant adherence, missed activities, and overdue follow-ups.
- Executives and C-Suite receive organization-wide read-only visibility across departments, sectors, consultants, customers, and activity types.
- Executive views include filters for department, sector, consultant, customer, activity type, purpose, status, and date range.
- Administrators receive organization-wide visibility plus configuration rights.
- Customer, organization, and tenant boundaries remain enforced; executive visibility never crosses the current organization.
- Access is capability-based using the existing `org_level`, `department_role`, `executive_view`, reporting hierarchy, and sector scopes—not hard-coded role-name checks.

## Implementation Changes

### Activity model and configuration

- Add `activity_type` with `meeting`, `phone`, `email`, and `visit`; backfill existing records as `meeting`.
- Retain existing meeting and Outlook fields, making them optional for other activity types.
- Add administrator-managed activity reasons and versioned questionnaire templates.
- Support text, select, multi-select, number, date, and boolean questions.
- Store structured answers with the questionnaire version used.
- Connect follow-up actions explicitly to their originating activity.
- Add reporting indexes for owner, customer, department, activity type, status, dates, and follow-up state.

### API and authorization

- Add `/api/kp/activities` endpoints for listing, creating, viewing, updating, completing, and cancelling activities.
- Add `/api/kp/activities/follow-ups` for overdue, due-today, upcoming, and completed follow-ups.
- Add scoped dashboard endpoints supporting `self`, `team`, `department`, and `organization` views.
- Permit `organization` scope only when `executive_view` or an equivalent administrative capability is present.
- Return allowed scopes and filters through the capabilities response so the UI does not infer access from role labels.
- Preserve `/api/kp/meetings*` compatibility by routing through the shared activity service with a Meeting-only filter.
- Apply the resolved visibility scope to record lists, customer searches, exports, aggregates, rankings, and drill-downs.

### User experience

- Preserve the existing Meetings layout, dialogs, cards, targets, actions, participants, and Outlook workflow.
- Add a unified “My Activities” view while retaining Meetings as a Meeting-only view.
- Add activity-type-specific forms and purpose-based questionnaires.
- Require reason, customer where configured, outcome, required answers, and either a follow-up or explicit no-follow-up reason.
- Add an immediately accessible follow-up queue grouped into overdue, due today, and upcoming.
- Add HOD and manager team-adherence dashboards with consultant drill-down.
- Add an Executive Overview for Executive and C-Suite users containing:
  - Activities planned, completed, missed, cancelled, and unplanned.
  - Overall and department-level adherence.
  - Active consultants and customers engaged.
  - Due and overdue follow-ups.
  - Activity-type and purpose distribution.
  - Department, sector, consultant, customer, and period comparisons.
- Allow executives to drill from organization totals into department, consultant, customer, and individual activity records while keeping the experience read-only.
- Restrict reason and questionnaire administration to authorized administrators.

## Compatibility and Delivery

- Migrate and backfill without removing or renaming existing meeting data.
- Centralize activity queries, scope resolution, completion validation, follow-ups, and dashboard calculations in shared services.
- Seed default reasons and questionnaires for all four activity types.
- Deliver in this order:
  1. Shared activity model and backward-compatible Meetings service.
  2. Activity forms and questionnaires.
  3. Follow-up queue.
  4. Manager and HOD reporting.
  5. Executive and C-Suite organization overview with drill-down.
- Defer geo-fencing, GPS check-in/out, route sequencing, scheduled executive emails, and order-cycle sales prompts.

## Test Plan

- Verify all existing meeting workflows remain operational after backfilling.
- Test Phone, Email, Meeting, and Visit creation and completion.
- Verify required reasons, questionnaire answers, outcomes, and follow-ups.
- Verify consultants cannot access another consultant’s activities without hierarchical authority.
- Verify managers and HODs see only their reporting hierarchy, department, or configured sectors.
- Verify Executive and C-Suite users can access organization-wide summaries and drill-downs.
- Verify executive access remains read-only and cannot cross organization boundaries.
- Verify every aggregate, ranking, export, follow-up count, and drill-down applies the same resolved scope.
- Test activity filters, adherence calculations, overdue queues, and backward-compatible meeting endpoints.
- Add backend authorization and aggregation tests plus frontend tests for scope switching, executive filters, conditional forms, and drill-downs.

## Assumptions

- Executive and C-Suite visibility is organization-wide but read-only.
- Administrators retain configuration and correction rights.
- HOD reporting remains department/sector scoped; it is not automatically organization-wide.
- HOD, Executive, and C-Suite reporting ships in-app first.
- Geo-fenced visits remain V2.
- Existing organization, hierarchy, portfolio, and sector-scope rules remain authoritative.
