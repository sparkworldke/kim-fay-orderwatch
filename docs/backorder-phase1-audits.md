# Backorder Phase 1 Audits

## Endpoint consumption

Repository search completed on 2026-07-22. External/BI consumers cannot be ruled out, so no route is removed.

| Endpoint | Known repository consumer | Status |
|---|---|---|
| `GET operations/backorders` | `useBackorders`; backorder page and customer/order detail cards | Active |
| `GET operations/backorders/summary` | `useBackordersSummary`; backorder page | Active |
| `GET operations/backorders/analytics` | `useBackordersAnalytics`; backorder page filters/charts | Active |
| `GET operations/backorders/export` | Backorder page download action | Active |
| `GET operations/backorders/by-account` | `useBackordersByAccount` hook | Active; manager/admin restricted |
| `GET operations/backorders/sku-breakdown` | Shared business-category SKU breakdown hook | Active |
| `GET operations/backorders/sku-breakdown/export` | Shared SKU breakdown export workflow | Active; manager/admin restricted |
| `GET operations/backorders/reconciliation` | No `src/` consumer found | Deprecation candidate only |
| `PATCH operations/backorders/{id}` | Reason editor mutation | Active |

## Reason-code usage

Run `php artisan orderwatch:audit-backorder-reasons --days=90` in the target environment. Add `--json` for machine-readable output. The command recommends retirement only when both recent usage and currently stored references are zero; it never changes the vocabulary.

The current table is a latest-state store, so “stored references” is not guaranteed to represent reasons from rows already pruned. Review output with Sales Operations before changing assignment options.

## Classification contract

`brand` is the canonical backorder display classification. `posting_class`, `sub_trading_group`, and `supplier` remain secondary metadata. `product_segment` is the separate Manufactured/Trading business-value axis and is not interchangeable with brand.
