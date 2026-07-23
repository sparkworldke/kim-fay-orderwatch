# July 2026 staff and customer portfolio import

1. Apply migrations.
2. Preview staff reconciliation with `php artisan team:import-staff --path="../excels/Staff_Email_Match_July_2026.xlsx" --dry-run --preserve-manual`.
3. Preview the portfolio with `php artisan customers:preview-staff-portfolio`.
4. Review every error/gap and the acceptance-case owners in the assignment batch. Do not apply an unapproved batch.
5. Replace the pending, empty frozen JSON with the reviewed user and assignment rows, set `review_status` to `approved`, and review the change in source control.
6. Run `php artisan db:seed --class=StaffPortfolioImport2026JulySeeder` against a recent production-like snapshot and compare counts.
7. Run the same explicit seeder in production. It is intentionally not registered in `DatabaseSeeder`.

The seeder reads only the frozen JSON. Corrections after deployment require a new versioned data file and follow-up seeder.

## Bugs found and fixed while dry-running against the real files (2026-07-23)

Running steps 2–3 against the actual workbooks surfaced real bugs — all fixed in `StaffImportService`/`StaffPortfolioImportService`, verified by `tests/Feature/StaffPortfolioImportServiceTest.php` (401 assertions against the real files with 9 real staff emails seeded):

1. **Header-row detection** — "Matched Staff" leads with a title + subtitle + blank row before the real header row; both loaders assumed row 1 was always the header, so almost every row silently became a gap. Fixed by skipping rows with ≤1 populated cell before treating one as the header.
2. **Sheet-name whitespace** — the real tab name is `"Outlets "` (trailing space), not `"Outlets"`; lookup now falls back to a trimmed-name match.
3. **Naivas/Magunas Thika carve-out — this was silently producing the wrong owner.** The carve-out checked the `Region` column for "Thika", but Thika branches are tagged with the *same* region as every other branch of that account ("Nairobi Key Accounts" / "Nairobi West") — the only real signal is the branch name itself. Before the fix, the two real Naivas-Thika outlets (`CUST101416`, `CUST101303`) would have resolved to Lucy Wanjiru instead of Lilian Kimeu.
4. **Apostrophe canonicalization** — "Maguna's" canonicalized to "maguna s" (split into two tokens), so every Magunas-specific rule silently never matched (results still happened to be correct via literal fallback, but the audit trail was wrong). Apostrophes are now stripped before tokenizing.
5. **Needle typos** — `kassmart`/`khetias`/`on the way` didn't match the real account names (`Kassmatt`/`Khetia Drapers`/`Onn The Way`). Same "correct by literal-fallback accident, wrong audit tag" situation; now fixed.
6. **Fuzzy name-matching threshold** — the Outlets/Rollup sheets use short display names ("George Amenya") while the HR staff sheet has full legal names ("George Amenya Moranga"); `similar_text()`'s raw percentage score can't structurally clear the 90% bar in that shape, so **every one of the 10 named reps failed to resolve**. Added a token-subset check (every word in the shorter name appears in the longer one → high-confidence match), mirroring the existing `agent-tools/match_staff_emails.py` approach to the same problem.

**Real data gap, not a bug:** Lawrence Amukhono (Khetias + all Rift MT outlets) has no row in "Matched Staff" — per "Missing Email", he has no active email/user account today. His portion of the portfolio will correctly land in the gap-review queue until an account exists for him; nothing should be guessed on his behalf.
