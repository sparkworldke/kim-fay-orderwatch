Please implement the following critical fixes and enhancements to the account creation, inventory synchronization, and order synchronization systems:

1. **Account Creation System Update**:

- Modify the account creation workflow to either add a timestamp update for the 'verified_at' field upon successful account registration, or completely remove the redundant email verification step from the new user onboarding process to eliminate incomplete verification states.

2. **Order Synchronization Enhancement**:

- Update the order sync logic to implement a comprehensive comparison mechanism that automatically retrieves the latest order statuses from the source system, compares them against local database records, and updates any mismatched statuses to ensure data consistency across all platforms.

3. **Inventory Synchronization Error Resolution**:

- Fix the misleading error message "cant sync inventory is runing" that incorrectly appears when an inventory sync is already active in the background. Correct the grammar of the error message and ensure it only triggers when a sync is actually in progress, eliminating false positive error reports.

- Implement a dedicated stop sync button for individual synchronization modules, including inventory, that allows users to terminate a running sync process for the specific module it is initiated from. This button must only be visible and interactive when a sync is actively running for that module.

- Resolve the core issue where synchronization processes continue to run in the background even after an error message is displayed to the user, ensuring that all background sync operations properly terminate when errors occur or when the user initiates a stop action.

All changes must undergo functional testing to verify: account creation flows work as intended, order statuses sync accurately, inventory sync errors display correctly, the stop sync button functions reliably, and background processes terminate properly when required.

Implement the following updates to the order management system's user interface:

1. For the Backorders tab/menu:

- Integrate interactive, data-visualization graphs that display key backorder metrics including historical backorder volume trends, inventory lead time correlations, and product category backorder distribution

- Add a dedicated field to capture and display detailed reason codes for each backorder, with options to categorize backorders by root causes such as supplier delays, inventory shortages, production issues, or logistics disruptions

- Ensure the graphs support filtering by date range, product line, and warehouse location, and that the backorder reason field is editable by authorized staff

- Reference the provided screenshots to align the new UI components with the existing system's design language and layout

2. For the main Orders section:

- Add a dedicated input field/slot to capture and document the specific reason for order rejection, supporting both pre-defined rejection reason options (e.g., out-of-stock, customer request, invalid payment, address error) and free-text notes for additional context

- Make the order rejection reason field mandatory when an order is marked as rejected, to ensure complete audit tracking of all rejected orders

- Implement validation to prevent order rejection status from being saved without a populated reason field, and display the rejection reason prominently within the order details view for future reference

3. Conduct end-to-end testing to verify that all new UI elements function correctly across desktop and mobile views, that data is accurately captured and stored in the backend database, and that the graphs render correctly with real-time backorder data. Ensure all updates maintain consistency with the platform's existing accessibility and performance standards.

Implement the following two critical improvements to the system:

1. Sync backorder reasons and rejection reasons from the Acumatica ERP system into the application's database. Ensure this synchronization process is automated, reliable, and includes error handling for failed API calls or data mismatches. Add validation checks to confirm all required reason codes are properly imported and stored in the correct data structures for use across order management workflows.

2. Update all email templates in the application to include the correct FRONTEND_URL variable in all applicable links. Resolve the current 404 error that occurs when users click links in generated emails by verifying that FRONTEND_URL is properly injected during template rendering, all relative paths are correctly constructed, and links point to valid frontend routes. Conduct end-to-end testing of all email types to confirm links function as expected in both staging and production environments, with no remaining 404 errors

### Implementation Breakdown: Secure Import Guardrails for Laravel Dashboard Email Import System

#### Core Project Context

You have built a custom Laravel dashboard to centralize email filtering and importing, as native Outlook rules failed to reliably sort incoming orders from 30+ non-uniform branch emails linked to your main Chandara supermarket brand. Your order emails originate from inconsistent sender addresses and lack standardized subject lines, making manual or native email management unfeasible. Below is a structured, actionable implementation plan for your two proposed import strategies, including guardrails to ensure data integrity, security, and operational reliability.

---

#### 1. Strategy 1: Wildcard Import Implementation & Guardrails

This approach uses pattern-based wildcard matching to aggregate orders from all branch emails under the Chandara main brand, reducing manual configuration overhead.

##### Implementation Steps

1. Integrate an authenticated IMAP connection with your Outlook 365/Exchange account to pull unread emails into your Laravel dashboard’s database, scheduled via Laravel’s task scheduler to run every 15 minutes to avoid rate limits.

2. Build a regex-powered wildcard matching system in Laravel’s `EmailImportService` class to identify Chandara branch emails (e.g., pattern `/.+@chandara-supermarket\.com$/` to match all sender addresses ending in your official domain, or `/branch-\d+@chandara\.com$/` for numbered branch sub-addresses).

3. Map all matched wildcard emails to the central `Chandara` brand record in your dashboard’s database, with a dynamic field to tag the extracted branch ID from the sender email for internal sorting.

##### Mandatory Guardrails

- Security guardrail: Restrict wildcard pattern creation to admin-only users in your Laravel authorization system (use Spatie Permissions to assign the `create-email-wildcards` permission exclusively to administrative roles).

- Data integrity guardrail: Log all wildcard-matched imports with the full sender email, timestamp, and regex pattern used to enable audit trails; block any wildcard pattern that could match non-branch emails (e.g., block overly broad patterns like `/.+@.+/` that would pull in unrelated external emails).

- Rate-limiting guardrail: Cap wildcard imports to 500 emails per hour to avoid overwhelming your Outlook API connection and triggering account restrictions.

- Duplicate prevention guardrail: Add a unique composite index on `(message_id, sender_email)` in your emails database table to block importing identical order emails multiple times.

---

#### 2. Strategy 2: Branch-Specific Exact Email Import Implementation & Guardrails

This approach uses a pre-configured list of the 30 exact branch sender emails to import orders, tagged to their respective Chandara branches for granular tracking.

##### Implementation Steps

1. Build a CRUD interface in your Laravel dashboard to let admins add, edit, and deactivate the 30 verified branch sender emails, linked to individual branch records (e.g., map `bangkok-branch@chandara.com` to the Bangkok branch record in your system).

2. Configure the same IMAP connection to scan incoming emails, matching sender addresses to your pre-vetted list of branch emails, and assign each imported order to its corresponding branch automatically.

3. Add a manual tagging interface in the dashboard to let staff reassign orders if an unrecognized new branch email is detected, with a workflow to submit new emails for admin approval before being added to the official list.

##### Mandatory Guardrails

- Verification guardrail: Require dual admin approval to add any new branch email to the official list; block imports from any email not pre-approved in the system to eliminate spam or fraudulent order submissions.

- Access control guardrail: Restrict branch-level email viewing to assigned branch managers, while global admins can view all Chandara branch orders, implemented via Laravel’s policy system.

- Inventory alignment guardrail: Automatically route imported branch orders to that branch’s inventory queue in your Laravel dashboard’s order management module to prevent cross-branch inventory discrepancies.

- Expiry guardrail: Add an auto-deactivation rule for any branch email that has not sent an order in 90 days, with an admin alert to review and either reactivate or permanently remove the unused address.

---

#### Cross-Strategy Mandatory Requirements (Both Import Options)

1. Create a centralized dashboard widget that displays real-time import metrics: number of imported orders in the last 24 hours, number of unmatched unrecognized emails, and import success rate, with alerts sent to admin Slack/email if the success rate drops below 99%.

2. Build a retry mechanism for failed imports (e.g., failed IMAP connections) that retries up to 3 times with exponential backoff, logging all failures for debugging.

3. Add end-to-end encryption for all stored sender email credentials and imported order data, aligned with Laravel’s built-in encryption features to comply with data privacy regulations for customer order information.

4. Write PHPUnit feature tests for both import strategies to validate that wildcard patterns correctly match valid branch emails, block invalid addresses, and that branch-specific imports correctly tag orders to the right branch.

5. Conduct a 7-day parallel testing period where both import strategies run in a staging environment to compare accuracy: measure how many orders are correctly matched, how many unrecognized emails are caught, and the operational overhead of each strategy to select the best long-term solution for your dashboard. kim-fay-orderwatch follow the file for advice

Create a comprehensive cron job scheduling system with the following requirements and constraints, ensuring no job queues are implemented:

1. **Core 3-hour interval sync jobs**:

- Implement three primary sync processes that run every 3 hours:

a. Email synchronization

b. Order matching

c. Daily sales order synchronization

- Add idempotency checks to prevent redundant processing: if a sync task was completed within the last 3 hours (e.g., a job run at 6 AM must not reprocess any tasks that were successfully completed during the 6 AM run when it executes again at 9 AM), store last successful run timestamps for each specific task category to enforce this constraint.

2. **Sales Order (SO) status update job**:

- Develop a dedicated SO sync update job that exclusively modifies sales order statuses, including transitions from hold to open, open to shipping, and all other required status progression workflows. This job runs independently to ensure real-time status accuracy.

3. **Inventory data check job**:

- Schedule an inventory synchronization job to run every 5 hours to maintain accurate stock level records across all systems.

4. **Backorder processing job**:

- Set a fixed daily schedule for backorder validation and processing to run at 4 PM every day to ensure timely resolution of pending orders.

5. **Additional sync optimization**:

- Analyze all remaining unscheduled synchronization tasks and recommend and implement optimal timing for each job, considering system load, business hours, and dependency on other scheduled jobs to avoid resource conflicts. Ensure all cron job timings are aligned to minimize performance impact on production systems while meeting all business requirements for data freshness.

All jobs must be configured to run independently without relying on any queueing system, with proper logging of execution times and success/failure status for monitoring and troubleshooting purposes.

Create a Laravel task scheduler that implements the following requirements strictly without using Laravel's queue system:

1. Configure a recurring cron job scheduled to run every Tuesday through Saturday at 7:30 AM. This job must generate and send a daily report email that includes data from the previous calendar day.

2. Implement an additional scheduled task that monitors the data sync process. This task must send an immediate alert email to commercialtechlead@kimfay.com in two specific scenarios:

- When new data is successfully synced to the system

- When any data sync guardrail check fails

3. All scheduling logic must be defined exclusively within Laravel's native scheduler (routes/console.php or the Kernel's schedule method) with no queue worker dependencies. Ensure all email sending logic executes synchronously within the scheduled command itself.

4. Add proper error handling for email delivery failures, log all task executions (including successful sends, sync status updates, and guardrail failures) to Laravel's logging system, and verify that the cron schedule expression correctly targets the 7:30 AM Tuesday-Saturday window for the daily report. Test both tasks to confirm they trigger at their specified times and deliver emails to the correct recipients under all defined conditions.

Ensure the email reporting functionality in the admin module adheres to the following requirements:

1. The preconfigured recipient email addresses stored in the admin module for report distribution are correctly pulled into the email composition interface.

2. Implement distinct "Send To" (primary recipients) and "CC" (carbon copy recipients) fields within the email interface, populated with the preconfigured recipient lists from the admin module.

3. Preserve the thread integrity when users reply to report emails: configure the reply function to automatically set all original "Send To" recipients as the primary recipients of the reply email, and retain all original CC recipients in the CC field of the reply to maintain full visibility for all involved parties.

4. Validate that all preconfigured admin module email addresses are properly loaded into the respective recipient fields without manual re-entry, and test the reply flow to confirm recipient retention works as expected across all supported email clients.

Implement role-based access control (RBAC) with the following permission levels and feature visibility rules:

1. **Admin Role**:

- Full unrestricted access to all system features and functionalities

- Visibility granted for all modules including Acumatica, AI Keys, Roles, Permissions, Notification Rules, Mail, Customers, Order Match, and Administration

- All sync options enabled

2. **Customer Service Role**:

- View access to all system features except Acumatica, AI Keys, Roles, Permissions, and Notification Rules (these modules must be hidden)

- All sync options enabled

- Maintain visibility of Mail, Customers, Order Match, and Administration modules

3. **All Other User Roles**:

- View-only access to permitted modules, with no sync options available

- Hide the following modules: Mail, Customers, Order Match, and Administration

- Restrict all edit, modify, and sync capabilities to only view permissions for accessible features

Ensure all role-based restrictions are enforced at both the UI level (hiding restricted elements) and backend API level to prevent unauthorized access. Test each role's permissions thoroughly to verify all visibility and functionality constraints are correctly applied.