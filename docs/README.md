# OrderWatch docs

All project documentation, spreadsheets, CSVs, and sample files live here.

## Layout

| Path | Contents |
|------|----------|
| `docs/*.md` | Guides, PRDs, plans, notes |
| `docs/data/` | Excel workbooks and CSVs (BI export, HR match, samples) |
| `docs/samples/` | Sample PO PDFs and similar attachments |

## Notable guides

| Doc | Topic |
|-----|--------|
| [DEPLOY-VPS.md](./DEPLOY-VPS.md) | Backend VPS deploy |
| [fill-rate-user-guide.md](./fill-rate-user-guide.md) | Fill Rate module |
| [fillrate-manufactured-teams-status.md](./fillrate-manufactured-teams-status.md) | Fill Rate · Manufactured · Teams status |
| [team-module-guide.md](./team-module-guide.md) | Team & org rollout |
| [cron-jobs-guide.md](./cron-jobs-guide.md) | Scheduler / cron jobs |

## Data files used by the app

| File | Used by |
|------|---------|
| `data/Stock Items BI(Data).csv` | `php artisan inventory:seed-from-bi` |
| `data/staff_email_match.xlsx` | `php artisan team:import-staff` (fallback after JSON) |
| `../agent-tools/staff_email_match.json` | Default staff import input |

## Kept outside `docs/`

| Path | Why |
|------|-----|
| `AGENTS.md` | Agent / Lovable project instructions at repo root |
| `backend/README.md` | Package readme for the Laravel API |
| `orderwatch/AGENTS.md` | Cloudflare worker subproject instructions |
