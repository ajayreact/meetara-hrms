# Stage 60 report

The fresh-install rehearsal passed on `meetara_hrms_stage60_20261021`. Production deployment was not verified. The application is not production-ready.

## Cause

Stage 59 failed at `2026_10_20_120000_add_company_lifecycle_events` with SQLSTATE 1824. The server default storage engine is MyISAM. `Schema::create` with no engine followed that default, so `tenants` and `users` were MyISAM. MyISAM discarded `users_tenant_id_foreign`. The lifecycle migration then created an InnoDB table and could not reference `tenants`.

Laravel's MySQL grammar appends `engine = InnoDB` when the blueprint does not set an engine and `config/database.php` does. The schema dump already records these tables as InnoDB. The six payloads and the Stage 52 candidate were not edited.

## Change

`config/database.php` mysql and mariadb `engine` changed from `null` to `InnoDB`. SHA-256 `ba077911bbdce0f813b16c194a497cd9d133c2e3acf24589753a27c13b6792a6`.

This does not change the MySQL server default and does not alter tables that already exist. `meetara_hrms` was not connected to.

Unchanged hashes:

| File | SHA-256 |
|---|---|
| `app/Providers/AppServiceProvider.php` | `3abdb9e69eb13197350466b82ac6fad572b1e90c9fc7e3fdea55d32cebe5461f` |
| Stage 52 users candidate | `d32a3c27f6ac7ff37a49c728a6af612f55e34d70bde0b1700cfae5e08af4625b` |
| Six historical payloads | matched the Stage 59 recorded hashes |
| `database/schema/mysql-schema.sql` | `a1f8aa7ed4df6b386068d2d1984d018fd8544f4697f4d76afebc69bec8ae5cbb` |
| Stock `0001` users migration | `10d79883bc08ce46510f9ad8b1830b4d06d586dc56ae2c8bac3b3e491f69a90f` |

The rehearsal copies under `quarantine/stage59-review/rehearsal-migrations` matched those payload and candidate hashes before the run.

## Database

`meetara_hrms_stage60_20261021` did not exist. It was created empty with `utf8mb4` and `utf8mb4_unicode_ci`. The identity check printed `database=meetara_hrms_stage60_20261021 tables=0 engine=InnoDB`.

Command:

```powershell
php artisan migrate --force --no-interaction --path=quarantine/stage59-review/rehearsal-migrations --schema-path=quarantine/stage60-review/schema-not-used.sql
```

`DB_DATABASE` was set only in that process. The schema-dump path is not a file, so `database/schema/mysql-schema.sql` was not loaded. Exit code 0. Every migration through `2026_10_21_120000_repair_company_lifecycle_subject_email` printed DONE. The ledger has 25 rows.

Read-only check on that database:

- `myisam=0`
- `non_innodb=0`
- `users_tenant_id_foreign` delete `SET NULL`, update `NO ACTION`
- lifecycle, invitations, offer letters, and the repair migration are recorded

`ON UPDATE NO ACTION` is the spelling MySQL stored for the omitted update clause. The Stage 52 candidate still requires `restrict`. It was not changed. It had already completed before `100000` added the foreign key, so this spelling did not fail the run. A later run of that candidate against this foreign key would reject it.

## Tests

`vendor/bin/phpunit`: exit 0, 159 tests, 2334 assertions, 14 skipped, 0 failures. sqlite `:memory:`.

`npm run build`: exit 0, Vite 6.1.1, 2044 modules, 6.97s.

Playwright was not run. It targets `meetara_hrms_browser`.

## Remaining blockers

- The six payloads and the permission reconstruction are not in `database/migrations`.
- The permission file is a reconstruction, not a recovered original.
- The users-candidate update-rule spelling and the observed `NO ACTION` rule differ.
- Payroll statutory decisions and offer-letter legal approval are unresolved.
- Production deployment is not verified.
