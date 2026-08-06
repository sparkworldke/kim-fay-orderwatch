# KP Sales Consultant Meeting Module

## Summary

Expand the existing KP Meetings CRUD page into a current-month planning, execution, and performance module. Consultants manage their own customer meetings and accepted accompaniment requests; management receives org-scoped team analytics. Existing calendar and Acumatica customer/order data will be reused.

## Key Changes

### Meeting lifecycle and data

- Extend meetings with purpose, `virtual|physical` mode, previous/current notes, outcome, follow-up date or no-follow-up reason, planned/unplanned flag, and `scheduled|completed|cancelled` status.
- Seed the 17 supplied meeting purposes as admin-manageable reference data, preserving historical values when purposes are disabled.
- Add a flexible meeting-action table. Admins manage action categories; four categories may be marked as the dashboard’s “main actions.” Each action records description, owner, due date, status, and completion date.
- Require current notes, outcome, action details where applicable, and either a follow-up date or no-follow-up reason before completion.
- Preserve existing meetings through additive migrations and map existing title/notes/location fields into the expanded model.

### Customers and accompaniment

- Replace free-text customer entry with assigned-customer selection backed by `UserCustomerAssignment`; admins and properly scoped managers may create meetings for consultants within their scope.
- Permit an explicit non-customer/internal meeting path for purposes such as One on One, Directors Review, and internal reporting meetings.
- Add accompaniment invitations with `pending|accepted|declined|cancelled` status and timestamps.
- A meeting appears on another consultant’s dashboard only after acceptance. The owner receives primary credit; accepted accompaniers receive participation credit; team totals count the meeting once.
- Validate customer ownership at creation and retain an immutable customer name/ID snapshot for reporting.

### Planner and dashboards

- Make the main page default to the current calendar month in the application timezone.
- Consultant cards show monthly target versus completed achievement, purpose split, top four action categories with closed/open counts, and plan adherence.
- Add a dated meeting table with customer, purpose, mode, owner/accompaniers, plan status, meeting status, follow-up, and action progress.
- Add a monthly PJP planner with daily capacity, planned meetings, completed planned meetings, missed meetings, cancellations, and unplanned visits.
- Monthly targets default to four meetings per configured working day and can be overridden per consultant/month by an administrator.
- Count every distinct completed meeting once toward the owner’s target. Repeat visits do not create duplicate meeting records but remain valid separate meetings.
- Show repeat/follow-up analysis separately: unique customers visited, total visits, repeat visits, meetings per customer, last/next follow-up, and visits alongside Acumatica monthly purchase count/value and last purchase date.
- Surface B2B preparation and closure prompts: stakeholder role and seniority, site/branch, opportunity or need, decision process, budget/timeline, competitor presence, product/service fit, proposal/sample/site-survey requirement, purchasing pattern, payment/debtor risk, next commitment, owner, and due date.
- Admin and C-suite see company-wide data. Executives and HODs default to their configured org subtree. Management views add consultant, department, status, purpose, mode, customer, and month filters plus team rankings and overdue actions.

### API and permissions

- Add dashboard-summary, planner, purpose configuration, target configuration, meeting actions, accompaniment response, and customer purchase-pattern endpoints.
- Expand meeting create/update responses to return purpose, customer, owner, participants, actions, completion data, and planner classification.
- Apply server-side org scoping through the existing org-tree/customer-assignment services; never rely on frontend filtering for access control.
- Consultants may manage their meetings, actions, and invitations. Accepted accompaniers may view the meeting and update only actions assigned to them. Management access is read-only except for target/configuration privileges granted to administrators.
- Team aggregates deduplicate by meeting ID so accepted accompaniment never inflates company totals.

## Test Plan

- Verify current-month boundaries, timezone handling, working-day target calculation, overrides, and month transitions.
- Verify completed, cancelled, missed, unplanned, repeat, and follow-up meetings produce the correct achievement and adherence figures.
- Verify meeting completion is rejected when required notes, outcome, actions, or follow-up disposition are missing.
- Verify assigned-customer enforcement, internal-meeting exceptions, and customer snapshot retention after assignment changes.
- Verify accompaniment acceptance/decline visibility, individual credit, participant action permissions, and team deduplication.
- Verify consultant, HOD, executive, C-suite, and administrator scopes against direct reports, deeper org subtrees, and out-of-scope records.
- Verify purchase-pattern calculations against Acumatica order history, including customers with no purchases.
- Add backend feature tests for endpoints and authorization plus frontend tests for planner, dashboard cards, filters, close workflow, empty/loading/error states, and responsive tables.
- Run Laravel tests, TypeScript checks, linting, and the production frontend build.

## Assumptions and Defaults

- “Unique meeting count” means one count per completed meeting record; unique customers and repeat visits are separate metrics.
- Actions are flexible, while administrators identify four main categories for dashboard emphasis.
- Meeting credit goes fully to the owner and is labeled as participation for accepted accompaniers; aggregate totals remain deduplicated.
- The main dashboard opens on the current month, while authorized users may select historical months for reporting.
- Monthly target defaults to four meetings per configured working day, excluding weekends; administrator overrides take precedence.
- No Outlook write-back is added in this phase; existing OrderWatch meetings continue appearing in the combined KP Calendar.
