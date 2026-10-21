# Stage 53 report

## Summary

The statement `Schema::defaultStringLength(191)` was recovered from a recorded edit to `app/Providers/AppServiceProvider.php` in chat `f0697b32-2cd5-4451-9f11-90180714140d`. The current provider does not contain it, and no surviving file was found. The setting was not restored.

The six payloads contain 91 unqualified `string()` columns. Each one is `varchar(191)` in the schema dump and in the earliest backup. Their indexes and defaults are not all the same. A fresh install is not reproducible.

## Evidence

The transcript `StrReplace` inserts the call into `boot()` after the recorded users-index failure `Specified key was too long; max key length is 1000 bytes`. The tool result says the file was updated. `D:\wamp64\www\VSP-CRM` does not contain this call and is a different project. No Blueprint macro or custom schema grammar was found. No transcript entry removes the line. The current `AppServiceProvider` hash is `f61bf0206c0be1a4b8305ddbc3e0d7da995d21c74a66375ef708a99a34ad78c2`.

## Remediation

Option A, restoring that recorded provider line, is the only option with source evidence. It would make unqualified `string()` columns `varchar(191)` without editing `0001` or the payloads. It was not applied. Explicit lengths and a new helper would be new source. The helper was not created.

## Users candidate and install

The Stage 52 candidate still checks `users_tenant_id_foreign` against `tenants.id`, delete rule `set null`, and update rule `restrict`. A partial or incompatible state fails before DDL. A compatible state makes no change. It is safe enough for a separate review and is not approved for promotion. The update-rule spelling remains inferred until a database is read.

The fresh install stops at `0001_01_01_000000_create_users_table`. Permission tables cannot be created from this repository without treating the external Spatie stub as the missing file, which this stage does not do.

## Checks

`php quarantine/stage53-review/static-string-config-search.php` listed transcript hits and did not open a database.

`php quarantine/stage53-review/static-unqualified-string-inventory.php` counted 91 rows and rejected any row that was not `varchar(191)` in both snapshots. `php -l` was run on both scripts.

PHPUnit was not run.

## Files created

Only under `quarantine/stage53-review/`:

- `static-string-config-search.php`
- `static-unqualified-string-inventory.php`
- `historical-string-config-search.md`
- `unqualified-string-inventory.md`
- `unqualified-string-inventory.csv`
- `string-length-remediation-options.md`
- `0001-schema-discrepancy-review.md`
- `users-column-candidate-review.md`
- `fresh-install-readiness.md`
- `stage53-report.md`

Payloads, the Stage 51 candidate, the Stage 52 candidate, `0001`, `.env`, Composer files, cached configuration, and the schema dump were not changed.

## Databases

No database connection was opened. No database was created or modified.

## Next step

Stop. A later approval can restore the recorded provider line or choose another reconstruction. Until then, do not promote migrations, do not create a database, and do not start a rehearsal.
