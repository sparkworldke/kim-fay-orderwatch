# Daily Executive Exceptions Email

**Report type:** `daily_executive_email`  
**Timezone:** Africa/Nairobi  
**Report date:** Yesterday (calendar day before send time)  
**Command:** `php artisan orderwatch:send-daily-report-fixed`

## Sections

### 1. Order Exceptions
- Week KPIs (Mon–Sat through report date): Total Orders, Pending Approval, In Shipping
- Daily table per weekday (Sundays excluded)
- Prior-month carryover when count > 0: incomplete orders split by Pending Approval / In Shipping

### 2. Fill Rate & Backorders (yesterday only)
- Fill rate % from orders dated yesterday
- Top 5 reasons with **explicit Acumatica reason codes only** (excludes unassigned)
- Top 5 SKUs by backorder value — **product description**, not inventory ID
- Top customer groups from yesterday's backorder lines

### 3. Nairobi & Mombasa 24hr SLA
- Week delay % per region (`nairobi`, `coast`)
- Breach count, delayed KES value, on-time %

### 4. Revenue Split (Yesterday)
- **KP:** `customer_class LIKE 'KP%'`
- **CS:** `customer_class LIKE 'CS%'`

## Status normalization
- Pending Approval: `LOWER(TRIM(status)) = 'pending approval'`
- In Shipping: `LOWER(TRIM(status)) = 'shipping'`
- Prior-month complete: `completed`, `back order` (shipping still counts as incomplete carryover)

## Implementation files
- `backend/app/Services/Reports/DailyExecutiveReportService.php` — payload builder
- `backend/app/Services/Reports/ExecutiveReportMetricsService.php` — fill rate, SLA, revenue queries
- `backend/app/Mail/DailyManagementReportMail.php` — HTML template
- `backend/tests/Feature/DailyExecutiveReportTest.php` — tests

## Send / preview
```bash
cd backend
php artisan orderwatch:send-daily-report-fixed --source=manual
```