Add option to sync Non-sales-order documents from Acumatica — QT, RC, CM, and PL. Dashboard and Orders show SO only.

Sync from Acumatica
Quote (QT)
Return / Credit Note (RC)
Credit Memo (CM)
Pick List (PL) with dates range on the credit-notes-more page and add filters and stat card to show count for each and by change of each filter you have dynamic card changes, 


Strictly send the following notification rules configuration details **only** to the dedicated email address commercialtechlead@kimfay.com, with no distribution to any other recipients. Format the content clearly in the email body to ensure all rule identifiers, alert channels, last evaluation timestamps, and last trigger timestamps are accurately presented as listed below:

## Notification Rules
1. R1 - Critical Orders Pending
   - Alert channels: Email, In-App
   - Last evaluated: Never
   - Last triggered: Never
2. R2 - SLA Breach
   - Alert channels: Email
   - Last evaluated: Never
   - Last triggered: Never
3. R3 - Revenue at Risk
   - Alert channels: Email
   - Last evaluated: Never
   - Last triggered: Never
4. R4 - AI Cycle Complete
   - Alert channels: In-App
   - Last evaluated: Never
   - Last triggered: Never
5. R5 - Order Match Queue Backlog
   - Alert channels: Email
   - Last evaluated: 30/06/2026, 10:04:40
   - Last triggered: 30/06/2026, 10:04:40
6. R6 - Order Match Duplicate PO
   - Alert channels: Email
   - Last evaluated: 30/06/2026, 10:05:02
   - Last triggered: 30/06/2026, 10:05:02

Before sending, verify that all timestamp values, channel assignments, and rule names match the source data exactly, confirm no additional recipients are added to the email, and validate that the email is transmitted securely to the specified sole recipient.



Update the inbox accordion component with the following requirements:
1. Implement an initial state where all inbox accordions remain collapsed by default; they must only expand when a user explicitly interacts with them (such as clicking the accordion header) to prevent automatic opening on page load or data refresh.
2. Add three required text displays to the accordion header:
   - A counter showing the total number of emails for the associated category
   - A separate counter showing the total number of emails that include attachments within that category
   - Place the PO count value directly adjacent to these two new metrics, all positioned in the accordion header section
3. Ensure all numerical counters are accurately calculated and dynamically update when the inbox's email data changes.
4. Verify that the accordion interaction works as intended across all supported devices and screen sizes, with all counter text elements properly aligned and visible without layout overflow.
5. Test the implementation to confirm no accordions open automatically on initial page render, navigation to the inbox page, or after data synchronization, and that all three counter values display correctly for every accordion instance.


Implement a feature enhancement for the Notification rules module by adding a configurable option that enables administrators to assign specific email addresses and user roles as recipients for individual notification types. This feature must include:
1. An intuitive UI section within the existing Notification rules configuration panel that displays multi-select dropdowns for both email recipients and role-based recipients
2. Backend validation to ensure only valid, active system emails and existing roles can be assigned
3. A database schema update to store the assigned email and role recipient mappings linked to each specific notification rule
4. Logic to automatically include all assigned email addresses and users belonging to the selected roles in the recipient list when the associated notification is triggered
5. Comprehensive unit and integration tests to verify that all assigned recipients correctly receive the specified notifications, including edge case testing for role membership changes and invalid email handling
6. Access controls to restrict modification of these recipient settings to only users with administrative privileges
7. Documentation updates to the system admin guide explaining how to configure and use this new recipient assignment feature

Implement the required display functionality for the Cron Job tab according to the following specifications:

1. Core Display Requirements
   - On the dedicated Cron Job tab, render a structured table or list that presents all specified Laravel scheduler commands
   - For each command, include its complete cron expression, the full artisan command string, its "Last Run" timestamp, and its "Next Due" time calculation
   - Ensure all provided commands are accurately displayed with their respective cron schedules and execution statuses:
     - `*/15 * * * * php artisan otp:prune` with Next Due timestamp calculated as 6 minutes from now
     - `0 * * * * php artisan acumatica:sync-categories` with Next Due timestamp calculated as 51 minutes from now
     - `0 * * * * php artisan orderwatch:evaluate-order-match-notifications` with Next Due timestamp calculated as 51 minutes from now
     - `0 7 * * 2-6 php artisan orderwatch:send-daily-report-fixed --source=scheduler` with its corresponding next run calculation

2. Layout and Formatting Specifications
   - Align command entries with visual separators (the provided dotted lines) to clearly distinguish each command row and improve readability of associated timestamp metadata
   - Format the cron expression column with proper spacing to maintain readability of the 5-part cron schedule
   - Position the "Next Due" timestamp consistently aligned to the right of each command entry for uniform presentation

3. Functional Requirements
   - Implement dynamic timestamp calculation logic that automatically updates the "Last Run" and "Next Due" times in real time, matching the example time offsets provided
   - Ensure the tab loads all command data correctly on initial render, with no missing entries or formatting errors
   - Verify that all command strings, including the optional `--source=scheduler` flag, are displayed in full without truncation