# User identity JSON seeds (Active staff + HODs)

## Sources

| File | Purpose |
|---|---|
| `excels/Active staff July 2026- HQ.xlsx` | Employee number + official name (Permanent + STC) |
| `excels/Kimfay Employees - HODs.csv` | Org chart employee numbers + reports_to + segment |
| `excels/KP-Customers 20260805 Agusst.csv` | Sales Rep name → Rep Code (for `users.rep_code`) |

## Generated files

| JSON | Content |
|---|---|
| `active-staff-2026-07.json` | Full HQ staff extract |
| `hods-2026.json` | Full HOD/org extract |
| `user-identity-alignments-2026-08.json` | Name matches + diagnostics |
| `user-identity-seed-2026-08.json` | **Seeder input** (match_names → employee_number) |
| `kp-sales-rep-codes-2026-08.json` | KP Sales Rep → rep_code (+ aliases YVON/C967/C1262) |

## Rebuild JSON

```bash
python backend/scripts/build_user_identity_json.py
# then re-run KP rep enrichment if needed (script updates alignments;
# kp-sales-rep-codes is built by the same manual step or seeder load)
```

To refresh KP rep codes only:

```bash
# from repo root after build_user_identity_json.py
python -c "exec(open('backend/scripts/build_user_identity_json.py').read())"  # staff/hods
# use the one-liner in repo history or re-run the enrich step in build pipeline
```

## Run seeder (production / staging)

```bash
cd backend
php artisan db:seed --class=UserIdentityFromStaffJsonSeeder
```

Then portfolio:

```bash
php artisan db:seed --class=KpRepCodeAlignment202608Seeder
php artisan db:seed --class=KpCustomerPortfolio202608Seeder
```

## Behaviour

1. Match **active** users by name (exact normalized, else ≥2 token overlap).  
2. Set `users.employee_number` from **Active staff**.  
3. Set `users.rep_code` when known (JSON or KP customers map / aliases).  
4. Upsert `user_acumatica_rep_mappings`.  
5. Set `reports_to_user_id` when both employee numbers resolve.  
6. Skip ambiguous multi-user name matches (logged).  

Does **not** create users — only corrects existing profiles.
