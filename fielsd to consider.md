fielsd to consider
FOr Fillrate use quantinty per Item whic is marked as QtyOnShipments then five cost to business and comparison to what we are doing.


*FIELDS TO ADD*

*Header Level (SOOrder):*
1. ApproveDate / ApprovedDateTime — exact approval timestamp
2. CompletedDate — exact completion timestamp
3. RejectedBy — who rejected the SO
4. RejectedDateTime — when rejection happened

*Line Level (SOLine > Details):*
5. ShippedQty ⭐ HIGHEST PRIORITY — confirmed dispatched qty needed for fill rate
6. CancelledQty — qty cancelled by Cancel Remainder rule
7. UnbilledQty — shipped but not yet invoiced
8. DemandQty — demand-driven backorder quantity
9. SchedShipDate — expected ship date for backorders
10. PONbr — linked purchase order for backorder lines
11. OrigOrderQty — original qty before backorder split
12. ItemStatus — Active/Inactive filter for inventory
13. ItemClassID — product category
14. QtyOnHand — current stock on hand
15. QtyAvail — available to promise
16. QtySOReserved — reserved for other SOs
17. QtySOBackOrdered — total backordered across all SOs

*New Sub-entity — Approvals (EPApproval):*
18. ApprovedByID, ApproveDate, Status, Reason,