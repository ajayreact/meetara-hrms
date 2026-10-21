# Stage 51 report

## Summary

The five users-column SQL definitions are supported by both the schema dump and `meetara_hrms-before-office-networks.sql`. A guarded, forward-only repair candidate is in quarantine. It was not promoted and it was not run.

The six historical payloads still hash to the Stage 50 values. The backup matches the payload shapes. Differences in the schema dump match later on-disk migrations. No payload was verified as the original file.

## Users repair

Candidate: `quarantine/stage51-review/candidates/2026_10_19_010000_add_users_company_columns.php`.

It checks all five columns before DDL. All absent means one MySQL `ALTER TABLE`. All compatible means no DDL. A partial or incompatible set throws before DDL. `down()` throws. The foreign key stays in the `100000` payload because `tenants` does not exist yet.

## Payloads

All six remain `recovered_but_unverified` in `quarantine/stage44-historical-migration-payloads/`. The permission migration remains `partial_evidence_only`. No permission candidate was written.

## Checks

`php quarantine/stage51-review/static-schema-extract.php` read the payload files and the two SQL texts. It reported `hash_match` for all six payloads. It printed the distinguishing column and foreign-key lines from the dump and the backup. It did not open a database.

`php -l` was run on the candidate and on that extractor.

PHPUnit was not run.

## Files created

- `quarantine/stage51-review/static-schema-extract.php`
- `quarantine/stage51-review/candidates/2026_10_19_010000_add_users_company_columns.php`
- `quarantine/stage51-review/users-column-repair-review.md`
- `quarantine/stage51-review/migration-payload-validation.md`
- `quarantine/stage51-review/permission-migration-review.md`
- `quarantine/stage51-review/migration-dependency-matrix.md`
- `quarantine/stage51-review/stage51-report.md`

No file under `database/migrations`, `database/schema`, `config`, or the Stage 44 payload directory was modified.

## Databases

No database connection was opened. No database was created or modified. `.env`, Composer files, cached configuration, the schema dump, and the active migration files were not changed.

## Unresolved

- None of the six payloads, and not the permission migration, is proven byte-for-byte to be the file that was migrated.
- Unqualified `string()` columns are `varchar(191)` in both snapshots. The installed Laravel default string length is 255, and this application does not call `Schema::defaultStringLength(191)`.
- The users snapshots omit `ON UPDATE` for `users_tenant_id_foreign`. The candidate accepts only `RESTRICT` or `NO ACTION`.
- SQLite definitions for the five columns are not in the snapshots, so the candidate refuses every non-MySQL driver.
- The permission stub can explain the tables only together with `teams=true` and `team_foreign_key=tenant_id`. The original published file and the original config file are still missing. Spatie is not a project dependency.
- `120000` updates `plan_feature.enabled`, and its `down()` does not reverse that update.
- Live migration ledger status was not re-read.

## Next step

Wait for explicit approval before copying the users candidate into `database/migrations`, before promoting any historical payload, and before opening a database. A rehearsal also has to decide the `varchar(191)` string length before the six payloads can be treated as reproducible.
