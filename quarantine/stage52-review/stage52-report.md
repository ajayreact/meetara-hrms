# Stage 52 report

## Summary

Unqualified `string()` columns are `varchar(191)` in both the schema dump and the earliest backup. The installed Laravel default is 255, and the application never sets 191. That mapping is unresolved. A snapshot-faithful fresh installation stops before `0001_01_01_000000_create_users_table`.

The Stage 51 users candidate accepts a foreign-key update rule of `NO ACTION`. Neither snapshot records that rule. The Stage 52 revision requires `restrict` and leaves the Stage 51 file unchanged.

The six historical payloads remain quarantined and unverified. The permission migration remains `partial_evidence_only`. No installation path is ready.

## String length

Ninety-one unqualified `string()` calls in the six payloads are `varchar(191)` in both snapshots. Eight explicit lengths match both snapshots exactly. `payroll_runs.status` and `payroll_items.payroll_status` stay `varchar(191)`; `2026_10_19_220000` changes their defaults from `processed` in the backup to `draft` in the dump. The same 191-versus-255 gap is already present in the stock `0001` users, cache, and jobs migrations. This stage did not change the framework default.

## Foreign key and users candidate

`users_tenant_id_foreign` references `tenants.id` with `ON DELETE SET NULL`. Laravel's `nullOnDelete()` does not emit `ON UPDATE`. MySQL's default for an omitted update clause is `RESTRICT`. The revised candidate rejects every other update rule, including `NO ACTION`, and still does not create or drop the key.

The revision is `quarantine/stage52-review/candidates/2026_10_19_010000_add_users_company_columns.php`, SHA-256 `d32a3c27f6ac7ff37a49c728a6af612f55e34d70bde0b1700cfae5e08af4625b`. It can be considered for a future disposable rehearsal of those five columns after explicit approval. It was not promoted.

## Payloads and permission tables

Payload hashes match Stage 50 and Stage 51. The backup assignee foreign key points at `employees`. The dump points at `users` because on-disk `2026_10_19_260000_point_ticket_assignee_at_users.php` retargets it. Those differences are explained by later source files. They do not make the payloads originals.

The Spatie stub remains outside this project. The current application can use `roles` and `model_has_roles` only when those tables already exist. A fresh install cannot create them from this repository without the missing migration, and Spatie was not installed.

## Checks

`php quarantine/stage52-review/static-string-and-constraint-audit.php` compared payload text with the dump and backup. `php -l` reported no syntax errors on that script and on the revised candidate. PHPUnit was not run.

The `0001` users migration hash is still `10d79883bc08ce46510f9ad8b1830b4d06d586dc56ae2c8bac3b3e491f69a90f`. The schema dump hash is still `a1f8aa7ed4df6b386068d2d1984d018fd8544f4697f4d76afebc69bec8ae5cbb`. The Stage 51 candidate hash is still `bc1283747b84e80d5b593fed52b6c8a073a8b389af1fd14606159f729f133d6c`.

## Files created

Only under `quarantine/stage52-review/`:

- `static-string-and-constraint-audit.php`
- `static-string-and-constraint-audit.txt`
- `candidates/2026_10_19_010000_add_users_company_columns.php`
- `string-length-audit.md`
- `foreign-key-audit.md`
- `users-column-candidate-audit.md`
- `historical-payload-audit.md`
- `migration-manifest.md`
- `stage52-report.md`

## Databases

No database connection was opened. No database was created or modified. `.env`, Composer files, cached configuration, the schema dump, active migrations, the six payloads, and the Stage 51 candidate were not changed.

## Unresolved

- The configuration that made unqualified `string()` into `varchar(191)` is not in the current source.
- The six payloads and the permission migration are not proven originals.
- The information_schema spelling of an omitted `ON UPDATE` clause was not read. The revised candidate stops unless that spelling is `restrict`.
- Offer-letter unqualified strings have no snapshot.
- `120000` updates `plan_feature.enabled`, and its `down()` does not reverse that update.
- Live ledger status was not re-read.

## Next step

Wait for an explicit approval before any disposable rehearsal. The only candidate ready to consider in that rehearsal is the Stage 52 users-column file, and only after its filename is approved for `database/migrations`. A rehearsal that must match the snapshots still cannot pass `0001` until the `varchar(191)` mapping is recovered or separately specified. Do not promote payloads and do not install Spatie in that step.
