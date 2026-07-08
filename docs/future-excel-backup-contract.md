# Future Excel Backup Upload Contract

This phase keeps Acumatica API as the production data source. Excel upload is intentionally out of scope for now.

If a backup upload is added later, the importer should accept the same operational summary fields used by the dashboard.

## Fill Rate Workbook

Required summary/source fields:

- Reference Nbr.
- Date
- Actual Qty.
- Actual Value (Excl)
- Product Code
- Product Description
- Customer ID
- Customer Name
- Customer Group
- Ordered Qty
- Total Order Value
- Undershipped Qty
- Undershipped Value
- Status
- Reason
- Department

## Backorders Workbook

Required summary/source fields:

- Date
- Order Number
- CustomerID
- Customer Name
- Customer Group
- Inventory ID
- Item Description
- Back Order Qty
- Back ordered Value
- Reason
- Department
- Status

## Notes

- Acumatica remains the source of truth when both API data and spreadsheet data exist.
- The dashboard should use the same labels for API summaries and future spreadsheet-derived summaries.
- Upload implementation, validation screens, and conflict resolution are deferred.
