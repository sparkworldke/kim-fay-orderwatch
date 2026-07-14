
"SalespersonID": {
        "value": "P505"
      },
 

#Look Up
Implement a new lookup feature that enables users to search and retrieve complete data sets using a single ID input parameter. The supported lookup IDs include Inventory ID, SalesPersonID, Consultant ID, ZoneID, Route Code, and Route Name. This functionality should mirror the existing JSON data retrieval pattern currently implemented for Sales Orders (SO) and customer records within the Admin module. Additionally, add a dedicated copy icon to the search results section that allows users to instantly copy the full retrieved JSON payload to their clipboard. Ensure the feature maintains consistency with the Admin module's existing UI/UX standards, includes proper input validation for all supported ID formats, implements error handling for invalid or non-existent IDs, and undergoes end-to-end testing to confirm accurate data retrieval and reliable copy functionality.


#frontend
Implement the following updates for the Outlets feature: frontend
1. Hide the outlet ID formatted as CUSTXXXX on all outlet displays
2. For outlets that have branches, format branch names to follow the pattern "ParentOutletName BranchLocation" (e.g., Chandarana Kisumu, Naivas Jogoo Rd) to clearly associate each branch with its parent outlet
3. On the main Outlet page, maintain the existing Description and Offers sections, then add a section that displays exactly 5 randomly selected branch outlets of the parent outlet; this selection must re-randomize and refresh the displayed branches every time the page loads or refreshes
4. Add a "View all branches" link/button on the main Outlet page that navigates to a dedicated branches tab page. This tab page must include: a functional search bar to filter branches by name, and a region filter component that allows users to narrow down branches by their assigned geographic region
5. For all parent outlets that have an assigned region slot, display the associated region(s) prominently on the main Outlet page
6. Implement pagination functionality on two core pages: the main Outlets listing page, and the financial institutions main page to improve load performance and user navigation through large datasets


### Implementation Plan: Sales Representative (Consultant) Feature Enhancement
---
#### Phase 1: Data Layer Implementation
1. **Model & Database Migration Development**
   - Create a new database model and corresponding migration for the `Consultants` (Sales Representatives) entity. The core required fields for the model are:
     - Primary key ID
     - `RepCode`: Unique alphanumeric identifier for each sales representative (required, unique constraint enforced)
     - `Sales Rep`: Full name of the sales representative (optional, as specified)
     - Timestamps for record creation and updates
   - Update the core Sales Order (SO) model to add a foreign key linking to the new `Consultants` model, enabling association of sales representatives to individual orders. - 
   - Build a one-time data sync script to backfill existing Sales Order records with matching sales representative data, populating the new linked field in the SO table to ensure historical data consistency.
- Add 2 Tabs Improt from SO or Import from Consultants Table
2. **Sales Representative Selection Functionality for Sales Orders**
   - Add a dropdown/selection field to all Sales Order creation and editing interfaces to allow users to assign an active sales representative from the `Consultants` list.
   - Implement validation to only display active, available sales representatives in the selection list, and enforce required assignment logic for new SOs (aligned with business workflows).

#### Phase 2: Sales Representative Dashboard & Analytics Development
1. **Dedicated Sales Representative Account View**
   - Build a authenticated, role-restricted dashboard accessible only to assigned sales representatives, which surfaces all account-specific data for their assigned territory/scope.
   - Add the following core sections to the dashboard:
     - **Assigned Customers List**: A filterable, sortable table displaying all customers assigned to the sales representative, with core contact details and associated order history links.
     - **Stat Cards with Key Metrics**: Display real-time summary counts including: total assigned customers, total active sales orders, total completed sales, monthly revenue generated, and pending order value.
     - **Top-Selling Products/Services**: A ranked list of the products/services the sales representative has sold most frequently, including volume and revenue breakdowns over customizable date ranges (30/90/365 days).

#### Phase 3: AI-Powered Insights with OpenAI Integration
1. **OpenAI Comment & Prediction Feature Implementation**
   - Build a backend integration that pulls a sales representative’s full historical order data, customer demographics, and top-selling items to generate a structured prompt for OpenAI.
   - Use the following optimized prompt to generate actionable insights, predictive forecasts, and strategic comments:
     ```
     Act as a senior sales operations analyst for a B2B commerce platform. Using the following sales representative's performance data, generate 3 concise, actionable sections:
     1. Key Observations: 2-3 clear insights about their current sales performance, including trends in their top-selling items and customer retention patterns.
     2. Predictive Forecast: A 90-day sales projection based on their historical order volume, with specific assumptions about retention and growth.
     3. Strategic Recommendations: 2-3 actionable steps to increase their revenue, including cross-sell opportunities with their existing customer base and outreach priorities.
     Performance Data to analyze: {{sales_rep_full_data_set}}
     Keep all content specific to this representative, avoid generic advice, and limit the total output to 300 words or less.
     ```
   - Surface the generated OpenAI insights on the sales representative’s dashboard, with an option to refresh predictions with updated data.

#### Phase 4: Testing & Validation
1. Execute end-to-end testing to verify:
   - The `Consultants` model and migration run without errors on all staging and production environments, with all foreign key constraints enforced.
   - Sales representatives can only access their own assigned customer and order data, with no cross-account data leaks.
   - All stat card metrics calculate accurately, and top-seller rankings update in real-time as new orders are added.
   - The OpenAI integration successfully generates relevant, actionable insights without formatting errors, with prompt logging for future optimization.
2. Validate that all historical Sales Order records are correctly synced to the new `Consultants` table with zero data loss during deployment.


### Enhanced Actionable Requirements for Customers Table Enhancement & Customer Analytics Feature Implementation

#### 1. Core Customers Table Column Modification & Merge Strategy
- **Review existing Customers Table schema and merge the following logical, non-redundant columns to support full customer data tracking, eliminating duplicate or conflicting fields**:
  Mandatory final columns: Customer ID (primary key), Customer Name, Customer Class, Currency ID, Payment Terms, Customer Status, Price Class ID, Price Class Name, Route Code, Route Name, Credit Limit, Rep Code, Sales Rep, Zone ID, Customer Zone, Contact Email, Tax Registration ID, Statement Cycle, Preferred Delivery Terms
  *Rationale: Standardize ambiguous original column labels (e.g., "Tax" → "Tax Registration ID", "Delivery" → "Preferred Delivery Terms") to align with cross-system data consistency requirements.*
- Ensure all added columns enforce data validation rules (e.g., valid foreign key constraints for Currency ID, Price Class ID, Zone ID; mandatory non-null constraint for Customer ID and Customer Name) to prevent invalid data entry.

#### 2. Salesperson-Customer Matching Logic Implementation
- Map the SO (Sales Order) field `SalespersonID` (example value: "P505") to the customers table’s consultant/account manager record to link assigned sales reps to their respective customer accounts. Implement a foreign key relationship to ensure only valid, active `SalespersonID` values can be assigned to customers.

#### 3. Dynamic Customer Details Panel Feature
Build a dynamic, context-aware customer details panel that triggers when a user selects a customer from the table, with conditional rendering rules:
  - If the selected customer has associated branch entities: Fetch and display all linked branch records, including core branch operational metrics.
  - If the selected customer has no linked branches: Render the following aggregated customer lifetime and sales performance metrics:
    1. Customer Lifetime Value (LTV)
    2. Total count of historical Sales Orders (SO)
    3. Total historical Revenue from Contracts (RC)
    4. Date of the customer’s most recent SO (last purchase date)
    5. Average order value (AOV) calculated as total historical revenue divided by total number of SOs.

#### 4. Product Portfolio Penetration Analytics
Add a product range uniqueness metric for all selected customers that quantifies their purchase breadth against the full company product portfolio:
- Calculate the ratio of unique distinct product categories the customer has purchased across all SOs relative to the total available company product portfolio.
- Render a clear visual indicator (e.g., "This customer purchases 1 of 300 total product categories, with recurring purchases limited to toilet paper across all orders") to highlight narrow purchasing patterns.

#### 5. Cross-Sell/Upsell Opportunity Recommendation Engine
Implement a rule-based recommendation system tied to customer class values, that triggers contextually relevant product suggestions whenever a customer’s purchase patterns indicate unmet product needs:
  - If `CustomerClass` equals "CSKEY" (Modern Trade account): Recommend expanded product ranges suitable for large-format retail clients.
  - If `CustomerClass` starts with the "CS" prefix (all Consumer Sales accounts): Recommend all relevant products within the Consumer Sales product line that the customer has not yet purchased.
  - If `CustomerClass` starts with the "KP" prefix: Recommend all relevant products within the KP product category that align with the customer’s industry.
  - If `CustomerClass` equals "KPREST" (restaurant accounts): If the customer only purchases narrow-range goods (e.g., toilet paper, interleaved paper products), recommend compatible foodservice products such as serviettes to expand their order basket.

#### 6. Testing & Validation Requirements
- Conduct end-to-end schema testing to confirm all new table columns function as intended, with no data integrity errors.
- Validate salesperson matching logic to confirm 100% of active SO `SalespersonID` values correctly link to their assigned customers.
- Verify conditional details panel rendering for both branch-linked and non-branch-linked customers, with all aggregated metrics calculating accurately.
- Test the recommendation engine to confirm all customer class rules trigger the correct product suggestions, including edge cases for narrow purchase patterns.
- Validate product range uniqueness calculations to ensure they accurately reflect each customer’s purchasing breadth relative to the full company portfolio.
SO (sales Orde)

{
  "id": "d03ee20b-b376-f111-bb10-06a431d3f245",
  "rowNumber": 1,
  "note": {
    "value": ""
  },
  "Approved": {
    "value": true
  },
  "BaseCurrencyID": {
    "value": "KES"
  },
  "BillToAddressOverride": {
    "value": false
  },
  "BillToContactOverride": {
    "value": false
  },
  "CashAccount": {
    "value": "10170"
  },
  "ContactID": {
    "value": "132067"
  },
  "ControlTotal": {
    "value": 17153.18
  },
  "CreatedDate": {
    "value": "2026-07-03T07:45:54.64+00:00"
  },
  "CreditHold": {
    "value": false
  },
  "CurrencyID": {
    "value": "KES"
  },
  "CurrencyRate": {
    "value": 1
  },
  "CurrencyRateTypeID": {
    "value": "SPOT"
  },
  "CustomerID": {
    "value": "CUST103249"
  },
  "CustomerOrder": {
    "value": "PO : 26016609"
  },
  "CustomerPin": [],
  "Date": {
    "value": "2026-07-03T00:00:00+00:00"
  },
  "Description": [],
  "DestinationWarehouseID": [],
  "Details": [
    {
      "id": "6404a736-b376-f111-bb10-06a431d3f245",
      "rowNumber": 1,
      "note": {
        "value": ""
      },
      "Account": {
        "value": "40120"
      },
      "AlternateID": [],
      "Amount": {
        "value": 1399.3
      },
      "AutoCreateIssue": {
        "value": false
      },
      "AverageCost": {
        "value": 1065.2774
      },
      "Branch": {
        "value": "HEADOFFICE"
      },
      "CalculateDiscountsOnImport": [],
      "Commissionable": {
        "value": false
      },
      "Completed": {
        "value": true
      },
      "CustomerOrderNbr": [],
      "DiscountAmount": {
        "value": 466.43
      },
      "DiscountCode": {
        "value": "MAJID"
      },
      "DiscountedUnitPrice": {
        "value": 1399.2985
      },
      "DiscountPercent": {
        "value": 25
      },
      "ExtendedPrice": {
        "value": 1865.73
      },
      "ExternalRef": [],
      "FreeItem": {
        "value": false
      },
      "InventoryID": {
        "value": "FAYWP0024"
      },
      "InvoiceLineNbr": [],
      "InvoiceNbr": [],
      "InvoiceType": [],
      "LastModifiedDate": {
        "value": "07/04/2026 00:09:02"
      },
      "LineDescription": {
        "value": "Fay Antibacterial Wet Wipes 72s  + 12s Promo  KF"
      },
      "LineNbr": {
        "value": 1
      },
      "LineType": {
        "value": "Goods for Inventory"
      },
      "Location": [],
      "LotSerialNbr": [],
      "ManualDiscount": {
        "value": false
      },
      "ManualPrice": {
        "value": false
      },
      "MarkForPO": {
        "value": false
      },
      "NoteID": {
        "value": "6404a736-b376-f111-bb10-06a431d3f245"
      },
      "OpenQty": {
        "value": 0
      },
      "Operation": {
        "value": "Issue"
      },
      "OrderQty": {
        "value": 1
      },
      "OvershipThreshold": {
        "value": 100
      },
      "POSource": [],
      "PurchaseWarehouse": [],
      "QtyOnShipments": {
        "value": 1
      },
      "ReasonCode": [],
      "RequestedOn": {
        "value": "2026-07-03T00:00:00+00:00"
      },
      "SalespersonID": {
        "value": "P076"
      },
      "SchedOrderDate": {
        "value": "2026-07-03T00:00:00+00:00"
      },
      "ShipOn": {
        "value": "2026-07-03T00:00:00+00:00"
      },
      "ShippingRule": {
        "value": "Cancel Remainder"
      },
      "ShipToLocation": {
        "value": "MAIN"
      },
      "TaxCategory": {
        "value": "TAXABLE"
      },
      "TaxZone": {
        "value": "VAT"
      },
      "UnbilledAmount": {
        "value": 0
      },
      "UndershipThreshold": {
        "value": 100
      },
      "UnitCost": {
        "value": 1065.27725
      },
      "UnitPrice": {
        "value": 1865.73134
      },
      "UOM": {
        "value": "CASE"
      },
      "VendorID": [],
      "WarehouseID": {
        "value": "FGS"
      },
      "custom": [],
      "_links": {
        "files:put": "/entity/IpayV2/22.200.001/files/PX.Objects.SO.SOOrderEntry/Transactions/6404a736-b376-f111-bb10-06a431d3f245/{filename}"
      }
    },
    {
      "id": "7745c13c-b376-f111-bb10-06a431d3f245",
      "rowNumber": 2,
      "note": {
        "value": ""
      },
      "Account": {
        "value": "40210"
      },
      "AlternateID": [],
      "Amount": {
        "value": 3862.07
      },
      "AutoCreateIssue": {
        "value": false
      },
      "AverageCost": {
        "value": 276.885
      },
      "Branch": {
        "value": "HEADOFFICE"
      },
      "CalculateDiscountsOnImport": [],
      "Commissionable": {
        "value": false
      },
      "Completed": {
        "value": true
      },
      "CustomerOrderNbr": [],
      "DiscountAmount": {
        "value": 0
      },
      "DiscountCode": [],
      "DiscountedUnitPrice": {
        "value": 482.75862
      },
      "DiscountPercent": {
        "value": 0
      },
      "ExtendedPrice": {
        "value": 3862.07
      },
      "ExternalRef": [],
      "FreeItem": {
        "value": false
      },
      "InventoryID": {
        "value": "HOBBW0053"
      },
      "InvoiceLineNbr": [],
      "InvoiceNbr": [],
      "InvoiceType": [],
      "LastModifiedDate": {
        "value": "07/04/2026 00:09:02"
      },
      "LineDescription": {
        "value": "Hobby Body Wash Marshmallow Coconut 1000Ml + Hobby Body wash 300ml"
      },
      "LineNbr": {
        "value": 3
      },
      "LineType": {
        "value": "Goods for Inventory"
      },
      "Location": [],
      "LotSerialNbr": [],
      "ManualDiscount": {
        "value": false
      },
      "ManualPrice": {
        "value": false
      },
      "MarkForPO": {
        "value": false
      },
      "NoteID": {
        "value": "7745c13c-b376-f111-bb10-06a431d3f245"
      },
      "OpenQty": {
        "value": 0
      },
      "Operation": {
        "value": "Issue"
      },
      "OrderQty": {
        "value": 8
      },
      "OvershipThreshold": {
        "value": 100
      },
      "POSource": [],
      "PurchaseWarehouse": [],
      "QtyOnShipments": {
        "value": 8
      },
      "ReasonCode": [],
      "RequestedOn": {
        "value": "2026-07-03T00:00:00+00:00"
      },
      "SalespersonID": {
        "value": "P076"
      },
      "SchedOrderDate": {
        "value": "2026-07-03T00:00:00+00:00"
      },
      "ShipOn": {
        "value": "2026-07-03T00:00:00+00:00"
      },
      "ShippingRule": {
        "value": "Cancel Remainder"
      },
      "ShipToLocation": {
        "value": "MAIN"
      },
      "TaxCategory": {
        "value": "TAXABLE"
      },
      "TaxZone": {
        "value": "VAT"
      },
      "UnbilledAmount": {
        "value": 0
      },
      "UndershipThreshold": {
        "value": 100
      },
      "UnitCost": {
        "value": 304.8113
      },
      "UnitPrice": {
        "value": 482.75862
      },
      "UOM": {
        "value": "PIECE"
      },
      "VendorID": [],
      "WarehouseID": {
        "value": "FGS"
      },
      "custom": [],
      "_links": {
        "files:put": "/entity/IpayV2/22.200.001/files/PX.Objects.SO.SOOrderEntry/Transactions/7745c13c-b376-f111-bb10-06a431d3f245/{filename}"
      }
    },
    {
      "id": "c568ef48-b376-f111-bb10-06a431d3f245",
      "rowNumber": 3,
      "note": {
        "value": ""
      },
      "Account": {
        "value": "40210"
      },
      "AlternateID": [],
      "Amount": {
        "value": 5793.1
      },
      "AutoCreateIssue": {
        "value": false
      },
      "AverageCost": {
        "value": 315.60662
      },
      "Branch": {
        "value": "HEADOFFICE"
      },
      "CalculateDiscountsOnImport": [],
      "Commissionable": {
        "value": false
      },
      "Completed": {
        "value": true
      },
      "CustomerOrderNbr": [],
      "DiscountAmount": {
        "value": 0
      },
      "DiscountCode": [],
      "DiscountedUnitPrice": {
        "value": 482.75862
      },
      "DiscountPercent": {
        "value": 0
      },
      "ExtendedPrice": {
        "value": 5793.1
      },
      "ExternalRef": [],
      "FreeItem": {
        "value": false
      },
      "InventoryID": {
        "value": "HOBBW0055"
      },
      "InvoiceLineNbr": [],
      "InvoiceNbr": [],
      "InvoiceType": [],
      "LastModifiedDate": {
        "value": "07/04/2026 00:09:02"
      },
      "LineDescription": {
        "value": "Hobby Body wash Pomegranate Blossom 1000ml + Hobby Body wash 300ml"
      },
      "LineNbr": {
        "value": 5
      },
      "LineType": {
        "value": "Goods for Inventory"
      },
      "Location": [],
      "LotSerialNbr": [],
      "ManualDiscount": {
        "value": false
      },
      "ManualPrice": {
        "value": false
      },
      "MarkForPO": {
        "value": false
      },
      "NoteID": {
        "value": "c568ef48-b376-f111-bb10-06a431d3f245"
      },
      "OpenQty": {
        "value": 0
      },
      "Operation": {
        "value": "Issue"
      },
      "OrderQty": {
        "value": 12
      },
      "OvershipThreshold": {
        "value": 100
      },
      "POSource": [],
      "PurchaseWarehouse": [],
      "QtyOnShipments": {
        "value": 12
      },
      "ReasonCode": [],
      "RequestedOn": {
        "value": "2026-07-03T00:00:00+00:00"
      },
      "SalespersonID": {
        "value": "P076"
      },
      "SchedOrderDate": {
        "value": "2026-07-03T00:00:00+00:00"
      },
      "ShipOn": {
        "value": "2026-07-03T00:00:00+00:00"
      },
      "ShippingRule": {
        "value": "Cancel Remainder"
      },
      "ShipToLocation": {
        "value": "MAIN"
      },
      "TaxCategory": {
        "value": "TAXABLE"
      },
      "TaxZone": {
        "value": "VAT"
      },
      "UnbilledAmount": {
        "value": 0
      },
      "UndershipThreshold": {
        "value": 100
      },
      "UnitCost": {
        "value": 315.60665
      },
      "UnitPrice": {
        "value": 482.75862
      },
      "UOM": {
        "value": "PIECE"
      },
      "VendorID": [],
      "WarehouseID": {
        "value": "FGS"
      },
      "custom": [],
      "_links": {
        "files:put": "/entity/IpayV2/22.200.001/files/PX.Objects.SO.SOOrderEntry/Transactions/c568ef48-b376-f111-bb10-06a431d3f245/{filename}"
      }
    },
    {
      "id": "abb71a55-b376-f111-bb10-06a431d3f245",
      "rowNumber": 4,
      "note": {
        "value": ""
      },
      "Account": {
        "value": "40000"
      },
      "AlternateID": [],
      "Amount": {
        "value": 3732.75
      },
      "AutoCreateIssue": {
        "value": false
      },
      "AverageCost": {
        "value": 1206.23513
      },
      "Branch": {
        "value": "HEADOFFICE"
      },
      "CalculateDiscountsOnImport": [],
      "Commissionable": {
        "value": false
      },
      "Completed": {
        "value": true
      },
      "CustomerOrderNbr": [],
      "DiscountAmount": {
        "value": 933.19
      },
      "DiscountCode": [],
      "DiscountedUnitPrice": {
        "value": 1866.376
      },
      "DiscountPercent": {
        "value": 20
      },
      "ExtendedPrice": {
        "value": 4665.94
      },
      "ExternalRef": [],
      "FreeItem": {
        "value": false
      },
      "InventoryID": {
        "value": "FAYTP0031"
      },
      "InvoiceLineNbr": [],
      "InvoiceNbr": [],
      "InvoiceType": [],
      "LastModifiedDate": {
        "value": "07/04/2026 00:08:23"
      },
      "LineDescription": {
        "value": "Fay TP Emb. Unwrap. 4x15s White"
      },
      "LineNbr": {
        "value": 7
      },
      "LineType": {
        "value": "Goods for Inventory"
      },
      "Location": [],
      "LotSerialNbr": [],
      "ManualDiscount": {
        "value": true
      },
      "ManualPrice": {
        "value": false
      },
      "MarkForPO": {
        "value": false
      },
      "NoteID": {
        "value": "abb71a55-b376-f111-bb10-06a431d3f245"
      },
      "OpenQty": {
        "value": 0
      },
      "Operation": {
        "value": "Issue"
      },
      "OrderQty": {
        "value": 2
      },
      "OvershipThreshold": {
        "value": 100
      },
      "POSource": [],
      "PurchaseWarehouse": [],
      "QtyOnShipments": {
        "value": 0
      },
      "ReasonCode": [],
      "RequestedOn": {
        "value": "2026-07-03T00:00:00+00:00"
      },
      "SalespersonID": {
        "value": "P076"
      },
      "SchedOrderDate": {
        "value": "2026-07-03T00:00:00+00:00"
      },
      "ShipOn": {
        "value": "2026-07-03T00:00:00+00:00"
      },
      "ShippingRule": {
        "value": "Cancel Remainder"
      },
      "ShipToLocation": {
        "value": "MAIN"
      },
      "TaxCategory": {
        "value": "TAXABLE"
      },
      "TaxZone": {
        "value": "VAT"
      },
      "UnbilledAmount": {
        "value": 0
      },
      "UndershipThreshold": {
        "value": 100
      },
      "UnitCost": {
        "value": 1204.98825
      },
      "UnitPrice": {
        "value": 2332.97
      },
      "UOM": {
        "value": "CASE"
      },
      "VendorID": [],
      "WarehouseID": {
        "value": "FGS"
      },
      "custom": [],
      "_links": {
        "files:put": "/entity/IpayV2/22.200.001/files/PX.Objects.SO.SOOrderEntry/Transactions/abb71a55-b376-f111-bb10-06a431d3f245/{filename}"
      }
    }
  ],
  "DisableAutomaticDiscountUpdate": {
    "value": false
  },
  "DisableAutomaticTaxCalculation": {
    "value": false
  },
  "EffectiveDate": {
    "value": "2020-08-04T00:00:00+00:00"
  },
  "ExternalOrderOrigin": [],
  "ExternalOrderOriginal": [],
  "ExternalOrderSource": [],
  "ExternalRef": [],
  "ExternalRefundRef": [],
  "Hold": {
    "value": false
  },
  "IsPromotional": {
    "value": false
  },
  "IsTaxValid": [],
  "LastModified": {
    "value": "2026-07-04T00:12:03.59+00:00"
  },
  "LocationID": {
    "value": "MAIN"
  },
  "LPODate": {
    "value": "2026-07-02"
  },
  "LPOExpiryDateandTime": {
    "value": "2026-07-03T21:00:00+00:00"
  },
  "MainOrder": [],
  "NoteID": {
    "value": "d03ee20b-b376-f111-bb10-06a431d3f245"
  },
  "OrderedQty": {
    "value": 23
  },
  "OrderNbr": {
    "value": "SO361688"
  },
  "OrderTotal": {
    "value": 17153.18
  },
  "OrderType": {
    "value": "SO"
  },
  "PaymentMethod": {
    "value": "CHECK"
  },
  "PaymentRef": [],
  "POSOrderNbr": [],
  "PreferredWarehouseID": [],
  "ReciprocalRate": {
    "value": 1
  },
  "RejectedReasons": [],
  "RequestedOn": {
    "value": "2026-07-03T00:00:00+00:00"
  },
  "ShipToAddressOverride": {
    "value": false
  },
  "ShipToContactOverride": {
    "value": false
  },
  "ShipVia": [],
  "Status": {
    "value": "Completed"
  },
  "TaxCalcMode": {
    "value": "Tax Settings"
  },
  "TaxTotal": {
    "value": 2365.96
  },
  "VATExemptTotal": {
    "value": 0
  },
  "VATTaxableTotal": {
    "value": 14787.22
  },
  "WillCall": {
    "value": true
  },
  "custom": [],
  "_links": {
    "self": "/entity/IpayV2/22.200.001/SalesOrder/d03ee20b-b376-f111-bb10-06a431d3f245",
    "files:put": "/entity/IpayV2/22.200.001/files/PX.Objects.SO.SOOrderEntry/Document/d03ee20b-b376-f111-bb10-06a431d3f245/{filename}"
  }
}






CUST

{
  "id": "8d23fcc1-4789-f011-842a-06ece84e3e57",
  "rowNumber": 1,
  "note": {
    "value": ""
  },
  "AccountRef": [],
  "ApplyOverdueCharges": {
    "value": false
  },
  "AutoApplyPayments": {
    "value": false
  },
  "BAccountID": {
    "value": 15783
  },
  "BillingAddressOverride": {
    "value": false
  },
  "BillingContactOverride": {
    "value": false
  },
  "CreatedDateTime": {
    "value": "2025-09-04T04:33:43.497+00:00"
  },
  "CreditLimit": {
    "value": 60000000
  },
  "CurrencyID": {
    "value": "KES"
  },
  "CurrencyRateType": {
    "value": "SPOT"
  },
  "CustomerClass": {
    "value": "CSKEY"
  },
  "CustomerID": {
    "value": "CUST103249"
  },
  "CustomerName": {
    "value": "Majid Al Futtaim Hypermarkets Ltd- Warris Mall"
  },
  "EnableCurrencyOverride": {
    "value": false
  },
  "EnableRateOverride": {
    "value": true
  },
  "EnableWriteOffs": {
    "value": true
  },
  "FOBPoint": {
    "value": "5T"
  },
  "IsGuestCustomer": {
    "value": false
  },
  "LastModifiedDateTime": {
    "value": "2026-04-23T05:03:59.25+00:00"
  },
  "LeadTimedays": [],
  "LocationName": {
    "value": "Primary Location"
  },
  "MultiCurrencyStatements": {
    "value": false
  },
  "NoteID": {
    "value": "8d23fcc1-4789-f011-842a-06ece84e3e57"
  },
  "OrderPriority": {
    "value": 0
  },
  "ParentRecord": {
    "value": "CUST100584"
  },
  "PriceClassID": {
    "value": "MAJIDALFLU"
  },
  "PrintDunningLetters": {
    "value": false
  },
  "PrintInvoices": {
    "value": true
  },
  "PrintStatements": {
    "value": true
  },
  "ResidentialDelivery": {
    "value": false
  },
  "RestrictVisibilityTo": [],
  "SaturdayDelivery": {
    "value": false
  },
  "SendDunningLettersbyEmail": {
    "value": false
  },
  "SendInvoicesbyEmail": {
    "value": false
  },
  "SendStatementsbyEmail": {
    "value": false
  },
  "ShippingAddressOverride": [],
  "ShippingBranch": [],
  "ShippingContactOverride": [],
  "ShippingRule": {
    "value": "Cancel Remainder"
  },
  "ShippingTerms": [],
  "ShippingZoneID": {
    "value": "Z005"
  },
  "ShipVia": [],
  "StatementCycleID": {
    "value": "MONTHLY"
  },
  "StatementType": {
    "value": "Open Item"
  },
  "Status": {
    "value": "Active"
  },
  "TaxRegistrationID": {
    "value": "P051522497N"
  },
  "TaxZone": {
    "value": "VAT"
  },
  "Terms": {
    "value": "30DS"
  },
  "WarehouseID": [],
  "WriteOffLimit": {
    "value": 20
  },
  "custom": [],
  "_links": {
    "self": "/entity/IpayV2/22.200.001/Customer/8d23fcc1-4789-f011-842a-06ece84e3e57",
    "files:put": "/entity/IpayV2/22.200.001/files/PX.Objects.AR.CustomerMaint/BAccount/8d23fcc1-4789-f011-842a-06ece84e3e57/{filename}"
  }
}