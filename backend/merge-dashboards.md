I copied a Stock Production dashboard feature into this existing TanStack React project. Laravel is the backend API.

The copied files are namespaced as follows:

src/components/production/
src/features/kimfay-production/
src/pages/StockInventoryDashboard.tsx
src/pages/StockPartnerIntelligence.tsx
src/pages/StockSalesIntelligence.tsx
src/routes/production.tsx
src/routes/production/
src/types/Stock/
src/utils/Stock/
src/services/Stock/
src/productionStyle.css

Please integrate the copied feature into this existing project safely.

Requirements:

1. Inspect the existing project before editing:
   - package.json
   - vite.config.*
   - tsconfig.json
   - src/routes/__root.tsx
   - existing route structure
   - existing QueryClientProvider
   - authentication and authorization implementation
   - API client
   - Tailwind and global CSS configuration
   - shared UI components

2. Do not replace or delete existing project files unnecessarily.

3. Preserve the namespaced structure:
   - components/production
   - services/Stock
   - types/Stock
   - utils/Stock
   - routes/production

4. Integrate these TanStack routes:
   - /production
   - /production/partners
   - /production/sales

5. If those routes already exist, use:
   - /stock-production
   - /stock-production/partners
   - /stock-production/sales

6. Do not copy or manually overwrite routeTree.gen.ts.
   Let the TanStack Router plugin regenerate it.

7. Reuse the existing QueryClientProvider.
   Do not create a second QueryClient or duplicate provider.

8. Replace copied mock-data imports with Laravel API requests.
   There must be no runtime imports from:
   - @/data/Stock
   - generated demo inventory
   - generated demo sales

9. Update services under src/services/Stock to use the existing API client.

Expected Laravel endpoints:

GET /api/stock/inventory
GET /api/stock/inventory?ownership=manufactured
GET /api/stock/inventory?ownership=partner
GET /api/stock/sales
GET /api/stock/transfer-requests
POST /api/stock/transfer-requests
POST /api/stock/transfer-requests/email
GET /api/stock/transfer-requests/export
PATCH /api/stock/inventory/{inventoryId}/msi

10. Follow the existing API client's conventions for:
    - base URL
    - credentials
    - CSRF protection
    - Laravel Sanctum
    - authentication headers
    - JSON parsing
    - validation errors
    - abort signals
    - error reporting

11. Connect the dashboard to the existing authenticated user.
    Remove or replace:
    - temporary role selector
    - localStorage-based role simulation
    - localStorage MSI overrides
    - demo permissions

12. Use existing authorization rules.
    MSI editing must only be visible and callable for authorized users.

13. Replace demonstration transfer-request logic with Laravel data.
    Remove forced PB SKU examples and frontend-generated demo records.

14. Transfer Requests must support:
    - grouped results by brand
    - affected SKU count
    - available source warehouses
    - Qty on Hand
    - Qty Available
    - server-created transfer requests
    - server-generated CSV export
    - Laravel queued email sending
    - multiple validated email recipients

15. Do not use mailto: for production email sending.
    Call POST /api/stock/transfer-requests/email.

16. Do not use browser-generated CSV if the Laravel endpoint is available.
    Download from GET /api/stock/transfer-requests/export.

17. Import src/productionStyle.css only in the production route layout.

18. Keep the production dashboard wrapped in:

<div className="production-dashboard">
  ...
</div>

19. Reuse existing design tokens and UI components where compatible.
    Add only missing production-specific tokens.
    Do not replace the destination global stylesheet.

20. Fix import aliases to match the destination tsconfig and Vite configuration.

21. Compare package.json and install only missing dependencies.
    Likely requirements include:
    - @tanstack/react-query
    - @tanstack/react-table
    - recharts
    - lucide-react
    - sonner
    - date-fns
    - required Radix UI packages

22. Preserve existing navigation and application layout.
    Add links to the Stock Production routes using existing navigation patterns.

23. Remove migration residue:
    - demo data
    - mock delays
    - PB-001 or forced FGS examples
    - Lovable references
    - static last-updated timestamps
    - "coming soon" controls
    - stale imports
    - duplicate providers
    - unused copied files
    - mojibake characters

24. Keep these calculations consistent:
    - Daily Run Rate = sales during the last three complete months divided by the exact number of calendar days in those months.
    - Days of Cover = Qty Available divided by Daily Run Rate.
    - Stock Needed = max(0, MSI - Qty Available).
    - Critical = Qty Available below 50% of MSI.
    - At Risk = Qty Available from 50% up to below 100% of MSI.
    - Healthy = Qty Available at or above MSI.

25. Prefer Laravel-calculated authoritative values when returned by the API.
    Do not allow frontend calculations to override server values.

26. After integration:
    - run TypeScript validation
    - run lint
    - run tests
    - run the production build
    - regenerate the TanStack route tree
    - test all three routes
    - test mobile and desktop layouts
    - test loading, empty, error, and permission states
    - verify there are no console errors

27. Search for residue with:

rg -n "@/data/Stock|mock|demo|PB-001|Lovable|localhost|mailto:|coming soon|localStorage" src

28. Report:
    - files changed
    - routes added
    - API contracts used
    - dependencies added
    - mock code removed
    - unresolved backend requirements
    - tests and build results

Do not stop after proposing a plan. Complete the integration, fix all compilation issues, and verify the final build.