# PRD: TPFGS (Tatu Park Finished Goods) Warehouse Inventory Sync

**Status:** Implemented (config + schedule + manual sync wiring)  
**Date:** 31 July 2026  
**Product:** Kim-Fay OrderWatch / Sight  
**Owner:** Platform / Operations  

---

## 1. Summary

Enable OrderWatch to **schedule and manually sync stock positions for Acumatica warehouse `TPFGS`** (Tatu Park Finished Goods), now that the integration user `ipay` has branch access that surfaces TPFGS inventory via the `IpayV2` API.

TPFGS becomes a first-class warehouse in:

- Stock-position **cron jobs** (twice-daily staggered schedule)
- Admin **manual inventory / stocks-only sync** warehouse picker
- Operations inventory **warehouse filters and summary** (config-driven)

---

## 2. Background & motivation

### 2.1 Business context

- **FGS** is Kim-Fay’s primary Nairobi finished-goods warehouse and was already on the stock-sync list.
- **TPFGS** is the Tatu Park finished-goods site. Stock visibility there matters for production planning, backorder / fill-rate context, and multi-site inventory views.
- Production UI already treats `TPFGS` as a finished-goods code (`FINISHED_GOODS_CODES`), but the **backend sync list did not include it**, so balances never refreshed from Acumatica on a schedule.

### 2.2 Access verification (pre-implementation)

Probed live Acumatica with `ipay` + endpoint `IpayV2` / `22.200.001`:

| Capability | Result |
|------------|--------|
| OAuth password grant | Success |
| `StockItem` + `$expand=WarehouseDetails` | Success — **`WarehouseID = TPFGS` present** |
| Qty on TPFGS rows | Readable (e.g. non-zero `QtyOnHand` on multiple SKUs) |
| Related TP sites in details | `TPRODSPARE`, `TPRMS` also visible on items |
| Direct `Warehouse` form (`IN204000`) | **403** — insufficient rights (unchanged) |
| `ItemWarehouse` / inventory summary inquiry | 403 / not on endpoint |

**Conclusion:** iPay does **not** need the Warehouse master form. Per-warehouse stock sync already uses **stock item warehouse details**, which **includes TPFGS**. Safe to add to OrderWatch sync.

### 2.3 Problem statement

Without TPFGS on `config/inventory.warehouses`:

1. No cron row `inventory-sync-tpfgs`
2. Manual admin sync rejects `warehouse_id=TPFGS` (hard-coded allow-list)
3. Inventory summary / filters under-represent Tatu Park FG stock

---

## 3. Goals

1. **Sync** TPFGS stock positions on the same morning + midday stagger pattern as other warehouses.
2. **Allow manual** full inventory and stocks-only sync filtered to TPFGS from Administration → Sync.
3. **Surface** TPFGS in warehouse pickers / counts driven by config (no one-off special cases).
4. **Avoid schedule thrash** for warehouses already in production (append TPFGS; do not re-index mid-list).

### Non-goals

- Granting iPay rights to the Warehouse master form (not required).
- Adding every other TP warehouse (`TPRMS`, `TPRODSPARE`, `PFGS`, etc.) in this change.
- Changing DTC Calltronix price-list warehouse set (still DTC + FGS only).
- Live ERP calls on every page load (snapshot + cron model unchanged).
- Multi-branch company switching beyond what stock details already expose.

---

## 4. Functional requirements

### 4.1 Configuration (source of truth)

| Key | Requirement |
|-----|-------------|
| `config/inventory.php` → `warehouses` | Must include `TPFGS` |
| `warehouse_labels['TPFGS']` | Human label: `TPFGS (Tatu Park FG)` |
| Order of list | **Append** TPFGS after existing warehouses so stagger indices for DTC…TRMS stay fixed |

### 4.2 Scheduled sync

| Item | Value |
|------|--------|
| Job key | `inventory-sync-tpfgs` |
| Command | `php artisan orderwatch:inventory-sync --job-key=inventory-sync-tpfgs --warehouse=TPFGS` |
| Mode | Stocks-only (`stocks_only: true` in job settings), same as other per-warehouse jobs |
| Schedule pattern | Twice daily from `stock_sync.morning_start` / `midday_start` with `stagger_minutes` (default 30) |
| Expected times (default config, index 9) | **13:00** and **16:30** app/cron timezone (EAT) |
| Ensure path | `CronJob::ensureWarehouseInventorySyncs()` (already runs with other cron seed/ensure) |

Removed warehouses remain paused via existing `ensureWarehouseInventorySyncs` logic; TPFGS is **enabled**.

### 4.3 Manual sync (admin API + UI)

| Surface | Behaviour |
|---------|-----------|
| `POST /api/admin/acumatica/sync/inventory` | Accepts `warehouse_id=TPFGS` |
| `POST /api/admin/acumatica/sync/inventory-stocks` | Accepts `warehouse_id=TPFGS` |
| Validation allow-list | Derived from `config('inventory.warehouses')` (no hard-coded duplicate list) |
| Administration UI | Import warehouse dropdown includes **TPFGS** |

### 4.4 Downstream consumers (automatic once config + data exist)

- Operations inventory summary / warehouse chips (`OperationsController` merges config + DB).
- `inventory_warehouse_balances` rows for `warehouse_id = TPFGS`.
- Production / stock views that already treat TPFGS as finished goods when balances exist.

---

## 5. Technical design

### 5.1 Data flow (unchanged architecture)

```
Acumatica StockItem (+ WarehouseDetails)
        → AcumaticaInventorySyncService (filter warehouse_id=TPFGS)
        → inventory_warehouse_balances / inventory item stock fields
        → Operations + Production Intelligence APIs
```

Per-warehouse jobs continue to prefer **stocks-only** updates; use `--full` or admin full inventory sync when new SKUs must be created.

### 5.2 Files touched (implementation)

| Area | File |
|------|------|
| Config | `backend/config/inventory.php` |
| Cron ensure + fallback list | `backend/app/Models/CronJob.php` |
| Manual sync validation | `backend/app/Http/Controllers/Api/Admin/AcumaticaController.php` |
| Admin warehouse dropdown | `src/routes/app.administration.tsx` |
| Unit tests | `backend/tests/Unit/InventoryWarehouseCronScheduleTest.php` |

### 5.3 Schedule math (default)

`slot = start + index * 30 minutes`

| Index | Warehouse | Morning | Midday |
|------:|-----------|---------|--------|
| 0 | DTC | 08:30 | 12:00 |
| 1 | FGS | 09:00 | 12:30 |
| … | … | … | … |
| 8 | TRMS | 12:30 | 16:00 |
| **9** | **TPFGS** | **13:00** | **16:30** |

If ops need TPFGS earlier in the day, reorder `warehouses` carefully (reorders shift **all** later indices) or introduce explicit per-warehouse schedule overrides (out of scope for this PRD).

---

## 6. Acceptance criteria

- [x] `TPFGS` present in `config('inventory.warehouses')` and labelled in `warehouse_labels`.
- [x] `CronJob::inventoryWarehouseJobKey('TPFGS')` === `inventory-sync-tpfgs`.
- [x] Stagger labels for TPFGS index match **13:00** / **16:30** under default stock_sync settings.
- [x] Manual inventory + inventory-stocks endpoints accept `warehouse_id=TPFGS`.
- [x] Administration import warehouse list includes TPFGS.
- [x] Unit test covers TPFGS membership, job key, label, and schedule slots.
- [ ] After deploy: `CronJob::ensureWarehouseInventorySyncs()` (or app boot that already ensures jobs) creates/enables `inventory-sync-tpfgs`.
- [ ] Ops runs one manual stocks-only sync for TPFGS and confirms `inventory_warehouse_balances` rows + non-zero sample qty where ERP has stock.
- [ ] Cron run log for `inventory-sync-tpfgs` shows success on next scheduled window (or forced run).

---

## 7. Rollout / ops runbook

1. Deploy backend + frontend with config change.
2. Ensure cron rows: hit any path that calls `CronJob::ensureWarehouseInventorySyncs()`, or run a one-liner artisan tinker / existing cron ensure command used in ops.
3. Optional first load:
   ```bash
   php artisan orderwatch:inventory-sync --job-key=inventory-sync-tpfgs --warehouse=TPFGS --source=manual --force
   ```
4. Verify Administration → Sync → warehouse **TPFGS** manual stocks sync.
5. Verify Inventory / Operations warehouse summary shows **TPFGS** with SKU counts after sync.
6. Monitor Acumatica timeouts (same SSL / page-size behaviour as other warehouse jobs).

---

## 8. Risks & mitigations

| Risk | Mitigation |
|------|------------|
| Longer overall morning/midday wave (one extra warehouse) | 30-minute stagger; TPFGS last so peak FG jobs (FGS) stay early |
| Empty qty fields on some WarehouseDetails rows (API sometimes returns `{}`) | Existing sync mapping; spot-check non-zero ERP SKUs after first run |
| Future warehouse list drift between PHP config and admin UI | Backend validation now reads config; keep frontend constant comment + list in sync (same pattern as before) |
| Operators expect Branch entity API | Branch not exposed on IpayV2; stock details are sufficient |

---

## 9. Future work (out of this PRD)

- Optionally add `TPRMS` / `TPRODSPARE` if raw materials / spares visibility is required for Tatu Park.
- Drive admin warehouse dropdown from an API reading `config/inventory.warehouses` to eliminate frontend hard-coding entirely.
- Explicit schedule overrides per warehouse if 13:00/16:30 is too late for Supply Chain.

---

## 10. References

- `backend/config/inventory.php`
- `backend/app/Models/CronJob.php` (`ensureWarehouseInventorySyncs`, `inventoryWarehouseSync`)
- `backend/app/Console/Commands/RunInventorySync.php`
- `backend/app/Services/Admin/AcumaticaInventorySyncService.php`
- Access probe scripts: `agent-tools/probe_tpfgs_access.py`, `agent-tools/probe_tpfgs_detail.py`
- Related product notes: `BACKORDER-REPORT-REFACTOR.md` (FGS primary; TPFGS secondary site)
