# Stage 56 report

Static review only. No active source file was edited. No database connection was opened. No migration command was run. This stage does not start Stage 57.

## 1. Verified current-state hashes

| Item | SHA-256 | Result |
|---|---|---|
| `app/Providers/AppServiceProvider.php` | `3abdb9e69eb13197350466b82ac6fad572b1e90c9fc7e3fdea55d32cebe5461f` | Matches Stage 55 |
| `database/schema/mysql-schema.sql` | `a1f8aa7ed4df6b386068d2d1984d018fd8544f4697f4d76afebc69bec8ae5cbb` | 84,502 bytes. Matches the documented dump fingerprint |
| Stage 52 users candidate | `d32a3c27f6ac7ff37a49c728a6af612f55e34d70bde0b1700cfae5e08af4625b` | 13,808 bytes. Unchanged |
| Stage 51 users candidate | `bc1283747b84e80d5b593fed52b6c8a073a8b389af1fd14606159f729f133d6c` | Unchanged predecessor |
| Six Stage 44 payloads | hashes in `missing-migration-inventory.md` | Match the documented payload hashes |
| VSP-CRM permission stub | `bd097ba10f37a131a9a8885ed4723387521a2f222f636b37b911e7b9ed34eec2` | 6,442 bytes. Matches the recorded stub hash |
| Stage 46 users diff | `9ed7e02748dbbe22ba1b8b9691f3d360cb9353a3f27b993a7179e54d2e762180` | Unchanged |
| Stage 48 `permission.php` candidate | `91a4bfa769fe102220ad07735954c798f6f330fd9a5d6a038c878a8011e3375d` | Unchanged |

`Schema::defaultStringLength(191);` appears once, at line 49, as the first statement in `boot()`. The Schema facade import appears once, at line 30.

Every file in `database/migrations` was re-hashed. The 17 hashes match the hashes recorded in Stages 50–55, including the three stock `0001` files and the fourteen later files. The dump still contains exactly 18 `migrations` rows, ending at `2026_10_19_270000_convert_workflow_tables_to_innodb`.

No discrepancy was found against those recorded values.

## 2. Seven missing migrations

None of the seven original source files is in `database/migrations`. None is a verified original.

| Migration | Status | Reconstruction |
|---|---|---|
| `2026_10_19_023112_create_permission_tables` | Partial evidence only. No Meetara file | Blocked |
| `2026_10_19_100000_create_hrms_tables` | Quarantined payload, `up()` and `down()` present | Requires approval. Not a verified original |
| `2026_10_19_120000_create_remaining_phase_tables` | Same | Requires approval |
| `2026_10_19_130000_add_holiday_calendars` | Same | Requires approval |
| `2026_10_19_140000_add_office_wifi_and_wfh` | Same | Requires approval |
| `2026_10_19_140000_add_payroll_day_counts` | Same | Requires approval |
| `2026_10_19_160000_add_profile_details_and_tickets` | Same | Requires approval |

Detail is in `missing-migration-inventory.md`.

## 3. Permission migration

Meetara `composer.json` and `composer.lock` do not require `spatie/laravel-permission`. The original published migration and the original `config/permission.php` were not recovered.

The VSP-CRM tree still has Spatie 8.3.0, git reference `60e8ed5b2fbf043c2264433fc2680c76b8b66aa6`, and the stub hash above. That is another project's lock file. The schema dump and the earliest backup show the five permission tables with `tenant_id` and index names such as `roles_team_foreign_key_index`. That structure is what the 8.3.0 stub emits when teams are enabled and the team key is `tenant_id`. It does not prove the Meetara file was byte-identical to the stub.

`app/` does not reference `Spatie\Permission`. It does query `roles` and `model_has_roles`.

Recovery status: blocked. Detail is in `permission-migration-evidence.md`.

## 4. Users-column candidate

The Stage 52 candidate is unchanged and remains quarantined. It is not verified and it is not approved.

It adds `tenant_id`, `is_platform_admin`, `invite_token`, `invited_at`, and `invite_accepted_at` in one MySQL `ALTER`. It does not add the foreign key. A partial or incompatible column set throws before that `ALTER`. `down()` throws. `ON UPDATE RESTRICT` is inferred from the dump omitting `ON UPDATE` and from MySQL's default for an omitted clause. It was not read from `information_schema`.

A future disposable rehearsal can be considered only after a separate approval. Detail is in `users-repair-review.md`.

## 5. Fresh-install order and earliest failure

Laravel orders migration files by the full filename. The dump's batches independently record this order for the historical names: `0001` users, cache, jobs, then permission, `100000`, `120000`, `130000`, office wifi, payroll day counts, `160000`, then `200000` through `270000`.

The current tree's earliest divergence is immediately after the three `0001` files. The next ledger name, `2026_10_19_023112_create_permission_tables`, is not a file. `0001` also does not create the five company columns, so `2026_10_19_100000` cannot add `users_tenant_id_foreign` until those columns exist.

Reading the current files shows a second, quieter failure mode. `2026_10_19_200000` through `2026_10_20_120000`, `2026_10_20_160000`, `2026_10_20_180000`, and `2026_10_20_200000` return without DDL when `tenants` and the other historical tables are absent. The invitation migration and the subject-email repair return without throwing when `2026_10_20_120000` is already recorded and `company_lifecycle_events` is absent. That return is not limited to SQLite in the source. This stage did not run migrate, so that path is a source reading, not an observed database result.

A fresh installation is not reproducible. The dependency map is `fresh-install-dependency-map.md`.

## 6. Facts and inferences

Facts established by files read in this stage:

- The provider hash, the one string-length call, the dump hash, the 18 ledger rows, the payload hashes, the candidate hash, and the active migration hashes match the earlier records.
- The seven historical PHP files are absent from `database/migrations`.
- Six quarantined payloads contain both `up()` and `down()`.
- Meetara's Composer files do not require Spatie. The VSP-CRM lock and stub match the previously recorded version, git reference, and hash.
- The Stage 52 candidate throws before DDL on a partial or incompatible users table, refuses non-MySQL, and refuses rollback.

Inferences, which are not approval:

- Snapshot agreement makes the six payloads the best available text for those six names. It does not make them verified originals.
- The permission-table shape is compatible with the 8.3.0 stub plus teams and `tenant_id`. The Meetara migration body is still unknown.
- `ON UPDATE RESTRICT` explains a dump that prints `ON DELETE SET NULL` and omits `ON UPDATE`. The spelling was not queried.
- The guarded later migrations can record themselves on an empty historical schema. That was not executed.

## 7. Decisions that require explicit approval

1. Promote any of the six quarantined payloads into `database/migrations`.
2. Approve the Stage 52 users candidate for a disposable rehearsal. Approval would still not verify it or authorize the live database.
3. Treat the external Spatie stub as a Meetara permission migration. The evidence for that copy is not sufficient.
4. Choose a new disposable database name and run a fresh-install rehearsal.
5. Accept `ON UPDATE RESTRICT` without an `information_schema` reading.

Until those decisions are made, the payloads, the candidate, and the permission stub stay in quarantine.

## 8. Safety confirmation

Created only:

- `quarantine/stage56-review/STAGE56_REPORT.md`
- `quarantine/stage56-review/missing-migration-inventory.md`
- `quarantine/stage56-review/fresh-install-dependency-map.md`
- `quarantine/stage56-review/permission-migration-evidence.md`
- `quarantine/stage56-review/users-repair-review.md`
- `quarantine/stage56-review/reconstruction-plan.md`

`AppServiceProvider.php` was not modified. No migration, schema dump, Composer file, `.env`, cached configuration, quarantined payload, or candidate was modified. No package was installed. No database connection was opened.
