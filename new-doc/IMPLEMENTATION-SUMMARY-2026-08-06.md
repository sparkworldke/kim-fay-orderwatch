# Kim-Fay Sight — implementation summary

**Completion date:** 6 August 2026  
**Scope:** Improvements completed from the KP/FOL review through Backorders reporting, access control, mobile usability, and deployment preparation.

## 1. KP FOL workflow

### What changed

- Corrected the FOL detail data so sales, volume, purchase history, consumables, SO information, and technician values come from the relevant customer/FOL records instead of defaulting unnecessarily to zero.
- FOL products remain tied to the selected FOL request; an absent FOL ID does not produce unrelated tagged products.
- Technician assignment uses eligible active users with technician roles/designations and the correct reporting hierarchy.
- FOL request permissions continue to control request, approval, invoicing, technician management, and installation actions.

### How it works

The FOL API loads the request, customer evidence, selected consumables, sales-order link, and installation assignment as one scoped response. The screen then enables actions according to the signed-in user’s effective permissions.

## 2. KP view and My Portfolio

### What changed

- Refocused My Portfolio on assigned customers and practical follow-up information.
- Added reliable customer counts, account status, performance information, next actions, contacts, and navigation into customer/order detail.
- Added portfolio verification tooling and improved KP customer/rep-code mapping.
- Users without broad access remain limited to their assigned customer book.

### How it works

Customer assignment and attribution services calculate the effective customer set for the user. KP account endpoints reuse that set, so cards, tables, totals, searches, and drill-down pages share the same scope.

## 3. Customer details and contacts

### What changed

- Improved customer detail pages with contact information, orders, account context, and branch handling.
- Contact editing respects user scope and preserves manually maintained CRM contacts during customer-master synchronization.
- Added clearer contact designations and contact-card behavior.

### How it works

The Acumatica customer master supplies account data while the CRM contact service owns manually maintained contact records. Synchronization updates master fields without overwriting those CRM contacts.

## 4. Partner Brands and product classification

### What changed

- Partner Brands users can see relevant sales, quantity, inventory, and backorder amounts for their assigned brands.
- Brand and trading-group filters only offer values the user is permitted to access.
- Product classification and business-category logic distinguish Kim-Fay manufactured products from trading/partner products.
- Production, inventory, sales, and Backorders reuse the same classification basis.

### How it works

Brand Assignment Scope converts each user’s brand assignments into allowed inventory IDs. API queries apply this scope before totals and detail rows are produced, preventing one brand owner from exporting or viewing another brand’s data.

## 5. Data caching and performance

### What changed

- Added domain-based caching for stable references and frequently reused analytics.
- Customer, portfolio, executive, order-filter, and reference queries use controlled cache durations.
- Cache invalidation is tied to the relevant domain update instead of broadly clearing unrelated data.
- Heavy downloads can be queued and generated outside the normal page request.

### How it works

Each cached API response has a domain key and TTL. Writes invalidate the affected domain. Large Excel reports use the export queue, save the generated workbook, and notify the requesting user when it is ready.

## 6. GT, Executive View, and My Team

### What changed

- Added GT navigation and GT Revenue & Orders/SFA entry points.
- Corrected the GT hierarchy around the designated GT HOD and reportees.
- Added an Executive View with company-level headline performance and controlled drill-down.
- Improved My Team and HOD portfolio behavior so managers see their own descendant tree rather than peer teams.
- Users without reportees do not receive a misleading My Team experience.

### How it works

`reports_to_user_id`, department membership, org level, channel, region/sector data, and customer assignments determine the effective subtree. Executive users receive company-wide read scope; HODs and managers receive only their permitted hierarchy/channel scope.

## 7. Role and access model

### What changed

- Consolidated access around authentication, capabilities, org scope, channel, brand scope, and explicit assignments.
- Customer Service, Production, Partner Brands, GT, KP, Executive, Administrator, Technician, and consultant users retain different viable menu/action sets.
- Empty or invalid scopes fail closed instead of returning all company data.
- Every active team role now receives the read-only `reports.export` permission.

### How it works

Menu capabilities decide which modules appear. Data Scope, Org Scope, department rules, and Brand Assignment Scope then constrain the actual API rows. Excel export uses the same filtered queries, so universal download access does not create universal data access.

## 8. Backorders interface

### What changed

- Reduced the top of the page to three decision KPIs:
  1. Revenue at Risk.
  2. Ready to Release.
  3. Blocked — No Stock.
- Added open lines, SKUs, customers, completion percentage, and current open balance as secondary metrics.
- Added Full, Partial, None, and Unknown stock-coverage states.
- Added derived owner and next action at SKU and sales-order-line level.
- Kept period, brand group, and search prominent; additional filters are behind **More filters**.
- Added clearer partial-load messaging directing users to narrow filters or download the full workbook.

### How it works

Revenue at Risk uses open quantity multiplied by unit price. Stock availability determines whether a line is ready, partially covered, or blocked. Product type and missing reasons determine whether Warehouse/CS, Production, Procurement, or Sales/CS acts next.

## 9. Backorders Excel reporting

### What changed

- The workbook is available as an immediate download or queued download for large extracts.
- It contains role-oriented sheets including Start Here, Summary, Backorders detail, Manufactured Lines, Trading/Partner Lines, Exposure by SKU, reasons, customers, products, orders, missing prices, and resolved Backorders.
- The Summary sheet uses the same three decision KPIs as the screen.
- Detail rows include coverage, responsible function, and recommended next action.
- A database migration grants report-export permission to existing roles; the role seeder covers fresh installations and newly seeded/custom roles.

### How it works

The export controller runs the same scoped Backorders query as the interface, applies current filters, enriches rows with stock and catalog data, and passes them to the multi-sheet Excel exporter. Queued exports execute the same controller under the requesting user’s identity.

## 10. Mobile and smartphone experience

### What changed

- Mobile typography is readable instead of inheriting the desktop micro-density.
- Buttons and interactive controls have touch-friendly minimum heights.
- Main content leaves safe space for the fixed bottom navigation and device home indicator.
- Backorders uses stacked SKU cards on phones and stacked sales-order cards when expanded; desktop keeps its detailed table.
- Mobile Backorders cards show customer, SO, dates, status, quantity, RaR, coverage, reason, owner, and action.
- The AI assistant is full-screen on phone sizes and remains a floating panel on larger screens.

### How it works

Responsive Tailwind breakpoints select phone cards below `md` and desktop tables from `md` upward. Global mobile CSS supplies readable type, touch targets, and safe-area spacing without changing the desktop layout.

## 11. Scheduled jobs and memory control

### What changed

- High-frequency application tasks such as OTP pruning and production-summary refresh use 30-minute Laravel schedules.
- Heavy jobs retain overlap locks and task-specific schedules to avoid duplicate or concurrent work.
- The deployment guide specifies a 30-minute server scheduler trigger as requested.

### How it works

Laravel’s scheduler evaluates registered jobs when `schedule:run` executes. `withoutOverlapping` prevents a second copy of a long task from starting while the first is still active. The selected server cron frequency limits how often the scheduler wakes up.

## 12. Deployment and handover

The deployment runbook is in [`PUSH-AND-DEPLOY-UPDATES.md`](./PUSH-AND-DEPLOY-UPDATES.md). It covers:

- Reviewing, committing, and pushing without rewriting Lovable-connected Git history.
- Pulling with `--ff-only`.
- Installing frontend and Composer dependencies.
- Building the production frontend.
- Running `php artisan migrate --force`.
- Clearing and rebuilding Laravel caches.
- Restarting queues.
- Configuring the 30-minute cron entry.
- Verifying Backorders, Excel downloads, role scope, migrations, queues, and schedules.

## 13. Verification completed

- Production frontend builds completed successfully.
- PHP syntax checks passed for the new reporting migration, seeder, and test.
- Universal report access test passed.
- Filtered Backorders Excel workbook test passed with 29 assertions.
- Backorder metrics test passed with 6 assertions.
- Git whitespace checks passed on the completed changes.

## 14. Files still awaiting the final commit

At the time this summary was created, the final reporting-access and mobile changes were still present in the working tree. They are included by the deployment guide’s staging command:

- `backend/database/seeders/RolesPermissionsSeeder.php`
- `backend/database/migrations/2026_08_06_000001_grant_report_export_to_all_roles.php`
- `backend/tests/Feature/UniversalReportExportAccessTest.php`
- `src/components/ai-assistant.tsx`
- `src/routes/app.backorders.tsx`
- `src/styles.css`
- `new-doc/PUSH-AND-DEPLOY-UPDATES.md`
- `new-doc/IMPLEMENTATION-SUMMARY-2026-08-06.md`

Review these files, commit them normally, and push without force-pushing.
