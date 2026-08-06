Build a polished, responsive executive web application called “Kim-Fay Intelligence” with three connected dashboards:

1. Production Intelligence
2. Partner Intelligence
3. Sales Intelligence

Use dummy frontend data only for the first version. Do not connect to a backend or API yet. However, structure the application so it can later connect cleanly to a Laravel API without redesigning the UI, data models, components, or business logic.

Use the supplied screenshots as the main visual reference for layout, spacing, card design, tables, filters, charts, and Kim-Fay blue branding.

The application must feel:

- Executive
- Clean
- Modern
- Easy to scan
- Operationally useful
- Data-rich without being crowded
- Suitable for desktop screens and meeting-room displays
- Responsive on tablets and smaller screens

==================================================
1. TECH STACK
==================================================

Use:

- React
- TypeScript
- Vite
- TanStack Router
- TanStack Table
- TanStack Query
- Tailwind CSS
- shadcn/ui
- Recharts
- Lucide React icons

Use reusable components and avoid duplicating logic between dashboards.

Suggested routes:

- /intelligence/production
- /intelligence/partners
- /intelligence/sales

Suggested project structure:

src/
├── components/
│   ├── dashboard/
│   │   ├── DashboardHeader.tsx
│   │   ├── DashboardFilters.tsx
│   │   ├── KpiCard.tsx
│   │   ├── StatusBadge.tsx
│   │   ├── ProductTable.tsx
│   │   ├── WarehouseBreakdown.tsx
│   │   ├── TrendChart.tsx
│   │   ├── SalesTrendTable.tsx
│   │   ├── MultiSelect.tsx
│   │   ├── ProductDetailPanel.tsx
│   │   ├── CategoryDrilldown.tsx
│   │   └── InsightCard.tsx
│   └── ui/
├── data/
│   ├── manufactured.ts
│   ├── partners.ts
│   ├── sales.ts
│   ├── machines.ts
│   ├── warehouses.ts
│   ├── categories.ts
│   └── channels.ts
├── pages/
│   ├── ProductionIntelligence.tsx
│   ├── PartnerIntelligence.tsx
│   └── SalesIntelligence.tsx
├── services/
│   ├── inventory.service.ts
│   └── sales.service.ts
├── types/
│   ├── inventory.ts
│   ├── sales.ts
│   └── filters.ts
└── utils/
    ├── calculations.ts
    ├── status.ts
    ├── filters.ts
    └── insights.ts

==================================================
2. BRANDING AND UI DIRECTION
==================================================

Use Kim-Fay blue as the primary interface colour.

Suggested colours:

- Primary dark blue: #082B72
- Primary blue: #0057D9
- Light blue: #EAF3FF
- Healthy green: #15803D
- At-risk amber: #F59E0B
- Critical red: #DC2626
- Page background: #F8FAFC
- Card background: #FFFFFF
- Border: #E2E8F0
- Primary text: #0F172A
- Secondary text: #64748B

Use:

- White cards
- Subtle shadows
- Rounded corners
- Clear spacing
- Strong visual hierarchy
- Compact tables
- Sticky table headers
- Clear hover and selected states
- Responsive layouts
- Minimal gradients
- Low visual noise
- Strong Kim-Fay blue identity

==================================================
3. GLOBAL NAVIGATION
==================================================

Create navigation for:

- Production Intelligence
- Partner Intelligence
- Sales Intelligence

The active module must be clearly highlighted.

The global header should include:

- Kim-Fay logo placeholder
- Current module title
- Last updated date and time
- Refresh button
- Export placeholder
- Full-screen control
- User avatar and role
- Reset Filters action

Use dummy text such as:

Last updated: 29 July 2026, 7:30 PM

==================================================
4. BUSINESS DEFINITIONS
==================================================

Kim-Fay operates with two brand ownership types.

Manufactured brands:

- Cosy
- Cosy Poa
- Fay
- Kleenex
- Sifa
- Tishu Poa
- Ultra Clean
- Kimfay

Partner or trading brands:

- Airoma
- Aptamil
- Bio Oil
- Cow & Gate
- Dabur
- Dermoviva
- Dove
- Duracell
- Fem
- Hobby
- Huggies
- Kotex
- Lux
- Miswak
- ORS
- Rexona
- Vatika

Brand ownership type:

type BrandOwnership = "manufactured" | "partner";

Business lines:

- Consumer Sales
- Kim-Fay Professional

Both business lines must be multi-select filters where applicable.

By default, both should be selected.

All sales values represent units or volume, not revenue.

Do not display KES, USD, dollar signs, or any currency symbol anywhere in the sales dashboards.

==================================================
5. SHARED DATA MODELS
==================================================

Use data models similar to:

interface InventoryItem {
  inventoryId: string;
  productName: string;
  brand: string;
  category: string;
  brandOwnership: "manufactured" | "partner";
  businessLine: "Consumer Sales" | "Kim-Fay Professional";
  site?: "HQ" | "Tatu";
  machine?: string;
  warehouseStocks: WarehouseStock[];
  safetyStock: number;
  bufferStock: number;
  msi: number;
  exportRequirement?: number;
  msiExport?: number;
  monthlySales: MonthlySales[];
  replenishmentEvents?: ReplenishmentEvent[];
}

interface WarehouseStock {
  warehouseId: string;
  warehouseName: string;
  site: "HQ" | "Tatu";
  qtyOnHand: number;
  qtyAllocated: number;
  qtyAvailable: number;
}

interface MonthlySales {
  month: string;
  year: number;
  quantity: number;
}

interface ReplenishmentEvent {
  date: string;
  quantity: number;
  eventType: "production" | "replenishment" | "adjustment";
}

interface SalesRecord {
  salesId: string;
  inventoryId: string;
  productName: string;
  brand: string;
  category: string;
  brandOwnership: "manufactured" | "partner";
  businessLine: "Consumer Sales" | "Kim-Fay Professional";
  channel: string;
  warehouseId: string;
  month: string;
  year: number;
  quantity: number;
}

The main table’s available quantity must always equal the sum of qtyAvailable across the currently selected warehouses.

Do not hard-code totals separately from warehouse records.

==================================================
6. INVENTORY STATUS LOGIC
==================================================

Status must be measured against MSI.

Use:

Critical:
Qty Available is below 50% of MSI.

At Risk:
Qty Available is at least 50% of MSI but below MSI.

Healthy:
Qty Available is equal to or above MSI.

Create a reusable function:

function calculateInventoryStatus(
  qtyAvailable: number,
  msi: number
): "critical" | "at-risk" | "healthy";

Handle zero MSI values safely.

Use:

- Red for Critical
- Amber for At Risk
- Green for Healthy

Show the status rules in a small legend:

- Critical: Stock is below 50% of MSI
- At Risk: Stock is between 50% and 99% of MSI
- Healthy: Stock is at or above MSI

==================================================
7. RUN-RATE AND STOCK-COVERAGE LOGIC
==================================================

Use the latest three complete months of sales.

Three-Month Average Run Rate:

Sum of the previous three complete months’ sales divided by 3.

Months of Cover:

Qty Available divided by Three-Month Average Run Rate.

Days of Cover:

Months of Cover multiplied by 30.

Coverage status:

Critical:
Less than 1 month of cover.

At Risk:
At least 1 month but less than 2 months.

Healthy:
2 months or more.

Show separately:

- MSI Status
- Coverage Status
- Final Planning Status

The Planning Status should use the more severe of MSI Status and Coverage Status.

Example:

- MSI Status: Healthy
- Coverage Status: At Risk
- Planning Status: At Risk

==================================================
8. PRODUCT CATEGORY
==================================================

Add Product Category as a core field and filter across all three dashboards:

- Production Intelligence
- Partner Intelligence
- Sales Intelligence

The Product Category filter must:

- Be searchable
- Support multi-select
- Default to all valid categories selected
- Show removable chips
- Collapse more than two selected categories into +N
- Include Select All
- Include Clear All
- Include search inside the dropdown
- Support keyboard navigation
- Persist in localStorage
- Update dynamically based on selected brand ownership, brands, and business line
- Automatically remove invalid selected categories after another filter changes

Every dummy SKU must have exactly one valid category.

==================================================
9. MANUFACTURED PRODUCT CATEGORIES
==================================================

Use categories such as:

- Toilet Paper
- Kitchen Towels
- Hand Towels
- Facial Tissues
- Serviettes
- Wet Wipes
- Cling Film
- Aluminium Foil
- Baking Paper
- Sanitizers
- Hand Wash
- Scouring Pads
- Professional Hygiene
- Away-from-Home Tissue
- Household Cleaning

Example mappings:

- Fay Advanced Multifold Hand Towels 12 x 240 Sheets → Hand Towels
- Fay Kitchen Towels 2 Rolls → Kitchen Towels
- Fay Eco Kitchen Towels → Kitchen Towels
- Fay Water Wipes 56s → Wet Wipes
- Fay Sensitive Wipes 56s → Wet Wipes
- Fay Everyday Wipes 56s → Wet Wipes
- Fay Antibacterial Wipes 56s → Wet Wipes
- Cosy Poa Toilet Paper 4 x 10s White → Toilet Paper
- Cosy Serviettes 18 x 100 Sheets → Serviettes
- Sifa Facial Tissues 100s → Facial Tissues
- Ultra Clean Scouring Pads → Scouring Pads
- Kleenex Facial Tissues 100s → Facial Tissues

==================================================
10. PARTNER PRODUCT CATEGORIES
==================================================

Use categories such as:

- Shower Gels
- Body Wash
- Bar Soaps
- Body Lotions
- Deodorant Sprays
- Roll-Ons
- Hair Removal
- Shampoos
- Conditioners
- Hair Care
- Braid Sprays
- Baby Wipes
- Baby Diapers
- Feminine Care
- Skincare Oils
- Batteries
- Coin Cells
- Toothpaste
- Oral Care
- Baby Nutrition
- Air Care

Example mappings:

- Dove Body Wash 400ml → Body Wash
- Dove Men+Care Body Wash 400ml → Body Wash
- Dove Shower Gel → Shower Gels
- Dove Roll-On 50ml → Roll-Ons
- Rexona Deodorant Spray → Deodorant Sprays
- Lux Bar Soap → Bar Soaps
- Hobby Body Lotion 400ml → Body Lotions
- Fem Hair Removal Cream → Hair Removal
- Vatika Shampoo 400ml → Shampoos
- Vatika Conditioner 400ml → Conditioners
- ORS Olive Oil Braid Spray 236ml → Braid Sprays
- Huggies Pure Wipes 56s → Baby Wipes
- Huggies Dry Pants Large → Baby Diapers
- Kotex Ultra Thin Pads 16s → Feminine Care
- Bio-Oil Skincare Oil 125ml → Skincare Oils
- Duracell AA Alkaline 4 Pack → Batteries
- Duracell CR2032 → Coin Cells
- Miswak Toothpaste → Toothpaste
- Airoma Air Freshener → Air Care
- Aptamil Infant Formula → Baby Nutrition
- Cow & Gate Infant Formula → Baby Nutrition

==================================================
11. PRODUCTION INTELLIGENCE
==================================================

Title:

Production Intelligence

Subtitle:

Manufactured inventory, production pressure and sales run-rate visibility

This dashboard is only for Kim-Fay manufactured brands.

TOP FILTERS

Display in this order:

1. Site — multi-select

Default selected:

- HQ
- Tatu

2. Business View — segmented control

Options:

- Production
- Sales

Default:

- Production

3. Business Line — multi-select

Default selected:

- Consumer Sales
- Kim-Fay Professional

4. Brand — multi-select

Default:

All manufactured brands selected.

Options:

- Cosy
- Cosy Poa
- Fay
- Kleenex
- Sifa
- Tishu Poa
- Ultra Clean
- Kimfay

5. Product Category — multi-select

Default:

All valid manufactured categories selected.

6. Machine — multi-select

Default:

All Machines

7. Warehouse — multi-select

Default:

All Warehouses

8. Status — multi-select

Default:

- Healthy
- At Risk
- Critical

9. Search

Search by:

- Inventory ID
- Product
- Brand
- Product Category

==================================================
12. PRODUCTION SITE AND MACHINE MAPPING
==================================================

Tatu machines:

- 4 DECK
- New TP Continuous
- OLD TP
- PERINI

HQ machines:

- 2 DECK
- 4 DECK
- Box Packing
- COCKTAIL
- CONTINUOUS SEALING
- DINNER
- MANUAL
- NEW HANDTOWEL M/C
- NEW FOIL
- New TP Continuous
- New TP Start Stop
- OLD FOIL
- OLD TP
- PERINI
- POCKET PACK
- PRINTING M/C
- ROTARY
- Sanitizer Line
- SCOURING PAD M/C
- START STOP
- V Fold
- Wipes One
- Wipes Two

Machine filter behaviour:

- Selecting only HQ displays HQ machines.
- Selecting only Tatu displays Tatu machines.
- Selecting both displays the union of both lists.
- If a selected machine becomes invalid after changing the site, automatically remove it.
- All Machines should represent every currently valid machine.
- Support multiple selections.
- Show selections as removable chips.
- Collapse excess selections into +N.

==================================================
13. PRODUCTION WAREHOUSES
==================================================

Use realistic dummy warehouses.

HQ warehouses:

- HQ FGS
- HQ DTC
- HQ Export
- HQ FGS3
- HQ PFGS
- HQ Main Warehouse
- HQ Professional Warehouse

Tatu warehouses:

- Tatu FGS
- Tatu Main Warehouse
- Tatu Raw Materials
- Tatu Finished Goods
- Tatu Dispatch

Warehouse filter behaviour:

- Support multi-select.
- Selecting HQ shows HQ warehouses.
- Selecting Tatu shows Tatu warehouses.
- Selecting both shows all valid warehouses.
- Remove invalid selections automatically.
- Default to all valid warehouses.
- Show selected warehouses as chips.
- Collapse excess selections into +N.

The quantity shown in the main product table must be the cumulative available quantity across all selected warehouses.

Do not show machine or warehouse columns in the main product table.

==================================================
14. PRODUCTION KPI CARDS
==================================================

Display:

- Total SKUs
- Critical SKUs
- At-Risk SKUs
- Healthy SKUs
- Total Quantity Available
- Average Months of Cover
- SKUs Below MSI
- Production Requirement

Production Requirement:

Maximum of 0 and MSI minus Qty Available.

All KPI cards must respond to the selected filters.

==================================================
15. PRODUCTION TABLE
==================================================

Columns:

- Inventory ID
- Product
- Brand
- Product Category
- Qty Available — All Selected Warehouses
- Safety Stock
- Buffer Stock
- MSI
- Last 3 Months Sales
- Three-Month Average Run Rate
- Months of Cover
- MSI Status
- Coverage Status
- Planning Status
- Production Requirement

Important:

- Do not show Machine in the main table.
- Do not show Warehouse in the main table.
- Qty Available must be cumulative across selected warehouses.

Support:

- Sorting
- Pagination
- Search
- Status filtering
- Category filtering
- Column visibility
- Sticky header
- Row selection
- Responsive layout
- Export placeholder
- Compact and comfortable density controls

Make Inventory ID clickable or make the entire row selectable.

==================================================
16. PRODUCTION PRODUCT DRILL-DOWN
==================================================

When a row is selected, update a right-side detail panel on desktop and a drawer on tablet/mobile.

Show:

- Inventory ID
- Product
- Brand
- Product Category
- Business Line
- Site
- Assigned machine
- Safety Stock
- Buffer Stock
- MSI
- Total Available Stock
- Three-Month Average Run Rate
- Months of Cover
- Production Requirement
- MSI Status
- Coverage Status
- Planning Status

WAREHOUSE BREAKDOWN

Columns:

- Warehouse
- Site
- Qty on Hand
- Qty Allocated
- Qty Available
- Percentage of Total Available

The total must match the cumulative quantity shown in the main table.

DEMAND AND STOCK TREND

Add a dual-line chart for the last 12 months showing:

- Monthly sales volume
- Month-end available stock

Add automatic annotations for:

- Highest monthly sales
- Lowest monthly sales
- Lowest stock level
- Stockout
- Replenishment
- Unusual sales spike
- Unusual sales decline

Use labels such as:

- Sales spike
- Low stock
- Replenishment
- Stockout

THREE-MONTH SALES SUMMARY

Show:

- Month 1
- Month 2
- Month 3
- Three-month total
- Three-month average
- Percentage change from first month to latest month

==================================================
17. MSI EDITING
==================================================

Only these roles may edit MSI:

- Super Admin
- Production Manager
- COO

Other users are read-only.

Add a dummy role switcher with:

- Executive Viewer
- Super Admin
- Production Manager
- COO

When an authorised user edits MSI:

- Open a confirmation dialog.
- Show current MSI.
- Allow numeric input.
- Require a reason.
- Validate the input.
- Save in React state or localStorage.
- Recalculate statuses immediately.
- Record a dummy audit entry.

Audit fields:

- Changed By
- Previous MSI
- New MSI
- Reason
- Date and Time

Do not update MSI immediately on a single click without confirmation.

==================================================
18. PARTNER INTELLIGENCE
==================================================

Title:

Partner Intelligence

Subtitle:

Trading-brand inventory, warehouse availability and demand visibility

This dashboard should visually match Production Intelligence but must not display production machines or production requirements.

TOP FILTERS

Display in this order:

1. View — segmented control

Options:

- Stocks
- Sales

Default:

- Stocks

2. Business Line — multi-select

Default selected:

- Consumer Sales
- Kim-Fay Professional

3. Warehouse — multi-select

Default:

- All Warehouses

4. Select Brands — multi-select

Default:

All partner brands selected.

Options:

- Airoma
- Aptamil
- Bio Oil
- Cow & Gate
- Dabur
- Dermoviva
- Dove
- Duracell
- Fem
- Hobby
- Huggies
- Kotex
- Lux
- Miswak
- ORS
- Rexona
- Vatika

5. Product Category — multi-select

Default:

All valid partner categories selected.

6. Status — multi-select

Default:

- Healthy
- At Risk
- Critical

7. Search

Search by:

- Inventory ID
- Product
- Brand
- Product Category

==================================================
19. PARTNER KPI CARDS
==================================================

Display:

- Total SKUs
- Critical SKUs
- At-Risk SKUs
- Healthy SKUs
- Total Quantity Available
- Average Months of Cover
- Brands Selected
- SKUs Requiring Replenishment

Replenishment Requirement:

Maximum of 0 and MSI minus Qty Available.

Use the term Replenishment Requirement, not Production Requirement.

==================================================
20. PARTNER INVENTORY TABLE
==================================================

Columns:

- Inventory ID
- Product
- Brand
- Product Category
- Business Line
- Qty Available — All Selected Warehouses
- Safety Stock
- Buffer Stock
- MSI
- Last 3 Months Sales
- Three-Month Average Run Rate
- Months of Cover
- MSI Status
- Coverage Status
- Planning Status
- Replenishment Requirement

Do not include machine or warehouse columns.

The cumulative quantity must use only the currently selected warehouses.

==================================================
21. PARTNER PRODUCT DRILL-DOWN
==================================================

Show:

- Inventory ID
- Product
- Brand
- Product Category
- Business Line
- Total Available Stock
- Safety Stock
- Buffer Stock
- MSI
- Three-Month Sales
- Average Run Rate
- Months of Cover
- Replenishment Requirement
- MSI Status
- Coverage Status
- Planning Status

Also show:

- Warehouse stock breakdown
- Monthly sales versus available stock
- Low-stock annotations
- Replenishment events
- Sales spike annotations
- Three-month sales summary

Do not display manufacturing machines.

==================================================
22. SALES INTELLIGENCE
==================================================

Title:

Sales Intelligence

Subtitle:

YTD volume trends across Kim-Fay manufactured and partner brands

All values represent units or volume.

Do not use currency.

TOP FILTERS

1. Brand Ownership View

Options:

- Manufactured
- Partner Brands
- Combined View

Default:

- Combined View

2. Business Line — multi-select

Default:

- Consumer Sales
- Kim-Fay Professional

3. Brands — multi-select

Default:

All valid brands selected.

Brand options must update dynamically:

- Manufactured shows only manufactured brands.
- Partner Brands shows only partner brands.
- Combined View shows all brands.

4. Product Category — multi-select

Default:

All valid categories selected.

Category options must update dynamically based on ownership view, selected brands, and business line.

5. Warehouses — multi-select

Default:

- All Warehouses

6. Period

Options:

- YTD 2026
- Last 12 Months
- 2025
- 2024
- Custom Range

Default:

- YTD 2026

7. Channel — multi-select

Options:

- Modern Trade
- General Trade
- Wholesale
- Distributor
- DTC
- Export
- Kim-Fay Professional
- Institutional

Default:

- All Channels

8. Search

Search by:

- Product
- Inventory ID
- Brand
- Product Category

==================================================
23. SALES KPI CARDS
==================================================

Display:

- YTD Sales Volume
- Current Month Sales Volume
- Average Monthly Sales
- Active Brands
- Best Performing Brand
- Best Performing Product Category
- Growth versus Prior Year
- Best Performing Product
- Manufactured Share versus Partner Share

Growth formula:

(Current YTD Units minus Previous YTD Units)
divided by Previous YTD Units
multiplied by 100.

Handle zero prior-year volume safely.

==================================================
24. SALES OVERVIEW TABLE
==================================================

Columns:

- Sales ID
- Inventory ID
- Product
- Brand
- Product Category
- Brand Ownership
- Business Line
- YTD Sales
- Current Month Sales
- Average Monthly Sales
- Growth versus Last Year
- Contribution to Selected Total
- Trend Status

Trend status rules:

Growing:
Growth greater than 5%.

Stable:
Growth between -5% and 5%.

Declining:
Growth below -5%.

Use:

- Green for Growing
- Blue or grey for Stable
- Red for Declining

==================================================
25. SALES CHARTS
==================================================

MONTHLY SALES TREND

Compare:

- Current selected period
- Equivalent prior-year period

For YTD 2026, compare:

- Jan to current month 2026
- Jan to the same month in 2025

Add annotations for:

- Highest month
- Lowest month
- Promotion spike
- Unusual decline
- Recovery
- Seasonal peak

SALES MIX BY OWNERSHIP

Create a donut chart showing:

- Manufactured brands
- Partner brands

Display:

- Percentage share
- Total units
- Selected-period total

TOP BRANDS

Create a horizontal bar chart for the top five brands by units.

Allow switching between:

- Top 5
- Top 10

Show whether each brand is manufactured or partner.

TOP PRODUCTS

Create a horizontal bar chart showing the top products by units.

TOP PRODUCT CATEGORIES

Create a horizontal bar chart showing:

- Top 5 Product Categories by YTD Sales Volume
- Option to switch to Top 10

Allow one chart control to switch between:

- Brands
- Product Categories
- Products

YTD SALES BY MONTH

Create a compact monthly chart or table showing:

- Month
- Sales units
- Month-on-month growth
- Running YTD total

==================================================
26. CATEGORY ANALYTICS
==================================================

Add category-level analysis to Sales Intelligence.

Show:

- Top 5 Product Categories by YTD Sales Volume
- Product Category Contribution to Total Volume
- Product Category Growth versus Prior Year
- Best Performing Product Category
- Fastest Growing Product Category
- Declining Product Categories
- Category Share by Business Line
- Category Share by Brand Ownership

Allow users to click a category name or chart segment to filter the dashboard to that category.

CATEGORY DRILL-DOWN

When a category is selected, show:

- Total SKUs
- Total Available Stock
- YTD Sales Volume
- Current Month Sales
- Average Monthly Run Rate
- Average Months of Cover
- Critical SKU Count
- At-Risk SKU Count
- Healthy SKU Count
- Top Brands in the Category
- Top Products in the Category
- Monthly Category Sales Trend
- Category Stock Trend
- Growth versus Prior Year
- Contribution to Total Selected Volume

==================================================
27. KEY INSIGHTS
==================================================

Generate rules-based insights from the dummy data.

Examples:

- Manufactured brands contributed 58% of selected YTD volume.
- Partner brands grew 15.1% versus the same period last year.
- Fay is the best-performing brand by units.
- Toilet Paper contributed 32% of manufactured YTD sales volume.
- Body Wash was the highest-performing partner category.
- Deodorant Sprays grew by 14.2% versus the same period last year.
- Baby Wipes declined by 6.3% versus the prior year.
- Kitchen Towels recorded the highest month-on-month growth.
- ORS declined by 2.1% compared with last year.
- July recorded the highest sales volume.
- Kim-Fay Professional contributed 18% of selected YTD volume.

Label the section:

Key Insights

Do not present insights as AI-generated unless an actual AI service is connected.

==================================================
28. DUMMY DATA REQUIREMENTS
==================================================

Generate enough dummy data to test all interactions.

Minimum:

- 30 manufactured SKUs
- 45 partner SKUs
- 24 months of sales history per SKU
- At least 10 warehouses
- Both HQ and Tatu sites
- All listed production machines
- Both business lines
- At least 8 sales channels
- Multiple products per category
- Multiple categories per brand where realistic

Include varied scenarios:

- Zero stock
- Negative available stock
- Healthy stock
- Stock below MSI
- Products with less than one month of cover
- Products with more than three months of cover
- Fast-growing products
- Declining products
- Seasonal spikes
- Replenishment events
- Products with sales but no available stock
- Products with stock but no recent sales
- Categories shared by several brands
- Categories belonging to only one brand
- Categories present in both manufactured and partner datasets
- Categories with healthy stock
- Categories with critical stock
- Growing categories
- Declining categories
- Categories with seasonal demand

All dummy data must be internally consistent.

For every item:

Total Available Stock =
Sum of qtyAvailable from the selected warehouses.

Do not hard-code totals independently.

==================================================
29. EXAMPLE MANUFACTURED PRODUCTS
==================================================

Include examples such as:

- Fay Advanced Multifold Hand Towels 12 x 240 Sheets
- Fay Kitchen Towels 2 Rolls
- Fay Eco Kitchen Towels
- Fay Water Wipes 56s
- Fay Sensitive Wipes 56s
- Fay Everyday Wipes 56s
- Fay Antibacterial Wipes 56s
- Cosy Poa Toilet Paper 4 x 10s White
- Cosy Poa Toilet Paper 4 x 10s Pink
- Cosy Serviettes 18 x 100 Sheets
- Sifa Facial Tissues 100s
- Tishu Poa Toilet Paper 4 x 10s
- Ultra Clean Scouring Pads
- Kleenex Facial Tissues 100s
- Fay Cling Film
- Fay Aluminium Foil
- Fay Baking Paper
- Fay Hand Wash
- Fay Sanitizer

==================================================
30. EXAMPLE PARTNER PRODUCTS
==================================================

Include examples such as:

- Dove Body Wash 400ml
- Dove Men+Care Body Wash 400ml
- Dove Shower Gel
- Dove Roll-On 50ml
- Duracell AA Alkaline 4 Pack
- Duracell AAA Alkaline 4 Pack
- Duracell CR2032
- Duracell CR2016
- Huggies Pure Wipes 56s
- Huggies Dry Pants Large
- Vatika Shampoo 400ml
- Vatika Conditioner 400ml
- Bio-Oil Skincare Oil 125ml
- ORS Olive Oil Braid Spray 236ml
- Fem Hair Removal Cream
- Hobby Body Lotion 400ml
- Kotex Ultra Thin Pads 16s
- Rexona Deodorant Spray
- Lux Bar Soap
- Miswak Toothpaste
- Dermoviva Body Lotion
- Airoma Air Freshener
- Aptamil Infant Formula
- Cow & Gate Infant Formula

==================================================
31. FILTER INTERACTIONS
==================================================

All filters must work with dummy data.

When a filter changes:

- KPI cards update
- Product table updates
- Warehouse breakdown updates
- Charts update
- Totals update
- Insights update
- Product detail panel updates
- Category analytics update
- Invalid dependent selections are removed

Filtering must happen instantly on the client side.

Use memoised selectors to avoid unnecessary recalculation.

Persist filters per dashboard in localStorage.

Use keys such as:

- production-intelligence-filters
- partner-intelligence-filters
- sales-intelligence-filters

Add:

- Reset Filters
- Clear individual selections
- Select All
- Search inside multi-selects
- Empty state
- Loading skeleton
- Error-state component for future API use

==================================================
32. UX RULES
==================================================

- Keep the interface executive-friendly.
- Avoid excessive visual clutter.
- Keep KPI cards at the top.
- Keep the main table as the primary focus.
- Use the right-side area for product details.
- Use a drawer for product details on smaller screens.
- Multi-select filters must show removable chips.
- Collapse excess chips into +N.
- Support keyboard navigation.
- Add accessible labels.
- Use meaningful empty states.
- Add a visible Reset Filters action.
- Do not permanently show explanatory tooltip panels on the right.
- Use tooltips only for icons or short explanations.
- Wrap filters to a second row on smaller screens.
- Use a More Filters drawer when needed.
- Place Product Category inside More Filters on smaller screens if necessary.
- Keep chart labels and table headings readable.
- Maintain the Kim-Fay blue visual identity.
- Use sticky table headers.
- Add clear hover states.
- Add clear selected-row states.
- Add loading skeletons.
- Add no-results states.
- Add pagination.
- Add compact and comfortable table density modes.

==================================================
33. IMPORTANT BUSINESS RULES
==================================================

1. All sales values are units, not revenue.
2. Main inventory table stock must be cumulative across selected warehouses.
3. Do not show machine or warehouse columns in the main product tables.
4. Machines are only relevant to Production Intelligence.
5. Partner Intelligence must not show production machines.
6. MSI editing is role-controlled.
7. Status must recalculate when MSI changes.
8. Run rate uses the latest three complete months.
9. All totals must come from underlying records.
10. Sales Intelligence combines manufactured and partner data without duplicate SKUs.
11. Brand options must react to brand ownership filters.
12. Category options must react to brand ownership, brand, and business-line filters.
13. Use multi-select filters wherever specified.
14. Apply all stated default selections on first load.
15. Use dummy data only in this version.
16. Keep services and types ready for future Laravel API integration.
17. Every dummy SKU must have exactly one valid product category.
18. Category totals must be calculated from SKU records.
19. Main quantity available must always match the selected warehouse records.
20. Do not hard-code KPI totals separately.

==================================================
34. DEFINITION OF DONE
==================================================

The frontend is complete when:

- All three dashboards are implemented.
- The pages visually follow the supplied screenshots.
- Dummy data powers all KPIs, tables, charts, totals, and insights.
- All filters work.
- HQ and Tatu dynamically control machines and warehouses.
- Machine and warehouse filters support multi-select.
- Manufactured and partner brand filters default to all selected.
- Product Category is available as a working multi-select filter on all three dashboards.
- Product Category appears in all relevant tables and product details.
- Category options respond dynamically to brand ownership, brand, and business-line selections.
- Sales Intelligence includes category-level rankings, trends, contribution, growth, and drill-down.
- Every dummy SKU has a valid category assignment.
- Main inventory tables show cumulative stock across selected warehouses.
- Three-month run rate and stock cover are calculated.
- MSI status, coverage status, and planning status are displayed.
- Authorised roles can edit MSI using a confirmation dialog.
- Sales Intelligence combines manufactured and partner sales volumes.
- No revenue or currency is shown.
- The interface is responsive and accessible.
- Filter state persists in localStorage.
- Invalid dependent filter selections are cleared automatically.
- Charts, KPI cards, tables, and insights update instantly.
- The code is reusable, modular, and ready for Laravel API integration.