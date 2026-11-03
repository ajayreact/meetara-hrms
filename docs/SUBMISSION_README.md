# Veltrix HRMS

Veltrix HRMS is a multi-company human-resources application. People sign in, work inside one company, and see only the screens their role allows. The local WAMP installation is the demonstration environment. It is not a production deployment.

## Stack

- PHP 8.3 and Laravel 12
- React 19, TypeScript, Inertia 2, and Tailwind CSS 4
- MySQL 8.4
- Vite 6 for the frontend build

Authorization is application code in `app/Auth/CompanyRole.php`. The project does not require `spatie/laravel-permission`.

## Roles

Stored role names map to six application roles:

| Stored name | Application role |
|---|---|
| Platform flag `is_platform_admin` | Super Admin |
| Owner | Company Admin |
| HR | HR |
| Accounts | Accounts |
| Manager | Team Lead |
| Employee, or any unknown name | Employee |

## Core modules

Dashboard, companies and invitations, employees, onboarding documents, offer letters, attendance and office networks, leave, holidays, overtime, salary, payroll, help desk, and inbox.

## Local setup

Prerequisites: PHP 8.3, Composer, Node.js, and MySQL 8.4. This tree was exercised on WAMP.

```powershell
composer install
npm install
copy .env.example .env
php artisan key:generate
npm run build
php artisan serve
```

`.env.example` points `DB_DATABASE` at `meetara_hrms`. Use the database that already belongs to this installation. Do not point a new command at that database in order to rebuild it.

Generate the application key on each machine. Do not copy a key from another environment into the repository.

## Database setup

`config/database.php` sets the `mysql` and `mariadb` schema engine to `InnoDB`. Laravel uses that value when a migration does not set an engine. The MySQL server default on this WAMP machine remains MyISAM. This setting does not change tables that already exist.

The PHP files in `database/migrations` still do not include the six recovered historical payloads or the permission reconstruction. Those files stay under `quarantine/`.

On 21 October 2026 the rehearsal directory was migrated onto a new empty database, `meetara_hrms_stage60_20261021`. All 25 migrations in that directory completed. Every table was InnoDB, and `users_tenant_id_foreign` was present with `ON DELETE SET NULL`. MySQL recorded `ON UPDATE` as `NO ACTION`. Details are in `docs/SUBMISSION_STATUS.md`.

Do not run `php artisan migrate`, `migrate:fresh`, `db:wipe`, or a rollback against `meetara_hrms`. `database/schema/mysql-schema.sql` is the 3 November 2026 schema-only baseline: 163,031 bytes, SHA-256 `565ff15940792a4530297648e3b7106061a76a0e6fd54e2d3721a84580542836`, 124 InnoDB tables, and 49 `migrations` rows. It contains no application data. On a new empty database, `php artisan migrate` loads that file and then has no PHP files left to run. Do not import the file into `meetara_hrms`; it contains `DROP TABLE IF EXISTS`. The seven historical PHP files are still absent and must not be recreated.

`docs/production.md` is the deployment procedure. It does not mean this tree has been deployed.

## Tests and build

Checked on 21 October 2026:

```powershell
vendor/bin/phpunit
npm run build
```

`vendor/bin/phpunit` uses the guarded sqlite `:memory:` suite. Do not use `php artisan test`.

Result: 159 tests, 2334 assertions, 14 skipped, exit 0.

`npm run build` completed with exit 0 (`vite build`, 2044 modules, built in 6.97s). The same command had completed earlier the same day in 8.26s, before the engine setting.

Playwright specs under `tests/Browser` were not run. Their config starts the application against the existing database `meetara_hrms_browser`.

## What works, and what stays blocked

Implemented and covered by the application code: sign-in, company-scoped navigation, employees, attendance, leave, holidays, overtime, salary structures, payroll drafts, help desk, offices, and offer-letter drafts.

Blocked on purpose:

- Payroll Approve and Process stay disabled. Provident fund, ESI, labour welfare fund, TDS, and professional tax have no authorized rule. A zero amount is not an exemption.
- Offer-letter Issue stays unavailable. The preview is a draft. There is no approved template and no verified legal name and address.

## Known limits

- The rehearsal sequence passed on `meetara_hrms_stage60_20261021`. Those migration files are not copied into `database/migrations`. The permission file is a labeled reconstruction, not a recovered original.
- `users_tenant_id_foreign` uses `ON DELETE SET NULL`. MySQL stored the omitted update clause as `NO ACTION`. The Stage 52 candidate still accepts only the spelling `restrict`, and that check was not changed.
- Production settings are not in the local `.env`: debug is on, session encryption is off, and mail is logged.
- The 3 November 2026 schema baseline already records the lifecycle, invitation, and subject-email repair migrations. Do not apply those files again to the live database. The InnoDB setting does not rewrite tables that already exist.

## Demo checklist

Use an account that already exists in the local database. Do not create a new database during the demo.

1. Open the login page and sign in.
2. Confirm the dashboard matches the signed-in role.
3. Open Employees and one employee profile.
4. Open Attendance. Clock-in from this machine can stay blocked unless the request comes from an approved office network.
5. Open Leave and Holidays.
6. Open a payroll run and show that Approve and Process are disabled while statutory setup is unresolved.
7. Open Offer letters and show that Issue is unavailable and the preview is marked as a draft.
8. Sign out.
