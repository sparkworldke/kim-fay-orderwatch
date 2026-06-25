• Derives line status: Backorders Imported, Fully Fulfilled, Cancelled, Partially Shipped — Backorder Pending, Pending Shipment
     • Maps all qty fields: OrderQty, ShippedQty, OpenQty, CancelledQty, UsrQtyAtApproval (falls back to OrderQty)
     • Computes backorder_qty and fill_rate_pct with NULL when denominator is missing (never defaults to 0%/100%)

     Real dates (no LastModified proxy)
     SO sync now stores only dedicated Acumatica fields:
     • ApprovedDateTime / ApprovedByID → approved_at, approved_by_id
     • ActualShipDate → shipped_at
     • CompletedDateTime / InvoiceDate → completed_at

     Backorder sync
     • Fetches all open SOs (not only Status eq 'Backorder')
     • Imports lines where derived status is backorder and open_qty > 0
     • Stores fulfillment_status, backorder_qty, qty_at_approval, cancelled_qty

     Fill rate
     • Uses UsrQtyAtApproval as denominator when present, else OrderQty
     • Caps at 100% for over-delivery; returns NULL when denominator is zero

     Inventory
     • Chunked fetch via InventoryItem with ItemStatus eq 'Active' (falls back to StockItem)
     • Max 500 pages, 200 records/page guardrails