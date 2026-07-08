Refactor the Laravel application's user and team management system to implement a hierarchical departmental structure with role-based access control, following the specified requirements:

1. **Team and User Structure Implementation**
   - Leverage Laravel's native teams feature to create department-based teams representing all organizational units: MT/Consumer Sales, GT, KP, Customer Service, Marketing, C-Suite, IT, hr, Dispatch, Stores, and Production
   - Implement user role hierarchies within teams:
     - C-suite and executive users granted cross-organizational oversight permissions
     - Head of Department (HOD) users assigned to manage their specific department team
     - Team members assigned to their respective department teams under their HOD
   - Add an `rep_code` (employee number) field to the user model with support for dynamic updates, as rep_codes may change over time and may not always align with Acumatica system data. Implement a manual matching interface that allows administrators to map local user rep_codes to corresponding Acumatica identifiers.

2. **Access Control for Customer Data**
   - For HODs and team members in customer-facing departments (MT/Consumer Sales, GT, KP), restrict customer data visibility to only customers assigned to their specific department. Ensure HODs can only view customer data associated with their department's customer portfolio.
   - For non-customer-facing departments (Customer Service, Marketing, C-Suite, IT, Dispatch, Stores, Production), implement unrestricted access to organizational-wide operational data while maintaining role-specific menu and permission restrictions.

3. **Dynamic Role-Based Permission System**
   - Build a granular, dynamic permission system that defines:
     - Menu visibility rules for each role, specifying which application menus are accessible or hidden based on user's department and role
     - Data masking configurations, including revenue blurring for roles that should not have access to full financial details
     - Department-specific menu sets that align with each team's operational needs
   - For all users assigned to departments with attached customers, enforce granular customer data access: users only view data for their assigned customers, including full access to related metrics: fill rate, email orders, backorders, and order suggestions.

4. **Activity Tracking and Session Management**
   - Add an activity tracking tab accessible to administrators and individual users that logs all user sessions, including login timestamps, logout events, and session duration
   - Implement dual logout functionality: support for manual user-initiated logout, plus automatic session termination for idle sessions after a configurable period of inactivity to maintain system security.

guardrail: any role can be a consultant that is we can their orders attched to them


WhatsApp/ Telegram Bot
Have each persons whatsapp name saved so that in future chat it can sync, Add this however we have to check on authorization they confirm via whatsapp number or SMS/Email is snt with OTP after entering give them Guide on what to as
a. yesterdays orders, Filteat for June, July, etc BackOrder value, Pending Approval, SOS, etca
  

   


Implement site-wide clickable interactive elements with the following standardized functionality:
1. For all customer identifiers: Make every display of customer names and customer IDs clickable across the entire platform. Each clickable element must navigate to the dedicated customer detail page that displays full customer information, order history, and associated transactions.
2. For all sales order (SO) identifiers: Ensure all SO IDs are clickable site-wide. Clicking an SO ID must redirect users to the corresponding SO detail page containing complete order information, line items, and status updates.
3. For all inventory-related identifiers: Make all SKU names and inventory IDs clickable throughout the site. Each clickable element must link to the product/inventory detail page that shows stock levels, product specifications, and associated sales records.
4. For all user/sales consultant identifiers: Correct the spelling error and implement clickable functionality for all Sales Consultant names, IDs, and rep codes displayed site-wide. Clicking these elements must navigate to the sales consultant's profile page showing their assigned customers, sales performance metrics, and commission records.
5. For all date elements: Implement two key date-related features: 
   - Make every date displayed across the site clickable
   - Add a consistent "View Date" button adjacent to all date displays
   - Both the clickable date and the "View Date" button must trigger the display of a comprehensive list of all sales orders (SOs) associated with that specific date, including filter and export options for the generated list.

Additional requirements:
- Apply consistent styling (including hover states, cursor indicators, and accessible link formatting) to all clickable elements to ensure uniform user experience
- Ensure all clickable elements maintain accessibility compliance (proper ARIA labels, keyboard navigation support, and screen reader compatibility)
- Test all clickable navigation flows across all pages of the site to confirm they function correctly and load the appropriate destination pages
- Verify that the date click and "View Date" button functionality properly filters and displays all relevant SOs for the selected date, including edge cases such as dates with no associated orders



### Inventory System Error Resolution & Feature Implementation
#### 1. Database Error Fix (SQLSTATE[42S22])
Resolve the `Unknown column 'updated_at' in 'field list'` error for the `inventory_sku_insights` table in the `kimfay_order_watch` MySQL database (host: 127.0.0.1, port: 3306):
- First, audit the `inventory_sku_insights` table schema to confirm missing columns; add the `updated_at` TIMESTAMP column with default value `CURRENT_TIMESTAMP` and `ON UPDATE CURRENT_TIMESTAMP` property to match the Eloquent model's default timestamp expectations
- Validate the fix by executing the failed insert query to confirm successful record creation, matching the values provided: `inventory_id = 'VATCR0010', date_from = '2026-04-09', date_to = '2026-07-08', ai_response = '{"insights":[],"data_gaps":[],"ai_status":"failed"}', ai_status = 'failed', data_gaps = [], generated_at = '2026-07-08 03:14:27', expires_at = '2026-07-08 07:14:27', updated_at = '2026-07-08 03:14:27', created_at = '2026-07-08 03:14:27'`

#### 2. Inventory Warehouse Summary Table Implementation
Build a clickable warehouse summary table that retains all existing warehouse entries, displays the total number of SKUs per warehouse, and maintains the specified SKU counts for confirmed locations:
- Include all original warehouse listings with populated SKU totals: `fgs (10000 SKUs)`, `fgs 2 (populate accurate total SKUs upon data retrieval)`, `msa (100 SKUs)`
- Implement a click event handler for each warehouse row that navigates to the full inventory detail table for that warehouse's stock

#### 3. Full Inventory Detail Table Implementation
Develop a paginated full inventory items table with compliant pagination controls and entry display options:
- Build the table to render all inventory items for the selected warehouse
- Implement pagination that matches the required format, including the entry range display: `5,541 – 5,543 of 5,543`
- Add an items-per-page dropdown with supported options: 20, 50, 100 entries per page
- Ensure pagination logic correctly calculates and displays entry ranges, and supports seamless navigation between pages

#### 4. Validation & Testing
- Test the end-to-end workflow: confirm the database error is resolved, warehouse summary table loads with accurate SKU counts, clicking a warehouse navigates to the full detail table, and all pagination controls function as expected with correct entry counts displayed


### Revamped Daily Email Notification Template Instructions
Please update the daily email notification template according to the following structured requirements:
1. Core content adjustment: Remove the numerical incomplete order summary line that displays the count of incomplete orders (e.g., the example line "371 incomplete orders — ") and the associated "xx incomplete orders" placeholder text from the email body.
2. Section modification requirement: For the comment marked "#4/ hide it for now - 4. Nairobi & Mombasa 24hr SLA — 7 Jul 2026", implement a temporary full hide of this entire SLA tracking section. This includes hiding the section header, and the full associated metric blocks for both regions:
   - Full Nairobi 24hr SLA metric block: "Nairobi (24hr): 0.0% not delivered after 24h (0 of 0 orders, 0 completed, KES 0 at risk)"
   - Full Mombasa 24hr SLA metric block: "Mombasa (24hr): 0.0% not delivered after 24h (0 of 0 orders, 0 completed, KES 0 at risk)"
3. Implementation check: After making the above edits, verify that the remaining email content maintains logical flow, all removed content is fully excluded from outgoing versions of the daily notification, and the hidden SLA section is only retained in the template source for potential future reactivation.