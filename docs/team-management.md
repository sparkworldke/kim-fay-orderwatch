
On the /inventory page, implement modifications to restructure and enhance the appearance of the SKU table according to the following specifications:
1. Reorganize the table columns to match the approved layout: Column 1, Column 2, Column 3, Column 4, Column 5, Keep the other columns Qty on hand	Run rate / day	Days left	Status
2. Within Column 1, create 2 vertically stacked display elements for all table rows: Row 1 displaying the product name, Row 2 displaying the inventory ID, 
3. Assign the following dedicated headers to the remaining columns: Column 2create 2 vertically stacked display elements for all table rows  = Brand - Manfacture/Trading (partner), Column 3 = Warehouse, Column 4 = Stock, Column 5 = UOM
4. Apply consistent visual styling to the restructured table, including proper spacing, alignment, and readability enhancements for all column and row content
5. Test the updated table layout across different screen sizes to ensure responsive behavior and maintain functionality of all existing inventory page features
6. Validate that all text labels are spelled correctly, including verifying "product name", "inventory ID", and "manufacturer" are accurately displayed without typographical errors




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


Teams may be assigned to one or multiple specific brands, and this brand association does not grant any additional system permissions. However, you need to implement a hierarchical filtering capability that allows users to filter core business metrics by brand, including fill rate, backorder status, and inventory data. The filtering hierarchy must support the structure: Partner Brands → Selected Brand Name → Category (with category as an optional, non-mandatory filter level). Ensure this filtering functionality is integrated into the relevant reporting dashboards, maintains accurate metric calculations regardless of the selected filter combinations, and is compatible with the existing team-brand assignment logic without altering any current permission frameworks.



Implement a system guardrail that enables any user role to be assigned the consultant designation, ensuring all order records can be properly attached to and associated with any consultant regardless of their primary system role. The implementation must include the following requirements:
1. Update the user role configuration logic to allow all existing and new system roles to be eligible for consultant status assignment without role-based restrictions
2. Modify the order data model and database schema to add a foreign key relationship that supports linking any order to any active consultant user account
3. Develop validation logic that verifies consultant user status exists before allowing order attachment, while ensuring no role-based filters block valid consultant assignments from any user role
4. Implement audit logging to track all instances where a consultant from a non-traditional role is assigned to an order, for compliance and monitoring purposes
5. Conduct end-to-end testing to confirm that users of every defined system role can be successfully designated as consultants, and that orders can be created, updated, and retrieved with correct associations to these cross-role consultants
6. Ensure the guardrail maintains full backward compatibility with existing order-consultant associations while expanding the eligibility pool to all user roles


can you try to match and create a user list for these users then a migration, remember i should be able to update and hve hod who has reportees and cas see his or her reportee data, there is cross functions eg, in head of brands can view gt and mt data they handle in all sections and report to 1 here is the org structure

executive
csuites
hod
brandsops
sales

1. C-suite report to Exuctive
2. Hods Report to C-suite
3. Sales, Brand Ops and others can either report to Hods or C-Suites

All Members can be in Either GT, MT, KP or Both, however there are others in Operations eg. Customer service, Finance, Marketing, Procument, Production, Store, Dispatch who support the MT/GT/KP teams

Example of a team break down
C-suite - Vignesh (Sees Everything in All sectors) & these alsod see everything teams/departments - Customer service, Finance, Marketing, Procument, Production, Store, Dispatch

MT - Hod - Purity- Sees Both Manufactured and Trading (partner) for only MT
KeyAccount Managers - Jane Oversees Carreffour outlets can only see carrefour orders
A key account manager can have different outlets to oversee (should be attached to them), we can work backwords and look at the SOs/outlets (customers)

Partner Brands - Hod - Muthoni (she has to see everything in GT/MT/KP for only Trading brands for her selelf and a team)
Brand Ops
- They are in chanrge of 1 or several brands, Let them see all partners brands but they can filter down
- Thay are not attached to any sales Orders/outlet for them should be able to see everything

GT - Hod Steve - Sees Both Manufactured and Trading (partner) for only GT
We have Regional Managers and Sales Consultants
- They are attached to a customer(s), work backwords to check


KP - Hod Susan - Sees Both Manufactured and Trading (partner) for only KP
Sales Consultants have customers attached to them
- let them only see respective customers 


Add option of Gaps etc

Share a PRD to Solve this and have a breakdown where we ell not have leaks
1. Teams
2. HOD
3. Consultant/Brand op

How to attach a user to the org chart and keep their role and privileges/permissions

Tests

Save as new team.md






   