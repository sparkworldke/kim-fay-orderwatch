# Update `# Acumatica Data.md` Into an Implementation Spec

## Summary
Rewrite the current broad Acumatica requirements document into a decision-ready technical implementation spec for this repo. The updated document will describe how to build the Acumatica sync platform on the existing Laravel backend and TanStack frontend, replacing absolute “100% accuracy” language with concrete validation, reconciliation, and failure-handling criteria.

## Key Changes
- Structure the document around current system reality:
  - Laravel backend already has Acumatica config, credential validation, audit logging, and sync log basics.
  - Order/customer API controllers and frontend pages exist but are mostly placeholders/demo-data driven.
- Add implementation sections for:
  - Acumatica API client/auth/session handling.
  - Customers, customer categories, sales orders, sales order lines, products, sync runs, reconciliation results, and dead-letter records.
  - Date-range sales order sync UI.
  - Selective customer order sync UI.
  - Background customer category sync job.
  - Structured logs, retries, alerting, and admin visibility.
- Define backend API additions:
  - Admin sync trigger endpoints.
  - Sync status/log endpoints.
  - Customer search endpoint for manual customer selection.
  - Reconciliation report endpoints.
- Define data model additions at a behavior level:
  - Local customer/order/category/product tables or equivalent persistence.
  - Sync run metadata.
  - Failed sync/dead-letter payload storage.
  - Field-level validation results.
- Clarify validation rules:
  - Required Acumatica fields must be present.
  - Local and Acumatica records must reconcile by stable external IDs.
  - Mismatches should be stored with field name, local value, Acumatica value, severity, and remediation status.
- Include staging and production acceptance criteria:
  - Credential validation succeeds.
  - Customer sync creates/updates expected records.
  - Date-range order sync only imports eligible records.
  - Selected-customer sync includes historical and active orders with line items.
  - Category mismatches are logged.
  - Failed records retry and then move to dead-letter storage.

## Test Plan
- Add backend feature tests for sync trigger endpoints, validation failure responses, sync log creation, retry/dead-letter behavior, and reconciliation output.
- Add unit tests for Acumatica response mapping, date filtering, customer category comparison, and sales order line-item normalization.
- Add frontend tests or manual QA steps for date-range sync controls, customer search/selection, sync status display, and error states.
- Include deployment verification steps for staging first, then production post-deploy.

## Assumptions
- This update changes only `# Acumatica Data.md`; no code implementation is performed in this planning step.
- The spec should be technical and implementation-oriented, suitable for an engineer or agent to implement afterward.
- The implementation should build on the existing Laravel Acumatica admin service rather than replacing it.
- Force-push/rebase/amend workflows remain out of scope because this Lovable-connected project must preserve published history.
