


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


Sales Consultant module
1. Add serach by consultant namr ot repcode/employee number
2. on viewing the consultantas page add option to search the customers and option like fillrate, add pagination & each column can be sorted
3. on cutomer details have accordion for each section with respective counts & pagination where possibl a. Whitespot -8 b. Documents/Orders - 15 c. common products -20
4. the SO are showing - on filrate please add data where applicable with revenue lost due to fillrate
5. Each SO check the Description value from the payload and add it bellow the SO number
6. Each inventory ID should also have the respective name of product



# Fillrate Module Development Requirements

## Core Features Implementation
1. **Excel Download Functionality**
   - Develop a secure, efficient Excel download capability that generates and exports fillrate data in valid .xlsx format
   - Implement date range selection for Excel downloads, allowing users to specify start and end dates to filter data before export
   - Ensure all Excel files adhere to the existing template structure, with all formatting and sheet layouts preserved
   - Add error handling for large datasets to prevent timeouts or file corruption during download

2. **Sales Order (SO) Data Synchronization with Tally**
   - Build an integration to sync all sales order data from source systems to Tally's fillrate module
   - Implement payload parsing logic to extract partial delivery reasons automatically from incoming SO data
   - Add validation checks to ensure only complete, accurate SO data is synced to Tally to maintain data integrity
   - Create a logging mechanism to track all sync activities, including successful transfers and failed records for troubleshooting

3. **Summary Sheet Configuration and Data Calculation**
   - Develop a dedicated Summary sheet within the Excel template that calculates and displays revenue loss attributed to fillrate issues
   - Implement data segmentation to split total revenue loss into two categories: manufactured items and trading items
   - Add functionality to break down fillrate shortfalls by their specific root causes, as captured from the partial delivery payload
   - Create a customer grouping analysis that identifies which customer segments are most impacted by fillrate issues, including aggregated quantity and value losses
   - Implement a separate "SOs Not Fully Delivered" tab that lists all incomplete sales orders with their corresponding quantities and monetary values
   - Add data validation to identify items with missing price values, flagging these records explicitly as a contributing factor to fillrate issues
   - Ensure all calculations in the Summary sheet are accurate and update dynamically when new data is synced or date ranges are modified

## Technical Requirements
- Maintain full compatibility with the existing fillrate module template structure; do not modify core template formatting
- Implement data validation for all input fields to prevent invalid entries from corrupting datasets
- Add user permissions to restrict access to sensitive financial data within the fillrate module
- Optimize data processing to handle large volumes of SO records without performance degradation
- Include unit tests for all core functionalities: Excel generation, data sync, and summary sheet calculations
- Document all new features for future maintenance and user training




I need to update the inventory system using the provided Excel file by implementing the following requirements in a structured, actionable manner:

### Core Data Structure Implementation
1. Update the inventory database schema to include the required columns, with Inventory ID defined as the primary key. The mandatory columns to add are:
   - Inventory ID (Primary Key)
   - Brand
   - Posting Class
   - Sub Trading Group
   - Supplier

### Frontend Display Requirements
2. Modify the inventory frontend display to format the brand and trading group information as specified:
   - First row: Show the Brand value
   - Second row: Show the Sub Trading Group value preceded by a hyphen, formatted as "- [Sub Trading Group]"
   - Ensure this formatted display is consistent across all inventory list views and detail pages

### Data Processing & Seeding
3. Develop a database seeder that supports both bulk inventory updates and new record creation:
   - The seeder must validate all incoming Excel data against the inventory schema
   - Use Inventory ID to match existing records for updates, or create new records when no matching Inventory ID is found
   - Implement error handling for invalid data types, missing required fields, and duplicate primary key entries
   - Add logging to track the number of records updated, created, and rejected during the seeding process

### Data Matching Requirement
4. Align all inventory records with the Fillrate segmentation data for both Manufacture and Trading categories that is included in the provided Excel file. Map and merge this segmentation data to the corresponding inventory records during the seeding process to ensure full data consistency.

### Validation & Testing
5. Conduct post-implementation verification:
   - Confirm all new columns are properly added to the database schema with correct data types
   - Validate that the frontend display renders the Brand/Sub Trading Group formatting correctly across all device sizes
   - Test the seeder process with a subset of test data to confirm accurate updates and new record creation
   - Verify that all Fillrate segmentation data from the Excel file has been correctly matched and applied to all relevant inventory records



  Implement the following updates to the application's UI and Excel export functionality to add the, Manufactured Vs Partner (Trading),  KP vs CS consumer sales split and remodify fillrate tracking:

1. Core Feature Implementation
   - Add the "DO KP VS Consumer Sales" metric split to the fillrate calculation system
   - Update the fillrate logic to categorize all customer records with categories starting with "KP" under the KP segment, and all other customer categories under the CS (Consumer Sales) segment
   - Apply this customer category split to the entire fillrate summary dashboard on the UI

2. UI Updates
   - Redesign the fillrate summary section to prominently display the KP vs CS split, including separate fillrate calculations, trend indicators, and comparative metrics for each segment
   - Ensure the UI retains responsive design and maintains visual consistency with existing platform styling
   - Add filter controls to allow users to toggle between combined and segment-specific (KP/CS) fillrate views

3. Excel Export Modifications
   - Revise the Excel export template to strictly follow the required sheet sequence:
     1. Instruction sheet: Include detailed guidance on interpreting fillrate metrics, KP/CS categorization rules, and sheet structure
     2. Summary sheet: Feature the high-level fillrate overview, with sub-sections for:
        - Manufactured/Partner product split fillrate data
        - Sector-based fillrate split with separate KP and CS segment aggregations
        - Root cause analysis breakdown for fillrate variances, mapped to both KP and CS segments
   - After the summary sheet, arrange all remaining sheets in the order required for executive stakeholder review, ensuring executive-focused dashboards and high-priority metrics are positioned first
   - Validate that all fillrate calculations in the exported Excel match the values displayed on the UI for both KP and CS segments

4. Validation & Testing
   - Conduct end-to-end testing to confirm accurate categorization of all customer categories into KP and CS segments
   - Verify fillrate values are consistent across the UI and all exported Excel sheets for both segments
   - Confirm the Excel sheet sequence adheres exactly to the specified structure and that all executive sheets are ordered correctly
   - Test edge cases, including customer categories that start with "kp" (lowercase) to ensure case-insensitive categorization, and ensure no customer records are unassigned to either segment


Update all naming conventions to reflect the required change: replace the existing label "KP (Key Partners)" with the standardized name "KP (Kimfay Professional)". Structure all analysis outputs to clearly segment order data by two core business categories: Manufactured goods and Trading (Partners) goods to enable cross-category performance comparison. Validate that all existing order records in the specified SO categories have valid, properly classified root cause reasons, and flag any records that are missing reasons or have unclassified reasons that do not align with the approved list. Generate a consolidated report that summarizes the current state of reason capture, identifies gaps, and provides a structured breakdown of order volumes by parent reason, sub-reason, and business category (Manufactured vs Trading).


Conduct a comprehensive audit of Acumatica system data across the Sales Order (SO) inventory modules, specifically focusing on cancelled sales orders, rejected sales orders, and on-hold sales orders. Verify whether the system currently captures the following pre-defined backorder reasons and fill rate root causes, and assess if these reasons are properly structured with a hierarchical parent-child classification framework:
1. Out of stock - Procurement
2. Out of stock - Production
3. Delay in delivery
4. Promo product
5. Transfer Delays
6. Short Expiry
7. Out of stock - MSA
8. Raw material stockout
9. Discontinued
10. PB Discontinued
11. Delayed Communication
12. Truck Full
13. Price Difference
14. Invoicing Error
15. Stock Variance
16. Isolation Error
17. Non focus
18. Wrong MOQ
19. Order To make
20. Kebs stickers
21. Wrong Product Description
22. System error
23. Conversion delays
24. Wrong code
25. Price Variance
26. Delayed Supplier Payment
27. LPO Error
28. Batch Sequence
29. Conversion issues
30. Price Overcharge
31. NPD
32. Did not pick on shipment
33. Production Stokout

Implement and enforce the required hierarchical reason structure where all sub-reasons are tied to a single parent reason, following the example format: "Cancelled Order - Wrong code" or "Cancelled Order - Wrong MOQ". 