# Push and deploy today’s Kim-Fay Sight updates

This checklist preserves the Lovable-connected Git history. Do not force-push, rebase, amend, or squash commits that have already been pushed.

## 1. Review and push from the development computer

Run these commands from the project root:

```powershell
cd C:\laragon\www\kim-fay-orderwatch
git status
git diff --check
git diff --stat
```

Review the changed files, then stage the completed update:

```powershell
git add backend src new-doc
git status
git commit -m "Improve reporting access backorders and mobile experience"
git push origin HEAD
```

Never use `git push --force` on this project.

## 2. Update the application server

Replace `/var/www/kim-fay-orderwatch` with the actual server path.

```bash
cd /var/www/kim-fay-orderwatch
git status
git pull --ff-only
```

If `git status` shows server-side edits, stop and review them before pulling. Do not discard them blindly.

## 3. Install production dependencies

Frontend, from the project root:

```bash
npm ci
npm run build:production
```

Laravel backend:

```bash
cd backend
composer install --no-dev --prefer-dist --optimize-autoloader
```

## 4. Run the Laravel deployment commands

Put the backend in maintenance mode only for the migration and cache refresh:

```bash
php artisan down --retry=60
php artisan optimize:clear
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
php artisan up
```

The migration grants `reports.export` to all existing roles. The role seeder is therefore not required during normal deployment.

If role records were manually deleted or this is a new installation, run:

```bash
php artisan db:seed --class=Database\\Seeders\\RolesPermissionsSeeder --force
```

Do not run `php artisan migrate:fresh`, because it deletes application data.

## 5. Configure scheduled checks for every 30 minutes

The server cron entry should be:

```cron
*/30 * * * * cd /var/www/kim-fay-orderwatch/backend && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Remove the previous every-minute entry after confirming the new path and PHP executable. Check the effective Laravel schedule with:

```bash
php artisan schedule:list
```

## 6. Verify after deployment

```bash
php artisan about
php artisan migrate:status
php artisan queue:monitor default
php artisan schedule:list
```

Then check in the browser:

1. Sign in with an administrator and a normal team account.
2. Open `/app/backorders` on desktop and at a 360px phone width.
3. Confirm the phone shows SKU cards and expanded order cards without horizontal page scrolling.
4. Download the filtered Backorders Excel workbook.
5. Queue a large Backorders download and confirm it appears under Downloads.
6. Confirm the normal team account only exports customers, channels, and brands inside its assigned scope.

## 7. If the migration fails

Keep the site in maintenance mode, save the error output, and inspect:

```bash
php artisan migrate:status
php artisan about
```

After correcting the cause, rerun:

```bash
php artisan migrate --force
php artisan optimize
php artisan queue:restart
php artisan up
```

Avoid rolling back unrelated migrations on a live database.
