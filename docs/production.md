# Production deployment

This file is the procedure for a future public host. It does not change the local WAMP installation. The local `.env` stays on `APP_ENV=local`, and this document does not switch that installation to production mode.

The application is not production-ready until the checks at the end of this file have been run on the real host. HTTPS has not been tested on a deployed endpoint.

## Separate the two environments

`.env.example` describes the local WAMP setup. Copying it to a public host and changing only `APP_ENV` leaves debug output on, leaves the log level at `debug`, leaves mail in the log, and can leave session encryption off.

`DeploymentSecurity` turns on session encryption, the Secure cookie, and HTTPS redirects only when `APP_ENV` is `production` and the matching override is empty. An explicit `SESSION_ENCRYPT=false` or `SESSION_SECURE_COOKIE=false` wins over that default. The local `.env` sets `SESSION_ENCRYPT=false`. Do not copy that line to the public host.

Generate a new `APP_KEY` on the host. Do not reuse the local key.

`APP_ENV` must be the string `production` on a staging host as well. `DeploymentSecurity` does not treat `staging` as production, so session encryption, the Secure cookie, and the HTTPS redirect stay off when that value is `staging` or `local`.

## Database baseline

The migration files in this repository do not rebuild the current schema. The live `migrations` table records these seven names, in this order, and none of those PHP files is in `database/migrations`, in `D:\wamp64\backups`, or in any other project file:

1. `2026_10_19_023112_create_permission_tables` (batch 1)
2. `2026_10_19_100000_create_hrms_tables` (batch 1)
3. `2026_10_19_120000_create_remaining_phase_tables` (batch 2)
4. `2026_10_19_130000_add_holiday_calendars` (batch 3)
5. `2026_10_19_140000_add_office_wifi_and_wfh` (batch 4)
6. `2026_10_19_140000_add_payroll_day_counts` (batch 5)
7. `2026_10_19_160000_add_profile_details_and_tickets` (batch 6)

Their combined effect is present in the current schema and in every backup from `meetara_hrms-before-office-networks.sql` onward. That earliest backup already contains all seven names and stops before `2026_10_19_200000_add_office_networks_and_attendance_breaks`. There is no dump from before the first of the seven, so the individual table, column, index, and data change of each file cannot be separated. Their `down()` methods are not in the repository. Do not roll one of them back, and do not recreate the missing PHP files from memory or from truncated chat history.

The files that do exist, `0001_01_01_000000_create_users_table` through `0001_01_01_000002_create_jobs_table` and `2026_10_19_200000_add_office_networks_and_attendance_breaks` through `2026_10_19_270000_convert_workflow_tables_to_innodb`, stay as they are. Do not delete or rename them to make `migrate:status` look clean. Do not run `php artisan schema:dump --prune`; that option deletes the migration directory.

### Current schema baseline

On 3 November 2026 the reviewed schema-only file replaced the 18-row dump. `database/schema/mysql-schema.sql` is 163,031 bytes and its SHA-256 is `565ff15940792a4530297648e3b7106061a76a0e6fd54e2d3721a84580542836`. It creates 124 InnoDB tables and inserts the 49 migration names recorded on the verified `meetara_hrms` catalog, including the seven names whose PHP files are absent. The only data statements are those `migrations` rows. It has no company, user, employee, payroll, or I-983 rows. `App\Support\MigrationInventory` reads that file and `database/migrations` without opening a database. The dump records 49 migration names. `2026_11_03_220000_add_company_legal_name`, `2026_11_04_030000_add_organization_catalog_status`, `2026_11_04_040000_add_i983_compensation_and_site_arrangement`, `2026_11_04_050000_reassign_accounts_only_users_to_owner`, and `2026_11_04_060000_add_i983_employer_profiles` are not among those names. A fresh `php artisan migrate` loads the dump and then runs those files. The catalog file adds `is_active` to `departments` and `designations`. The I-983 file adds nullable `hourly_rate` and `annual_compensation` on `i983_employers`, and nullable `work_arrangement` on `i983_training_plans`. Existing rows stay unchanged. `2026_11_04_050000_reassign_accounts_only_users_to_owner` moves users whose only company role is `Accounts` to `Owner` and writes a `role_assignment_events` row with action `accounts_to_owner`. It does not delete the `Accounts` role row, payroll rows, or users who also have another role. On 4 November 2026 it was applied to `veltrix_hrms` with `--path` after the Accounts-only count was 0. That name is recorded and must not be applied again. `down()` restores only users still holding `Owner` because of that event. The USA offer and I-983 deletion release adds no migration. `2026_11_04_060000_add_i983_employer_profiles` creates an empty `i983_employer_profiles` table, one row per company. It does not copy or change existing `i983_employers` rows, and it is not yet recorded on `veltrix_hrms`.

### New installation

Create an empty MySQL database whose name is not `meetara_hrms`. Copy `.env.example` to `.env` in the application root, generate a new `APP_KEY`, and set `DB_DATABASE` to that empty name. `$APP_ROOT` is that root. Run every `php artisan` command there. From that directory, confirm MySQL selected `$DB_DATABASE`:

```bash
cd "$APP_ROOT"
mysql --batch --skip-column-names -e "SELECT DATABASE();" "$DB_DATABASE"
```

Stop unless the only output line equals `$DB_DATABASE`. Then, still in `$APP_ROOT`, run `php artisan migrate`. Laravel loads `database/schema/mysql-schema.sql` only when no migration is recorded. That load inserts the 49 migration names in the dump. The same command then runs `2026_11_03_220000_add_company_legal_name`, which adds nullable `tenants.legal_name`, `2026_11_04_030000_add_organization_catalog_status`, which adds `is_active` to `departments` and `designations`, `2026_11_04_040000_add_i983_compensation_and_site_arrangement`, which adds the nullable I-983 compensation and training-site columns, `2026_11_04_050000_reassign_accounts_only_users_to_owner`, which moves Accounts-only users to Owner, and `2026_11_04_060000_add_i983_employer_profiles`, which creates an empty company employer profile table. An empty database has no such users. Run `php artisan migrate` again and confirm it reports nothing to migrate. The database must still have no company or user rows. Copy the official I-983 PDF to `storage/app/private/i983/official-i983.pdf`; that file stays outside Git. It is 119,842 bytes and its SHA-256 is `c1fffede432651cf1d6b5c9a0647d0aad96754b28ae9b40d5fd2d6d8ca0d7202`. Create the Platform Super Admin on the host, then create companies from that account. Do not import a data dump, and do not point this command at `meetara_hrms`.

Do not import `database/schema/mysql-schema.sql` into `meetara_hrms`. The file contains `DROP TABLE IF EXISTS`. Do not run `migrate:fresh`, `db:wipe`, or `migrate:rollback`.

Earlier rehearsals on `meetara_hrms_baseline_20261020` and `meetara_hrms_stage27_20261020` ran `php artisan migrate --force` when no later file existed. Those rehearsals are history. `meetara_hrms_baseline_20261020` has the schema only: 0 users and 0 employees. It is not a recovery copy.

### Existing database

`meetara_hrms` already recorded the 49 names in the schema dump. `php artisan migrate` does not reload the schema file once any migration is recorded. Do not run a migrate command against `meetara_hrms` as part of the production release. Do not import the schema file into `meetara_hrms`. Do not insert rows into `migrations` by hand, and do not recreate the seven missing historical files.

The live production database is `veltrix_hrms`. It already has companies, users, offers, and I-983 rows, so it is not a fresh install. A plain `php artisan migrate` does not reload `database/schema/mysql-schema.sql` once any migration is recorded. After a backup, and only after `SELECT DATABASE()` prints `veltrix_hrms`, apply the unrecorded files one at a time:

`php artisan migrate --force --path=database/migrations/2026_11_04_030000_add_organization_catalog_status.php`

`php artisan migrate --force --path=database/migrations/2026_11_04_040000_add_i983_compensation_and_site_arrangement.php`

The first file adds `is_active` to `departments` and `designations`. The second adds nullable `i983_employers.hourly_rate`, `i983_employers.annual_compensation`, and `i983_training_plans.work_arrangement`. Neither file updates existing salary, offer, payroll, user, or stored PDF rows. Skip a file whose name is already in `migrations`. `2026_11_04_050000_reassign_accounts_only_users_to_owner` is already recorded on `veltrix_hrms` and must not be applied again. The deletion and quarantine recovery release adds no migration file. `2026_11_04_060000_add_i983_employer_profiles` creates the empty company employer profile table and does not update existing I-983 rows. Apply it with `--path` only when that name is absent from `migrations`. Run `php artisan migrate --force` only to confirm that nothing remains, and stop unless it reports nothing to migrate. Do not run `migrate:fresh`, `db:wipe`, or `migrate:rollback`.

The October notes below describe the ledger before this baseline. They are not the install procedure. On that older reading the ledger had 21 migration rows, and `2026_10_20_120000_add_company_lifecycle_events` and `2026_10_20_140000_add_company_admin_invitations` were still unrecorded. Both names are now included in the 49-row baseline. Do not apply them again.

`2026_10_20_120000_add_company_lifecycle_events` must run before `2026_10_20_140000_add_company_admin_invitations`. When `tenants` and `users` exist, the invitation file throws, and is not recorded, unless `company_lifecycle_events` exists. It adds nullable `subject_email` when that column is missing, then creates `company_admin_invitations` only after the column is present. If `subject_email` is still absent, it throws before creating the invitation table. If `tenants` or `users` is missing and `2026_10_20_120000_add_company_lifecycle_events` is not already recorded, it throws before any invitation DDL. The standard SQLite suite has no `tenants` table, because seven historical files are missing. There `120000` is recorded as a no-op, and this file then returns without creating `company_admin_invitations`. That return is not a completed invitation schema. MySQL loaded from the schema dump already has `tenants` and `users`, so that return does not apply. MySQL commits each DDL statement on its own, so a failure after `subject_email` is added can leave the column without a migration row. Read the ledger and the column. An exit code of 0 is not enough. A thrown migration must be investigated before the next file is applied. Neither file is approved for `meetara_hrms`.

### Schema-change preflight

Use this sequence for every schema change. Stop at the first failed check.

1. Name the target database in the process environment. The name for a rehearsal must not be `meetara_hrms`.
2. Read that database's `migrations` rows on a copy when the target is the live database. Compare them with `MigrationInventory` and with this document. The current consistent ledger is the 49 names in `database/schema/mysql-schema.sql`. The older 18-row dump and 21-row reading are history. A ledger that matches neither the current file nor a written exception is inconsistent. Do not migrate it until the difference is written down.
3. List the files on disk that are absent from that ledger. Read each `up()` and `down()`.
4. Reject a plan that recreates one of the seven missing historical files, or that inserts a migration row by hand.
5. Reject `2026_10_20_140000_add_company_admin_invitations` when `company_lifecycle_events` is absent, unless `2026_10_20_120000_add_company_lifecycle_events` is approved first and has been applied.
6. Take the backup in the restore runbook and record its checksum.
7. Apply one approved file with `php artisan migrate --force --path=database/migrations/<approved-file>.php`. Confirm the connected database name before the command.
8. Record the command, the exit code, and the new migration row. Confirm `company_lifecycle_events.subject_email` in the database catalog when the invitation file was applied. An exit code of 0 does not replace that check. If the command throws, stop and inspect the schema before applying another file. Do not delete the migration row and do not rerun a file that is already recorded.

On 21 October 2026 the previous invitation file was rehearsed on new databases `meetara_hrms_stage41a_20261021`, `meetara_hrms_stage41b_20261021`, and `meetara_hrms_stage41c_20261021`. Those names were absent before the rehearsal. Each was loaded from `database/schema/mysql-schema.sql`. `meetara_hrms` was not used. Lifecycle first, then invitation, left `subject_email` on the first two databases. The old invitation file, run first on `meetara_hrms_stage41c_20261021`, recorded itself without `subject_email`. That database was left inconsistent. The current invitation file throws instead of recording that gap. It does not repair a migration row that is already stored. Neither file is approved for `meetara_hrms`. `down()` was not run: the lifecycle `down()` drops the event table, and the invitation `down()` drops the invitation table and `subject_email`.

A database that already has `2026_10_20_140000_add_company_admin_invitations` recorded and is missing `subject_email` needs the separate reviewed repair migration `2026_10_21_120000_repair_company_lifecycle_subject_email`. The inconsistent state is: `company_lifecycle_events` exists, `company_admin_invitations` exists, the invitation migration row is already stored, and `company_lifecycle_events.subject_email` is absent. `meetara_hrms_stage41c_20261021` was left in that state by the old invitation file. This stage does not connect to that database and does not apply the repair there.

Before applying the repair to any database, read both tables in the catalog, read `company_lifecycle_events` columns, and read the `migrations` rows for `2026_10_20_140000_add_company_admin_invitations` and for the repair file. The repair must be explicitly approved for that database. The repair is not approved for `meetara_hrms`. Back up the database and copy `storage/app/private` before the command. Apply one file with `php artisan migrate --force --path=database/migrations/2026_10_21_120000_repair_company_lifecycle_subject_email.php` only after the connected database name is confirmed. Afterward, confirm `subject_email` is nullable `varchar(191)`, the lifecycle and invitation row counts are unchanged, and the existing invitation migration row is still present. Laravel records the repair name when `up()` returns. The repair does not delete or rewrite ledger rows. MySQL commits the column add on its own, so a thrown command can leave the column without the repair row. Read the column before another attempt. Do not use `migrate:fresh`, `db:wipe`, `migrate:rollback`, or a hand-written `migrations` change as recovery. The seven historical migration files are still missing, so a fresh install from the PHP files alone is still blocked. If `subject_email` already exists as nullable `varchar(191)`, the repair leaves it. If the type, length, or nullability differs, the repair throws and does not change the column. SQLite stores the column as `varchar` and cannot prove the length 191. MySQL 8.4 reports `varchar(191)`.

On 21 October 2026 the fail-fast invitation file was checked on MySQL 8.4.7 using two new databases, `meetara_hrms_stage41b_valid_20261021` and `meetara_hrms_stage41b_invalid_20261021`. Both names were absent before the check. Each was created empty and loaded from `database/schema/mysql-schema.sql` (18 migration rows). `meetara_hrms` and the earlier Stage 41 databases were not used. On the valid database, lifecycle then invitation left both rows recorded, `subject_email` as nullable `varchar(191)`, and both tables InnoDB. On the invalid database, invitation first threw, stayed unrecorded, and created no invitation table and no `subject_email` column. A separate fault check on that same disposable database, after the lifecycle table existed, forced the column check to fail after the alter. MySQL left nullable `subject_email` `varchar(191)` in place, left the invitation migration unrecorded, and left `company_admin_invitations` absent. The same `--path` command then completed: one `subject_email` column, the invitation table, and the migration row. Neither file is approved for `meetara_hrms`.

### Read-only inspection script

`scripts/inspect-migration-state.php` prepares a later inspection. It does not bootstrap Laravel and it does not call migration methods. The operator must pass the expected database name. The only accepted shape is `meetara_hrms_inspect_` plus an eight-digit date. `meetara_hrms`, `meetara_hrms_stage41c_20261021`, the Stage 36–41 rehearsal and browser databases, and the other known disposable names are refused before any connection. `DB_URL`, `DATABASE_URL`, a cached `bootstrap/cache/config.php`, SQLite, and any non-MySQL driver are refused. `DB_DATABASE` is ignored as an authorization: if it is set and differs from the argument, the script stops. Without `--read` the script opens no connection. With `--read`, the first statement is `SELECT DATABASE()`. Metadata queries run only after that value matches the argument. The trace printed by the script is the list of statement templates `MigrationInspection::read()` actually sent to its reader. Unit tests pass a recording reader, so `SELECT DATABASE()` is proven to be first and an identity mismatch returns before any metadata or ledger statement. That test does not open PDO or MySQL. The trace redacts any URL or password text. It does not print parameter values, credentials, or table contents. After the identity check the report can include the server version, migration names, batches, and count, the presence and engine of `tenants`, `users`, `company_lifecycle_events`, `company_admin_invitations`, and `migrations`, the lifecycle columns `id`, `tenant_id`, `actor_id`, `action`, and `created_at`, and `subject_email` type, length, nullability, default, and collation. Invitation indexes and foreign keys are included when that table exists. A missing table or column is reported as absent. A nullable `varchar(191)` `subject_email` with no default, including a null `COLUMN_DEFAULT`, is `repair_unnecessary`. information_schema uses null both for an omitted default and for an explicit `DEFAULT NULL`, so those two shapes are classified the same way. A non-NULL default is `unsafe_incomplete`, and the report prints that default with the reason. An incompatible type, length, or nullability is also `unsafe_incomplete`. `repair_necessary` applies only when both company tables and the required lifecycle columns exist and `subject_email` is absent. The classification is informational. `verification_does_not_authorize_migrations=1` means the report does not approve the lifecycle, invitation, or repair migrations. Stage 41E did not open a database, and this reporting change does not open one either. The seven historical migration files are still missing, so a fresh install from the PHP files alone is still blocked.

Do not run `migrate:fresh`, `db:wipe`, or `migrate:rollback` as deployment or recovery. Do not delete the `migrations` table and then migrate: the dump contains `DROP TABLE IF EXISTS`, and Laravel loads it when no migration has been recorded. Do not import `database/schema/mysql-schema.sql` into `meetara_hrms`, `meetara_hrms_restore_20261020`, or `meetara_hrms_integrity_20261020`.

After a later migration is applied to a verified database, regenerate the schema file with `php artisan schema:dump` and without `--prune`, from that database, and ship the new file in the same release as the new migration. Check the new file with the rules below before replacing the old one. Do not replace the current dump until that review exists. `MigrationInventory` checks the current file's size and SHA-256.

### Source and integrity checks

Before trusting a baseline file:

- The dump command exits 0 and the file hash and size match the release.
- The file has no `USE`, `CREATE DATABASE`, `DROP DATABASE`, `GRANT`, `DEFINER`, or `INTO OUTFILE`.
- The only data statements are `INSERT` rows for `migrations`.
- After loading it into a new database, table count, column count, index rows, foreign keys, and migration names match the source. On 3 November 2026 the match against `meetara_hrms` was 124 tables, 1,368 columns, 521 index rows, 214 foreign keys, and the same 49 migration names and batches. The 20 October 2026 check of the retired 18-row dump was 75 tables, 657 columns, 278 index rows, 116 foreign keys, and 18 migration names.
- All 12 workflow tables are InnoDB: `payroll_payments`, `payroll_events`, `salary_structure_events`, `leave_request_events`, `overtime_events`, `ticket_comments`, `ticket_events`, `company_payroll_settings`, `company_payroll_setting_events`, `role_assignment_events`, `attendance_breaks`, and `location_networks`. `payroll_payments_payroll_item_id_unique` is still unique. `tickets.assignee_id` still references `users`.

### Backup and restore runbook

Use a new database name for every restore drill. Do not restore a dump onto `meetara_hrms`, and do not point a drill at the live private-file directory.

Before an approved schema change, with maintenance mode already on:

1. Write a timestamped logical dump. Record the command, the exit status, the byte size, the `Dump completed` line, and the SHA-256 checksum.
2. Copy `storage/app/private` in the same window. That directory holds employee documents and generated offer PDFs. A SQL dump does not contain those files. Record the file count and a checksum for the archive.
3. Restore the SQL dump with `--one-database` into a new database name. Restore the private-file archive into a separate directory, not over the live `storage/app/private` tree.
4. Compare table counts, migration names and batches, and the business row counts named in the change record. Compare the restored file count with the archive. Stop when any count differs.
5. Keep the dump and the file archive off the database host. A dump stored only beside MySQL is not a backup.

The schema file `database/schema/mysql-schema.sql` is not a backup of company data. Restoring service means restoring a data dump and the private-file archive together.

When the migration history is incomplete, do not roll forward or roll back to "catch up". The seven missing historical files have no `down()` method in this repository. `migrate:rollback` on `2026_10_19_260000_point_ticket_assignee_at_users` or `2026_10_19_270000_convert_workflow_tables_to_innodb` is not an incident response. Escalate by restoring the last verified dump and private-file archive into a new database and a new directory, then deploy the application revision that matches that backup. Leave the database under diagnosis in place until that comparison is written down.

### Logo packaging

`npm run build` does not publish the logo. The release must include `public/images/logo/veltrix-logo.png`. `HrmsPageController::logo` serves that file at `GET /brand/veltrix-logo.png` (`brand.logo`). `GET /brand/meetara-logo.png` serves the same file so an older link still loads. Do not put the logo into the Vite build. The previous Meetara image remains in `images/logo/meetara-logo.png` and is not deleted.

`composer.json` allows PHP `^8.2`. The suite and local server run on PHP 8.3.28. Use PHP 8.3 on Ubuntu 24.04. `package.json` does not set `engines`. GitHub Actions installs Node.js 22, and the local lockfile was built with Node.js 24.19.0 and npm 11.17.0. Install Node.js 22 on the VPS before `npm ci`. The Ubuntu 24.04 Node package is older than that CI version. `storage/` and `bootstrap/cache/` must be writable by the PHP user and not writable by the public. On this WAMP install those two directories accepted a write probe, which was removed. That does not prove a remote host's permissions.

This baseline was checked on the local WAMP MySQL instance. It does not make this application ready for a staging or production host. The local server default engine is still MyISAM, so a later `CREATE TABLE` on this server can still be MyISAM unless that statement sets `ENGINE=InnoDB`. `REFERENCES` for the migration account was not independently re-tested.

The fresh-database check above was made before any later migration file existed. Rollback of that check was not run. The seven missing files have no `down()` method. The files that do exist drop tables or columns, and `2026_10_19_270000_convert_workflow_tables_to_innodb` would convert the workflow tables back to MyISAM.

`2026_10_20_120000_add_company_lifecycle_events` is a later file. It creates only `company_lifecycle_events` (`tenant_id`, `actor_id`, `action`, `created_at`) with `ENGINE=InnoDB` when `tenants` and `users` already exist. It does not change `tenants`, users, employees, attendance, leave, payroll, or payments. `down()` drops that one table. The 3 November 2026 schema dump records this migration. A new empty database loads that dump and does not run this file again. Regenerate `database/schema/mysql-schema.sql` only after a later migration has been applied on a verified copy, with `php artisan schema:dump` and without `--prune`, and review the new file before replacing this one.

`2026_10_20_140000_add_company_admin_invitations` creates `company_admin_invitations` with `ENGINE=InnoDB` only after `company_lifecycle_events.subject_email` is present. It adds that nullable varchar(191) column when the lifecycle table exists and the column does not. It throws, and stays unrecorded, when `company_lifecycle_events` is missing, when `subject_email` is still absent after that add, and when `tenants` or `users` is missing unless `2026_10_20_120000_add_company_lifecycle_events` was already recorded without creating that table. That last return is the incomplete SQLite history in the preflight. It does not create the invitation table. The invitation table stores a SHA-256 hash of a single-use token, the company, the invited email, the inviter, the fixed Company Admin role name `Owner`, expiry, sent, accepted, and revoked timestamps. It does not alter `users.invite_token`. That older column remains for historical employee login rows and is not used by this invitation. `down()` drops `company_admin_invitations` and the `subject_email` column. It does not delete users, employees, or company rows created before the rollback. An accepted Company Admin account therefore remains after `down()`. Apply both files only after a reviewed backup, lifecycle first. They are not approved for `meetara_hrms`. SQLite tests cover the order and the refusal. MySQL 8.4.7 confirmed the same refusal, and confirmed that DDL commits each statement: a failure after the `subject_email` alter can leave that column without a migration row. The following `--path` run is safe when the column is already the nullable `varchar(191)` and `company_admin_invitations` is absent. See the 21 October 2026 MySQL check in the schema-change preflight.

`2026_10_20_160000_extend_employee_onboarding_documents` adds nullable `onboardings.welcomed_at`, nullable `onboarding_tasks.code` and `completed_by`, document review columns, and `employee_record_events` when the parent tables already exist. It also adds `onboardings_employee_id_unique` when that index is absent and no employee has two onboarding rows. That unique index is not in the pre-migration schema. The existing employee index is the non-unique foreign key `onboardings_employee_id_foreign`. `down()` now drops `onboardings_employee_id_unique` as well as `offer_letters` is dropped by `2026_10_20_180000_add_offer_letters`. `2026_10_20_180000_add_offer_letters` creates `offer_letters` with `ENGINE=InnoDB`. Neither file changes users, employees, attendance, leave, salary, payroll, or payments.

A read-only check on 20 October 2026, before either file was applied, recorded 18 migration rows, 1 company, 5 users, 4 employees, 0 documents, 1 onboarding, 4 onboarding tasks, and no offer-letter table. The onboarding row status was `active`, and all 4 tasks were `pending`. There were no duplicate onboarding employees. The task titles are "Share personal documents", "Introduce the team", "Issue laptop and access", and "Walk through company policies". None of those titles matches one required checklist item, so the application keeps each row, leaves it pending, and does not treat it as PAN, Aadhaar, bank proof, resume, or a signed offer. Opening onboarding adds the seven coded tasks once. Opening it again does not add them again. The four historical rows do not block completion of the seven required tasks. Manual follow-up is still required for those four rows: an authorized reviewer decides whether each operational task is done. "Share personal documents" is not evidence that a specific identity or bank document was verified.

On 20 October 2026, `2026_10_20_120000_add_company_lifecycle_events` and `2026_10_20_140000_add_company_admin_invitations` were still unrecorded. A plain `php artisan migrate` at that time would have applied those two files before these two. Both names are recorded in the 3 November 2026 baseline, so a current empty-database migrate does not run them again. Do not insert rows into `migrations` by hand.

The pre-migration backup is `D:\wamp64\backups\meetara_hrms-before-onboarding-offer-20261020121740.sql`. It is 125,729 bytes, SHA-256 `A8BBE002C9FB4408CB213D318BD786C2573AD6F7665321709EE598930B203893`, ends with `Dump completed`, and contains no `CREATE DATABASE` or `USE` statement. It was restored with `--one-database` into `meetara_hrms_preflight_20261020180530`. The original snapshot had 18 migration rows and no `onboardings_employee_id_unique` index. The two onboarding and offer-letter files were applied with `--path`, in filename order. The earlier two files stayed unrecorded. Rollback with `--step=1` twice, offer letters first, restored a schema snapshot identical to the original, including the absence of that unique index. Reapplying the same two `--path` commands reproduced the applied snapshot. Business row counts were unchanged through that cycle. A workflow pass on that disposable database then opened the existing checklist without duplicating required tasks, created one new employee with seven coded tasks, reviewed one private resume, and issued one offer letter whose issued text stayed unchanged. Cross-company requests returned 404. No email was sent. At the end of that rehearsal, `meetara_hrms` still had 18 migration rows. That count was the pre-apply baseline, not the current database.

These two files were applied to `meetara_hrms` on 20 October 2026 after a separate approval. Maintenance mode was on. The commands were:

`php artisan migrate --force --path=database/migrations/2026_10_20_160000_extend_employee_onboarding_documents.php`

`php artisan migrate --force --path=database/migrations/2026_10_20_180000_add_offer_letters.php`

Both commands exited 0. Each migration name is recorded once. `2026_10_20_120000_add_company_lifecycle_events` and `2026_10_20_140000_add_company_admin_invitations` remain unrecorded. The migration table grew from 18 rows to 20. `onboardings`, `onboarding_tasks`, `employee_documents`, `employee_record_events`, and `offer_letters` are InnoDB. `onboardings_employee_id_unique` is present. Offer-letter notice months default to 3.

The fresh backup taken immediately before this run is `D:\wamp64\backups\meetara_hrms-before-live-onboarding-offer-20261020131745.sql`. It is 125,729 bytes, SHA-256 `95452F837BA9EE6113D5624CED4EE1713FF19E6452FCA27DD358CB307E657A02`, ends with `Dump completed`, and contains no `CREATE DATABASE` or `USE` statement. The earlier rehearsal backup was left in place.

Before the migration, counts were 1 company, 5 users, 4 employees, 0 documents, 1 onboarding, 4 tasks, 1 attendance log, 1 leave request, 0 salary structures, 0 payroll runs, 0 payroll items, 0 payroll payments, and 8 notifications. Those business counts were unchanged by the schema change. Verification then opened the existing onboarding once. That application step added the seven required tasks, so the checklist now has 11 tasks. The four historical tasks are still pending and uncoded. Reopening did not add tasks or welcome notifications. The first open raised notifications from 8 to 11 and recorded one welcome event. No email was sent, and no offer letter was issued. Company, user, employee, document, salary, payroll, payment, and role-assignment counts stayed at the baseline. Maintenance mode was then disabled. `/up` returned 200, `/login` returned 200, the logo returned the 42,032-byte file, and an unauthenticated `/dashboard` redirected to `/login`. The HR employee page reports onboarding storage ready with 11 tasks and 4 historical notes. The offer-letter page reports storage ready and zero letters.

The appointment-letter wording is still a placeholder. Issuance stays blocked until an approved template and verified company legal details are on file. A draft preview is not an issued offer letter. Payroll statutory rates and joining-date proration remain unconfigured. Approval and processing now stay blocked until statutory readiness is proven. A zero deduction is not that proof.

## Offer letter issuance gate

`OfferLetters::issue()` calls `OfferLetterReadiness` inside the same transaction, after the letter is confirmed open and previewed, and before any file is stored or the status becomes `issued`. The check does not read the request body. Fields that try to mark a template approved or a company address verified are rejected. Calculation of a draft and a preview remain available. The preview text starts with `DRAFT — NOT APPROVED FOR ISSUANCE` and still contains the placeholder lines. The offer-letter screen hides the issue action and shows **Issuance blocked — template approval required**.

`offer_letters` stores the draft, the preview, and, for a letter that is already issued, an immutable `issued_body`. It does not store template approval. `tenants` stores a display name and an optional address. Neither field is a verified legal name or legal address. No migration in this change adds approval or verification columns. A later workflow needs, for each company, an approved template version, the template body, the approving user, and the approval time, plus a verified legal name, legal address, verifying user, and verification time. A typed address is not that verification. HR and Company Admin are not treated as a legal review. This gate does not make the HRMS legally compliant.

The file write remains inside the issuance transaction, after the readiness check. This stage cannot reach that write, because approval cannot be proven. A letter that is already issued is unchanged by a new draft version. A second issue request does not add another offer document or onboarding completion.

## Payroll statutory gate

Approval and processing call `PayrollStatutoryReadiness` inside the payroll transaction. The check is not the warning text saved during calculation. Review can still move a calculated run to under review, and that status is not approval. `process()` returns without new payments when a run is already processed. Every other processing attempt, including a run whose stored warnings were cleared, is rejected while readiness is unresolved.

`company_payroll_settings` stores a currency default and an audit row for that currency change. It does not store Provident Fund, Employee State Insurance, Labour Welfare Fund, Tax Deducted at Source, or professional tax. It also does not store an authorized statement that one of those items does not apply. A missing row, a null value, or a calculated zero is `not_configured`. No migration in this change adds rate columns, because a safe rule needs a qualified review of the rate, the employee group it covers, the actor, and the time of the decision. A single company toggle would not prove that every employee is exempt.

Until that reviewed design exists, the gate stays closed. Payroll calculation can still produce a draft. The payroll screen lists each unresolved category and disables approval and processing. The server rejects those actions even if the buttons are bypassed. This is not a statement that the HRMS is legally compliant.

On 20 October 2026 the offer-letter screen was changed so HR and Company Admin choose an employee from their own company. `offer_letters.employee_id` already existed, so this did not add a migration. The server copies the name from that employee and rejects an employee id from another company. `tenant_id` remains prohibited on the request. Issuance is refused until template approval and verified company legal details exist, so a new letter does not store a generated offer document and does not complete the `offer_issued` task. Signed offer, PAN, Aadhaar, bank proof, and resume stay pending. A historical task without a code stays pending and is not treated as one of the seven required tasks. Preview stays marked as a draft. A failed issue does not issue the letter or complete the task. A second issue request does not add another document, task, or welcome notification. A new version does not change an issued snapshot. This does not make the letters legally compliant.

The same two approved files were applied with `--path`, in filename order, to `meetara_hrms_browser` so browser tests could use the offer-letter tables. `2026_10_20_120000_add_company_lifecycle_events` and `2026_10_20_140000_add_company_admin_invitations` stayed unrecorded there. `meetara_hrms` was not migrated again. A later read of both databases showed 20 migration rows, one row each for the onboarding and offer-letter files, and no rows for the company-lifecycle or Company Admin invitation files. That warning applied on 20 October 2026. Both files are recorded in the 3 November 2026 baseline, so do not apply them again and do not run `migrate:fresh`.

With `DB_DATABASE` unset, `php artisan test --filter=OfferLetterTest` passed 4 tests and 158 assertions. `php artisan test` then passed 110 tests and 1836 assertions, and skipped 10. The skipped tests were not executed: `CompanyAdminInvitationMysqlTest` requires `meetara_hrms_invite_20261020`, `OfferLetterMysqlTest` requires `meetara_hrms_offer_20261020`, `OnboardingPreflightMysqlTest` requires `meetara_hrms_preflight_20261020180530`, `PaymentConcurrencyTest` requires `meetara_hrms_integrity_20261020`, and the six `Stage28MysqlWorkflowTest` methods require `meetara_hrms_stage28_20261020`. None of those tests is guarded for `meetara_hrms_browser`, so they were not pointed at it. `npm run build` completed. The first Playwright run passed 6 tests, failed the new offer-letter test because the status locator also matched the notice text, and did not run the mobile test. After that locator was limited to the status badge, `tests/Browser/prepare.php` was run only after it printed `database=meetara_hrms_browser`, and Playwright passed 8 tests.

`2026_10_20_200000_add_employee_record_event_note` adds one nullable `note` text column to `employee_record_events`. On 20 October 2026 it was applied to `meetara_hrms` with `php artisan migrate --database=mysql --path=database/migrations/2026_10_20_200000_add_employee_record_event_note.php --force`. The command exited 0. The migration count went from 20 to 21, and this file is recorded once. `employee_record_events.note` is nullable text. On that date a plain `php artisan migrate` would still have applied the then-unrecorded company-lifecycle and Company Admin invitation files. Both are recorded in the 3 November 2026 baseline. Do not apply them again. Company Admin and HR can record a decision on an uncoded historical task for an employee in their own company. The outcomes are `completed`, `not_applicable`, and `pending` (shown as Still open). Completed and Not applicable require a note. Still open accepts an empty note. The task title, task code, and `description` stay as they are. The current outcome is stored in `status`. `completed_by` and `completed_at` are set only when that outcome is `completed`. `pending` and `not_applicable` clear both fields. Each save appends an `employee_record_events` row with action `historical_decision`, a short subject such as `t12:pending`, and the full note in `note`. Earlier event rows stay. These decisions do not change the seven required checklist tasks or their completion result. Team Lead can read historical tasks for an authorized direct report and cannot record a decision. Employee, Accounts, and Super Admin cannot record one. Until the column exists, the employee page says decision storage is not ready and does not show the decision form.

The migration was rehearsed on `meetara_hrms_decision_20261020`, restored from a read-only copy of the current `meetara_hrms` schema. `tests/Feature/HistoricalDecisionMigrationTest.php` applied the file with `--path`, rolled it back with `--step=1`, and applied it again. The task descriptions, task count, event count, and offer-letter count stayed the same. `offer_letters` and `onboardings.welcomed_at` remained after rollback. The lifecycle and invitation migrations stayed unrecorded. With `DB_DATABASE` unset, `php artisan test --filter=HistoricalOnboardingDecisionTest` passed 3 tests and 59 assertions. `php artisan test` then passed 113 tests and 1,895 assertions, and skipped 11. The extra skip is `HistoricalDecisionMigrationTest`, which runs only on `meetara_hrms_decision_20261020`; that run passed with 12 assertions. `npm run build` completed. Playwright was not run for this change. Before the live apply, `meetara_hrms` had 20 migration rows, no `note` column, no `historical_decision` events, and the same four pending historical tasks with their descriptions present.

The fresh backup taken immediately before the live apply is `D:\wamp64\backups\meetara_hrms-before-decision-note-live-202610201505.sql`. It is 135,416 bytes, SHA-256 `a0029cb020c39f42fca2b4f632000cd118351762fdc52a8dad7381eff7528c61`, ends with `Dump completed`, and contains no `CREATE DATABASE` or `USE` statement. It was restored into `meetara_hrms_notecheck_20261020` and matched the live counts and historical-task fingerprint. After the single `--path` migration, `meetara_hrms` had 21 migration rows, one nullable `note` column, no `historical_decision` events, and the same four pending historical tasks. Event, offer-letter, employee, user, and task counts were unchanged. `2026_10_20_120000_add_company_lifecycle_events` and `2026_10_20_140000_add_company_admin_invitations` stayed unrecorded, and their tables were not created. No decision was saved for the four live historical tasks. This migration does not make the application ready for production.

Company Admin invitation links expire after 168 hours (`config/hrms.php`). Acceptance requires the company to be `active`. Deactivation blocks acceptance. Reactivation does not restore an expired or revoked invitation. Mail must be configured as described above before an invitation is created. The acceptance page is public, uses the existing CSRF protection, and logs the new Company Admin in only after the account, company binding, and `Owner` role are saved in one transaction. Raw tokens are not written to audit rows or normal log messages. Production HTTPS comes from `APP_URL` and `RequireProductionHttps` when `APP_ENV=production`.

The PHPUnit suite uses `RefreshDatabase`, which runs `migrate:fresh`. Do not point that suite at a database created from this baseline. The missing files cannot rebuild the schema, and `migrate:fresh` would drop it. The sqlite suite does not prove the MySQL baseline.

On the empty `meetara_hrms_stage27_20261020` database, with the process environment pointed at that name, these application checks passed: `GET /up` returned 200, `GET /` redirected to `/login`, the login page rendered, an unauthenticated `GET /dashboard` redirected to login, and `GET /brand/meetara-logo.png` returned the 42,032-byte PNG. A failed login attempt did not create a user. No company, employee, attendance, leave, payroll, or payment rows were created. Signed-in workflows and browser tests were not run against that database.

## Route Map and GPS retention

Two migrations support Route Map: `2026_10_21_150000_add_office_location_verification` (office latitude, longitude, verification radius, and the GPS verification flag on `locations`), then `2026_10_22_120000_add_route_map_gps_capture` (`locations.map_label`, the company GPS setting and its audit rows, employee acknowledgements, clock-in and clock-out location records, view audit rows, and retention audit rows). On 22 October 2026 both were rehearsed in order on the new empty database `meetara_hrms_routemap_20261022`, using `quarantine/stage59-review/rehearsal-migrations` plus the two files with `--path` and a schema path that does not exist. The run exited 0, every table is InnoDB, and a second run reported nothing to migrate. The same two files were then applied with `--path`, in that order, to `meetara_hrms`. Do not roll them back there. Rollback was exercised on sqlite only. `down()` drops the GPS tables and every stored location, so do not roll back a database that holds records you need. The 3 November 2026 schema baseline already records both files. A new empty database loads that baseline and does not run them again. Do not run `php artisan migrate` against the local `meetara_hrms` database to finish this feature.

GPS capture is off for every company until a Company Admin turns it on in Company settings. It is requested only during clock-in or clock-out, only after the employee acknowledges the notice on Route Map, and only after the existing network check has passed. It does not replace the approved public-network check, and a browser location can be inaccurate or spoofed. The current notice version is `2026-10-22.1` plus the retention period. Changing the notice text or the retention period requires a new acknowledgement.

Saved attendance coordinates are rounded to five decimal places, about 1.1 meters, before they are written. The column remains `decimal(10,7)`. Existing rows are not rewritten, and no migration was added for this rounding. The office-radius check uses the browser reading before rounding, so the 25-meter minimum radius is unchanged. Office coordinates stay at the precision that was saved for the geofence center. Denied, unavailable, and invalid captures still store no coordinates.

Precise locations are kept for `hrms.gps_location_retention_days`, 30 days by default, set through `HRMS_GPS_RETENTION_DAYS`. This default needs company and legal review before capture is enabled. Values outside 1 to 3650 stop collection and stop the cleanup without deleting anything. The acknowledged notice version includes the retention period, so a changed period requires a new acknowledgement. `php artisan hrms:prune-gps-locations` deletes expired `attendance_location_records` one company at a time. The cutoff is the application clock minus that many days. The clock is `config('app.timezone')` (`APP_TIMEZONE`, default `UTC`), not the office timezone. A record captured at 09:00 expires 30 times 24 hours later, not at local midnight. The command writes one `attendance_location_retention_events` row per company with the count and cutoff only. It does not delete attendance logs, acknowledgements, view events, or retention events. `--dry-run` counts without deleting. A second run deletes nothing and adds no audit row. An invalid retention value exits 1 and deletes nothing. The output names the timezone and the counts. It does not print coordinates.

The command is registered daily at 00:00 in the application timezone, with `withoutOverlapping`. Registration is not the same as execution. Production must run `php artisan schedule:run` every minute from cron. No queue worker is required for this command. This repository cannot confirm that a production scheduler is running. Do not run the command against `meetara_hrms` to test deletion.

A view of coordinates writes one `attendance_location_view_events` row for that company and actor. The existing `subject` column is 40 characters, so the row stores a compact label rather than a new set of columns: `s`, `t`, or `c` for self, team, or company; `e{id}` when one employee's coordinates were returned; `n{count}` when more than one employee was returned; and the requested dates as `YYYYMMDD`. The row is written only when coordinates were actually returned. It does not store latitude, longitude, or the request body. Ordinary users have no screen or route that edits these rows. Listing every employee id for a wide company view would need a new column or child table. That migration was not added.

Route Map and the office picker load tiles from `config('hrms.map_tiles.url_template')`, which is `https://tile.openstreetmap.org/{z}/{x}/{y}.png`. There is no API key and no environment override. Each tile request tells OpenStreetMap the area being viewed and the visitor IP address. The image request uses `referrerpolicy=no-referrer`, so the HRMS address, employee id, and date filters are not sent in the Referer header. The map states this disclosure. If tiles cannot be loaded, the page says so and the List view still works. Addresses are not geocoded. The OpenStreetMap Foundation tile usage policy (https://operations.osmfoundation.org/policies/tiles/) requires visible attribution, forbids heavy or bulk use of the public servers, and asks production applications to publish their own tiles or use another provider. Replacing the template is a code change. The replacement must be an `https` URL ending in `{z}/{x}/{y}.png`. An unsafe value is ignored and the OpenStreetMap template is used. A new provider still receives the viewed area. Do not add one until its usage policy and privacy terms are approved.

## Automated test layers

`phpunit.xml` is the only configuration for the standard suite. It forces `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:` with PHPUnit's `force="true"` attribute, and it sets the same values in `$_SERVER`. `tests/bootstrap.php` runs after that configuration and before any test. It clears `DB_URL` and `DATABASE_URL`, pins sqlite `:memory:` in `$_ENV`, `$_SERVER`, and `putenv`, and stops the process if cached configuration is present or the effective values are anything else. `Tests\TestCase::createApplication()` repeats that check before the Laravel application boots and again before `RefreshDatabase` can run. Laravel reads `$_SERVER` before `.env`, so a shell value such as `DB_CONNECTION=mysql` or `DB_DATABASE=meetara_hrms` cannot replace the standard suite's database. A database URL is rejected because Laravel lets it override the database name when a connection is opened.

Do not run `migrate:fresh` against the active application database. Feature tests use `RefreshDatabase`, which runs `migrate:fresh`. The seven missing historical migration files cannot rebuild the current schema. The guard exists so that command cannot reach `meetara_hrms` through the standard suite. If the guard fails, unset `DB_URL` and `DATABASE_URL`, run `php artisan optimize:clear` when it reports cached configuration, and run `vendor/bin/phpunit`. Do not remove the guard, do not pass `--no-configuration`, and do not point the suite at MySQL to make the failure go away.

The standard command is `vendor/bin/phpunit`. `php artisan test` uses the same `phpunit.xml`. Both stay on sqlite `:memory:`.

MySQL-specific tests are not part of that forced sqlite path. `phpunit.mysql.xml` is a separate configuration. Its bootstrap, `tests/bootstrap.mysql.php`, refuses to start unless `DB_CONNECTION=mysql` and `DB_DATABASE` is one of the explicit names in `Tests\Support\TestDatabaseGuard::MYSQL_ALLOWLIST`. `meetara_hrms` is always rejected, including if it is later added to that list. A database URL is rejected. The configuration does not include the Feature tests that use `RefreshDatabase`. Run it only with a shell that already points `DB_DATABASE` at the disposable database required by the test you intend to run, for example `meetara_hrms_stage37_20261020` for `HistoricalDecisionMysqlTest`. On the standard suite those classes skip without opening MySQL. Do not run `phpunit.mysql.xml` against `meetara_hrms`.

Playwright does not use sqlite. `playwright.config.ts` uses the explicit database `meetara_hrms_browser`. `playwright.stage28.config.ts` uses `meetara_hrms_stage28_20261020`. `playwright.stage36.config.ts`, `playwright.stage37.config.ts`, and `playwright.stage38.config.ts` use `meetara_hrms_stage36_browser_20261020`, `meetara_hrms_stage37_browser_20261020`, and `meetara_hrms_stage38_browser_20261020`. `tests/Browser/prepare.php` and the browser seed scripts refuse `meetara_hrms` before they bootstrap the application, and they also refuse a database URL. They then require the configured name to be on their own explicit allowlist. The standard PHPUnit command does not run Playwright.

`tests/Feature/PaymentConcurrencyTest.php` runs only on `meetara_hrms_integrity_20261020`. `tests/Feature/Stage28MysqlWorkflowTest.php` runs only on `meetara_hrms_stage28_20261020` and does not use `RefreshDatabase`. `tests/Feature/CompanyAdminInvitationMysqlTest.php` runs only on `meetara_hrms_invite_20261020`. `tests/Feature/OfferLetterMysqlTest.php` runs only on `meetara_hrms_offer_20261020`. `tests/Feature/OnboardingPreflightMysqlTest.php` runs only on `meetara_hrms_preflight_20261020180530`. `tests/Feature/HistoricalDecisionMigrationTest.php` runs only on `meetara_hrms_decision_20261020`. `tests/Feature/HistoricalDecisionMysqlTest.php` runs only on `meetara_hrms_stage37_20261020`. Any other configured MySQL name fails those tests closed.

## Production environment values

Set these on the host. Do not invent a domain, proxy address, password, or database account in the repository.

| Setting | Production value | Intended behavior |
| --- | --- | --- |
| `APP_ENV` | `production` | Turns on HTTPS redirects, session encryption, and the Secure cookie when the session overrides below are empty. `config/app.php` defaults this to `production` only when the variable is absent. |
| `APP_DEBUG` | `false` | Hides exception details. `config/app.php` defaults to `false` when the variable is absent. The local example sets `true`. |
| `APP_URL` | The real HTTPS origin, with no trailing path | Used for generated URLs, including password-reset links. |
| `APP_KEY` | Generated on the host with `php artisan key:generate` | Encrypts sessions and other ciphertext. Do not reuse the local key. |
| `SESSION_ENCRYPT` | Unset | `DeploymentSecurity` encrypts sessions in production. An explicit `false` wins, including a copied local value. |
| `SESSION_SECURE_COOKIE` | Unset | The session cookie is marked Secure in production. An explicit `false` wins. |
| `SESSION_HTTP_ONLY` | `true` | JavaScript cannot read the session cookie. The config default is already `true`. |
| `SESSION_SAME_SITE` | `lax` | A top-level visit from a password-reset message keeps the cookie. `strict` drops it. `none` is not appropriate. |
| `LOG_LEVEL` | `warning` or `error` | `config/logging.php` defaults to `debug` when this is absent. |
| `LOG_STACK` | `daily` | Selects the daily channel, which keeps 14 files unless `LOG_DAILY_DAYS` is set. The local example uses `single`. |
| `TRUSTED_PROXIES` | Empty when the client connects directly. Otherwise the specific proxy addresses, never `*` | Stored in `config/attendance.php` and applied after configuration loads. Empty and `*` trust nobody. |
| `DB_CONNECTION` | `mysql` | The application default in `config/database.php` is `sqlite` when this is absent. |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE` | The production MySQL host, port, and schema | Point at the production schema, not a local or drill database. |
| `DB_USERNAME`, `DB_PASSWORD` | The runtime account from the next section | Normal web requests use this account. Keep the password out of git and logs. |
| `MAIL_MAILER` | A real transport such as `smtp`, `sendmail`, `ses`, `ses-v2`, `postmark`, `resend`, or `mailgun` | `config/mail.php` defaults to `log`. Password reset and Company Admin invitations send mail synchronously and are not queued. `log` and the example sender `hello@example.com` do not count as delivery. Set the host, port, encryption, username, password, and a real `MAIL_FROM_ADDRESS`. `APP_URL` must be the HTTPS origin so the invitation link is usable. Invitation mail is synchronous because a queue worker is not part of the verified local setup; do not queue it, because the jobs table would store the message. |
| `FILESYSTEM_DISK` | `local` | The local disk root is `storage/app/private`. Employee documents and offer PDFs stay there. Do not switch this to the public disk. A SQL backup does not include these files. |
| `.env` | Present only on the host | `.gitignore` excludes `.env`, `.env.backup`, and `.env.production`. Do not commit them. Generate `APP_KEY` on the host. |

`SESSION_SAME_SITE=lax` allows a top-level visit from a password-reset message to keep the cookie. `strict` drops that cookie. `none` requires a Secure cookie and is not appropriate for this application.

`TRUSTED_PROXIES` is stored by `config/attendance.php` and applied in `AppServiceProvider` after configuration loads. An empty value and `*` both produce an empty trusted list, so forwarded headers are ignored. List a proxy address only when that proxy is the one terminating TLS in front of PHP. `config:cache` keeps this value because it is read from configuration, not from `env()` while the HTTP kernel is constructed.

## Database account and storage engine

Do this on the future host, or on an isolated copy. Do not change the local administrator account or the local server variable `default_storage_engine`.

Use two accounts. Store both passwords in the host secret store. Neither password belongs in git, in `.env.example`, or in a log.

The runtime account is the one named by `DB_USERNAME`. It needs `SELECT`, `INSERT`, `UPDATE`, and `DELETE` on the application schema. Sessions, cache, and the unused queue tables use those statements. Row locks from `SELECT ... FOR UPDATE` use the same `SELECT` privilege on InnoDB. It does not need `CREATE`, `ALTER`, `DROP`, `INDEX`, `REFERENCES`, `FILE`, `PROCESS`, `SUPER`, `GRANT OPTION`, or `ALL PRIVILEGES`.

The migration account is used only by the deployment command. Add `CREATE`, `ALTER`, `DROP`, `INDEX`, `REFERENCES`, and `CREATE TEMPORARY TABLES` on that schema. Remove it from the running application configuration after the migration window.

Replace the placeholders below. `<isolated-database>` must be a new database name, not `meetara_hrms`, `meetara_hrms_restore_20261020`, or `meetara_hrms_integrity_20261020`.

```sql
CREATE DATABASE `<isolated-database>` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER '<runtime-user>'@'<app-host>' IDENTIFIED BY '<runtime-password>';
GRANT SELECT, INSERT, UPDATE, DELETE ON `<isolated-database>`.* TO '<runtime-user>'@'<app-host>';

CREATE USER '<migration-user>'@'<deploy-host>' IDENTIFIED BY '<migration-password>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES, CREATE TEMPORARY TABLES
    ON `<isolated-database>`.* TO '<migration-user>'@'<deploy-host>';
```

Privilege status from the 20 October 2026 check on the removed schema-only copy `meetara_hrms_privilege_20261020`. No accounts were left on the server. Repeat the check with the production host's own accounts before cutover.

| Privilege | Runtime account | Migration account |
| --- | --- | --- |
| `SELECT`, `INSERT`, `UPDATE`, `DELETE` | Verified. A session row was inserted, updated, locked with `SELECT ... FOR UPDATE`, and deleted. | Included in the grant. The migration command read and updated the `migrations` table. |
| `CREATE` | Verified denied. `CREATE TABLE` was rejected. | Not independently re-tested. The engine migration uses `ALTER`, not `CREATE`. |
| `ALTER` | Not granted. | Verified. `2026_10_19_270000_convert_workflow_tables_to_innodb` changed `payroll_payments` from MyISAM to InnoDB. |
| `DROP`, `INDEX`, `CREATE TEMPORARY TABLES` | Not granted. | Not independently re-tested. |
| `REFERENCES` | Not granted. | Not independently re-tested. `2026_10_19_260000_point_ticket_assignee_at_users` was already recorded on that copy, so its foreign-key change did not run again. |

`meetara_hrms` stayed at 18 migrations during that check.

Test the runtime account before pointing the application at it:

1. Restore a reviewed backup into `<isolated-database>` with `--one-database`, or apply the reviewed migrations there with the migration account.
2. In a shell that does not write `.env`, set `DB_CONNECTION=mysql`, `DB_DATABASE=<isolated-database>`, `DB_USERNAME=<runtime-user>`, and `DB_PASSWORD` for one process.
3. Run a read, such as `php artisan db:show`, and a session write through an isolated HTTP check.
4. Confirm `CREATE TABLE` fails for the runtime account.
5. Unset those variables before any later command.

On the future MySQL server, set the default engine before creating any later table:

```sql
SET PERSIST default_storage_engine = InnoDB;
CREATE TABLE `<isolated-database>`.`engine_probe` (id bigint unsigned NOT NULL PRIMARY KEY);
SELECT ENGINE FROM information_schema.tables
    WHERE table_schema = '<isolated-database>' AND table_name = 'engine_probe';
DROP TABLE `<isolated-database>`.`engine_probe`;
```

The probe result must be `InnoDB`. The local WAMP server still defaults to MyISAM. The 12 workflow tables already on `meetara_hrms` are InnoDB; a new table created on this WAMP server can still be MyISAM until that server default changes.

## Deploy

These steps are for Ubuntu 24.04 with PHP 8.3, MySQL 8, Node.js 22, and Nginx. `$APP_ROOT` is the release directory, for example `/var/www/veltrix-hrms`. The Git repository name stays `meetara-hrms`. Run every `php artisan` command only after `cd "$APP_ROOT"`. Keep the site private until the smoke checks pass.

1. Install PHP 8.3, Composer, Node, MySQL 8, and Nginx. Point the Nginx `root` at `$APP_ROOT/public` and use `try_files $uri $uri/ /index.php?$query_string;` with PHP 8.3-FPM. Do not deploy `public/hot`.
2. From `$APP_ROOT`, install locked PHP dependencies: `composer install --no-dev --optimize-autoloader`.
3. From `$APP_ROOT`, install locked Node dependencies and build assets: `npm ci` and `npm run build`.
4. Write the production environment in `$APP_ROOT/.env`. Confirm `APP_ENV=production`, `APP_DEBUG=false`, the HTTPS `APP_URL`, an empty session-encryption override, and the database account for this step.
5. Test that account against an isolated database, as above.
6. From `$APP_ROOT`, stop writes with `php artisan down`. The maintenance driver is the file driver unless the host sets another `APP_MAINTENANCE_DRIVER`. `GET /up` stays available because the health route is excluded from maintenance mode.
7. Back up the target database and `storage/app/private` while maintenance mode is on. Check the dump exit status, file size, and the `Dump completed` line. Record both SHA-256 checksums. Restore the SQL dump with `--one-database` into a new database name and compare table counts, migration rows, and key business counts. Restore the private archive into a separate directory and compare the file count, including the official I-983 PDF. Keep earlier backups off the database host. Stop the deployment if a copy does not match.
8. Choose one database path. Do not run `migrate:fresh`, `db:wipe`, `migrate:rollback`, or a seeder. Do not import `database/schema/mysql-schema.sql` into a database that already has tables or rows. Before either path, from `$APP_ROOT`, confirm the selected database:

```bash
cd "$APP_ROOT"
mysql --batch --skip-column-names -e "SELECT DATABASE();" "$DB_DATABASE"
```

Stop unless the only output line equals `$DB_DATABASE`.

- Fresh database: `$DB_DATABASE` is a new empty database, not `meetara_hrms` and not the live `veltrix_hrms`. From `$APP_ROOT`, run `php artisan migrate --force` with the migration account. Laravel loads `database/schema/mysql-schema.sql` because no migration is recorded. That load inserts the 49 names in the dump, including the seven historical names whose PHP files are absent. The same command then runs `2026_11_03_220000_add_company_legal_name`, `2026_11_04_030000_add_organization_catalog_status`, `2026_11_04_040000_add_i983_compensation_and_site_arrangement`, `2026_11_04_050000_reassign_accounts_only_users_to_owner`, and `2026_11_04_060000_add_i983_employer_profiles`. Run `php artisan migrate --force` again and confirm it reports nothing to migrate. Confirm `tenants` and `users` are empty. Switch `.env` to the runtime account, which has only `SELECT`, `INSERT`, `UPDATE`, and `DELETE`.
- Existing database: `veltrix_hrms` is the live database. Read its `migrations` rows before any migrate command. If the 49 dump names are present, `php artisan migrate` from `$APP_ROOT` does not reload the schema. Apply only the absent files, in order, with `--path`: `2026_11_03_220000_add_company_legal_name` when that name is absent, then `2026_11_04_030000_add_organization_catalog_status`, then `2026_11_04_040000_add_i983_compensation_and_site_arrangement`, then `2026_11_04_060000_add_i983_employer_profiles`. Each of those files adds a table or nullable or defaulted columns and does not rewrite existing I-983 rows. `2026_11_04_050000_reassign_accounts_only_users_to_owner` is already recorded on `veltrix_hrms`. The deletion release adds no migration file. If a name is already recorded, skip that file. If the 49 names do not match, stop. Do not load the schema file. Do not copy local company or user rows into `veltrix_hrms`.

9. Place the official I-983 PDF at `$APP_ROOT/storage/app/private/i983/official-i983.pdf`. It is 119,842 bytes and its SHA-256 is `c1fffede432651cf1d6b5c9a0647d0aad96754b28ae9b40d5fd2d6d8ca0d7202`. Copy any existing private archive into `$APP_ROOT/storage/app/private` without replacing that PDF with a different file. The PHP user must own `storage/` and `bootstrap/cache/`, and the web server must not be able to list those directories as public URLs.
10. After the environment values are confirmed, from `$APP_ROOT` run `php artisan config:cache`, `php artisan route:cache`, and `php artisan view:cache`. Do this only on the host. A cache built from the local `.env` would freeze `APP_ENV=local`, debug output, and `SESSION_ENCRYPT=false`. A cache built from the wrong environment must be removed with `php artisan optimize:clear` from `$APP_ROOT` before the corrected values are cached. HTTP route actions are controllers, redirects, or Inertia's controller, so `route:cache` can serialize them. Group closures and the `inspire` console command are not HTTP actions. Do not leave a route or config cache on the local WAMP install.
11. Check `GET /up` for HTTP 200, sign in with a non-production account if one exists on that host, load the logo and built assets, confirm logs exclude secret values, and confirm HTTPS as described below.
12. From `$APP_ROOT`, run `php artisan up` only after those checks pass. No queue worker is required: application mail is sent synchronously and `app/` does not dispatch jobs. `routes/console.php` registers `hrms:prune-gps-locations` daily at 00:00 in `APP_TIMEZONE`, with `withoutOverlapping`. Run this cron every minute, from the application root:

```cron
* * * * * cd /var/www/veltrix-hrms && php artisan schedule:run >> /dev/null 2>&1
```

Replace `/var/www/veltrix-hrms` with the real `$APP_ROOT`. Cron does not expand that variable.

The Composer `dev` script is not the production process. When Nginx terminates TLS, set `TRUSTED_PROXIES=127.0.0.1` before `config:cache`. A wildcard is discarded.

## Veltrix production host

The production application URL is `https://hrms.veltrixds.com`. The primary domain is `veltrixds.com`. Set `APP_NAME="Veltrix HRMS"` and `APP_URL=https://hrms.veltrixds.com`. Leave the local application on `http://127.0.0.1:8000` until this deployment is approved.

Nginx uses `server_name hrms.veltrixds.com;` and `root $APP_ROOT/public;`. The site block needs a certificate for that exact name. Certbot can request it with `certbot --nginx -d hrms.veltrixds.com` after the DNS A record points at the host. Do not change DNS from this repository. Redirect port 80 to HTTPS. The PHP location passes the request to PHP 8.3-FPM, and `try_files $uri $uri/ /index.php?$query_string;` stays in the HTTPS server. Do not deploy `public/hot`.

Trusted hosts are the host from `APP_URL`, `localhost`, and `127.0.0.1`. Subdomains of `veltrixds.com` are not trusted automatically. There is no separate CORS configuration: the browser application is same-origin. Authentication and password-reset links are generated from `APP_URL`. Leave `SESSION_DOMAIN` empty so the session cookie is sent only to `hrms.veltrixds.com`. Do not set it to `.veltrixds.com`. When Nginx terminates TLS, set `TRUSTED_PROXIES=127.0.0.1` before `config:cache`.

Mail from-addresses must be verified on `veltrixds.com` before `MAIL_FROM_ADDRESS` is set. The fresh production database is `veltrix_hrms`. Do not rename or import `meetara_hrms`. The GitHub repository stays `meetara-hrms`.

## HTTPS and proxies

`RequireProductionHttps` forces the HTTPS scheme and redirects an insecure request only when `APP_ENV` is `production`. That check uses the cached `app.env` value. Local HTTP on port 8000 stays available. Empty `TRUSTED_PROXIES` means PHP trusts the socket address, not `X-Forwarded-Proto`. Behind a TLS-terminating proxy, set `TRUSTED_PROXIES` to that proxy's address before `config:cache`, so the Secure cookie and the redirect see the original HTTPS request. A wildcard is discarded.

On the deployed origin, these checks are still required:

- `https://<origin>/up` returns HTTP 200.
- `http://<origin>/login` redirects to HTTPS.
- The session cookie is `Secure` and `HttpOnly`, with `SameSite=Lax`.
- A request that did not come from the listed proxy cannot change the client address through `X-Forwarded-For`.

Until those requests are made against the real host, HTTPS is unverified.

## Mistaken USA offer and I-983 deletion

This release adds no migration and no scheduled job. Do not run `php artisan migrate`, `migrate:fresh`, `db:wipe`, or `migrate:rollback` to enable deletion. The buttons appear only after this revision is deployed and `npm run build` has been run on the host. This section does not authorize that deploy.

Only Head of Operations (`company_admin`) can delete a USA offer or an I-983 case. The buttons are on that company's USA offer list and I-983 list. Each confirmation names the record. HR can open both lists and receives HTTP 403 from `DELETE /usa-offers/{offer}` (`hrms.usa-offers.destroy`) and `DELETE /i983/{case}` (`hrms.i983.destroy`). A team lead, an employee, and a user with no company also receive 403. A record that belongs to another company, or a second delete of the same record, returns 404.

Deletion moves the selected private files into `storage/app/private/quarantine/{32-hex}/` before the database transaction. The transaction then removes the record. For an I-983 case it first removes that company's rows in `i983_student_certifications`, `i983_documents`, `i983_certifications`, `i983_corrections`, `i983_evaluations`, `i983_field_revisions`, `i983_employers`, `i983_students`, `i983_training_plans`, `i983_links`, and `i983_events`, then deletes the case and writes one new `i983_events` row: `action` is `case_deleted`, `subject` is the case reference, `case_id` is null, `actor_id` is the Head of Operations user, and `actor_type` is `hr`. USA offers have no deletion audit table. After the transaction commits, the quarantine copies are deleted. A failed move or a failed database transaction restores the files and leaves the record in place. The list then shows "The USA offer was not deleted. Its PDF is unchanged." or "The I-983 case was not deleted. Its stored files are unchanged." A failed final cleanup leaves the database delete in place and leaves the copies in quarantine. It does not put the files back.

A USA offer delete moves only `usa-offers/{companyId}/{offerId}.pdf`, and only when `usa_offers.file_path` is exactly that path. A path that points at stationery is ignored. An I-983 delete moves only files under `i983/{companyId}/{caseId}/`. Letterhead, the company signature, signatory images, `i983/official-i983.pdf`, and another company's files stay where they are.

If the process stops after the move and before the database commit, the record remains and its file is only in quarantine. If it stops after the commit and before the quarantine copies are removed, the record is gone and the copies remain. Neither case is cleaned by file age. Do not delete `storage/app/private/quarantine` by hand because the files look old.

### Manual quarantine recovery

Run recovery only for one known company, only after this revision is deployed, and only from `$APP_ROOT`. It is not a route and it is not on the scheduler. Copy `storage/app/private` first, including `quarantine/`, and record the archive checksum. Confirm the connected database before the command:

```bash
cd "$APP_ROOT"
mysql --batch --skip-column-names -e "SELECT DATABASE();" "$DB_DATABASE"
```

Stop unless the only output line is the intended database. Read that company's `tenants.id`. Replace `COMPANY_ID` below with that integer and with no other text:

```bash
php artisan tinker --execute='app(\App\Services\Hrms\StoredFileQuarantine::class)->recover(COMPANY_ID);'
```

Recovery reads only `quarantine/{32-hex}/` directories. For each file it uses the original path stored under that directory. A USA file is eligible only when that path is exactly `usa-offers/{companyId}/{offerId}.pdf`. An I-983 file is eligible only when it is under `i983/{companyId}/{caseId}/`. Any other path, including letterhead, a signature, and `i983/official-i983.pdf`, stays in quarantine and a warning is logged. A file whose company id is not `COMPANY_ID` is not moved, restored, or deleted.

When the matching `usa_offers` or `i983_cases` row still exists for that company, recovery moves the quarantine file back to its original path. When that row is gone, recovery deletes the quarantine copy and does not create a live file. When the original path already contains a file, recovery leaves both files in place and does not overwrite the live file. When the move fails, the database row and the quarantine copy stay. A missing quarantine file does not create a live PDF. If `usa_offers` or `i983_cases` cannot be read, the file stays in quarantine. Running the same command again is safe: completed files are already gone, and a conflict or a failure leaves the same quarantine file in place.

Success is logged at `info` as "Quarantine file was restored." or "Quarantine file was removed because its record no longer exists." A file left in place or a failed move or delete is logged at `warning`. Production `LOG_LEVEL=warning` or `error` hides the `info` lines. List `storage/app/private/quarantine` before and after the command. Do not treat a silent run as proof that no file was recovered, and do not copy a quarantine file over a live PDF by hand.

## Rollback

Keep the previous release on disk. Roll back code and database together to a pair that was deployed as a unit.

The current application expects `tickets.assignee_id` to reference `users`, and it expects the payroll, leave, overtime, ticket, and audit tables to be InnoDB so row locks and transaction rollback hold. `2026_10_19_260000_point_ticket_assignee_at_users` rewrites assignee ids when tickets exist. After it has stored user ids, `migrate:rollback` on that file is unsafe unless every assignee maps back to exactly one employee in the same company. `2026_10_19_270000_convert_workflow_tables_to_innodb` can convert empty, foreign-key-free tables back to MyISAM, which removes the row-lock behavior the payment code relies on. Do not use that `down()` as an incident response.

When a deployment must be undone:

1. Leave maintenance mode on. From `$APP_ROOT`, that is `php artisan down`.
2. Restore the last verified SQL backup into a new database name. Do not restore it over the database that is still being diagnosed.
3. Restore the matching `storage/app/private` archive into the rollback release, not over the private directory still being diagnosed. Confirm `official-i983.pdf` is 119,842 bytes and its SHA-256 is `c1fffede432651cf1d6b5c9a0647d0aad96754b28ae9b40d5fd2d6d8ca0d7202`.
4. Deploy the application revision that matches that backup's schema. The revision before `4316a86` is `cadda91`.
5. Point `DB_DATABASE` at the restored database. From `$APP_ROOT`, confirm `mysql --batch --skip-column-names -e "SELECT DATABASE();" "$DB_DATABASE"` prints that name, then rebuild the config cache.
6. Repeat the smoke checks, then open traffic with `php artisan up` from `$APP_ROOT`.

The pre-migration backup `D:\wamp64\backups\meetara_hrms-before-live-migration-20261020.sql` matches the schema from before migrations `260000` and `270000`. Restoring it requires the application revision from before those migrations. Changes made after that dump are absent from that file.

## Observability and recovery

These controls are not configured on the local installation. Documenting them does not mean they are running.

| Control | Current local state | Production requirement |
| --- | --- | --- |
| Application log | `LOG_STACK=single`, `LOG_LEVEL=debug`, one growing `storage/logs/laravel.log` | `LOG_STACK=daily` and `LOG_LEVEL=warning` or `error`. The daily channel keeps 14 files unless `LOG_DAILY_DAYS` is set. Ship files off the host or add a host log agent. |
| Error monitoring | No external monitor is configured | Send warning and error events to a monitor. Alert on repeated HTTP 500 responses. |
| Queue failures | No application job is dispatched. `failed_jobs` has no alert | When a job is added, run a worker and alert when `failed_jobs` gains rows. |
| Health check | `GET /up` exists and is reachable locally | Poll it from outside the host. Alert when it stops returning HTTP 200. |
| Backups | Manual SQL dumps in `D:\wamp64\backups`. No schedule, no private-file archive, and no retention job | Take a daily logical backup and a copy of `storage/app/private`. Record the exit status, size, and SHA-256. Keep both off the database host. |
| Restore drill | The stage 18 and stage 21 restores were manual | Restore the newest backup into a new database name, and the private-file archive into a separate directory, at least once a quarter. Compare table counts, migration rows, business counts, and file counts. Do not restore the drill onto the live database or the live private-file directory. |

Do not log passwords, session payloads, or `APP_KEY`. A recorded payment stores its reference in the payroll audit note, so shipped logs and audit exports must treat that note as sensitive.

## Host verification checklist

Run this on the real host after TLS, mail, and the dedicated database accounts exist. None of these host checks have been run. Items marked local were checked on this WAMP machine without changing `meetara_hrms` or the local `.env`.

| Check | How to verify | Result required | Status |
| --- | --- | --- | --- |
| TLS certificate | From outside the host, request `https://<origin>/up` and inspect the certificate name and expiry | HTTP 200 and a certificate that matches the origin | UNVERIFIED — HOST REQUIRED |
| HTTP redirect | Request `http://<origin>/login` | Redirect to the HTTPS login URL | UNVERIFIED — HOST REQUIRED. Local HTTP is intentional and returns the login page |
| Secure cookie | Sign in over HTTPS and inspect the session cookie in the browser | `Secure`, `HttpOnly`, and `SameSite=Lax` | UNVERIFIED — HOST REQUIRED |
| Proxy | With `TRUSTED_PROXIES` set to the real proxy, confirm `X-Forwarded-Proto: https` is honored only from that proxy | Direct clients cannot spoof HTTPS or their address | UNVERIFIED — HOST REQUIRED. The local code path rejects an empty list and `*` |
| External `/up` polling | Poll `GET /up` from outside the host, including during `php artisan down` | HTTP 200 both times, and an alert when it is not | UNVERIFIED — HOST REQUIRED. Local HTTP 200 was observed, including during the stage 21 maintenance window |
| Mail | Trigger password reset for a mailbox you control | The message arrives from `MAIL_FROM_ADDRESS` and the link uses `APP_URL` | UNVERIFIED — HOST REQUIRED. Local `MAIL_MAILER=log` |
| Error logging and alerting | Cause a handled warning and confirm the daily log and the external alert | Level is `warning` or `error`, and the line has no password or `APP_KEY` | UNVERIFIED — HOST REQUIRED. The local log is `debug` on the single channel |
| Encrypted backups | Run the host backup job from its scheduler | A new encrypted dump has a zero exit status, a `Dump completed` line, retention is enforced, and a copy is stored off the database host | UNVERIFIED — HOST REQUIRED. Existing local dumps are manual and not encrypted by this application |
| Restore drill | Restore that dump with `--one-database` into a new database name | Table count, migration count, and key business counts match the source. Discard the drill database afterward | UNVERIFIED — HOST REQUIRED. The stage 21 restore proved the manual method once |
| Disk space and database availability | Alert when the host volume or the MySQL service is unavailable | An alert fires before the volume is full and when the database stops accepting connections | UNVERIFIED — HOST REQUIRED |
| Failed jobs | After a queued job exists, stop the worker and inspect `failed_jobs` | An alert fires when a row is added | UNVERIFIED — HOST REQUIRED. No application job is dispatched today, so there is nothing to alert on |

Session encryption, the Secure cookie, and HTTPS redirects agree when `APP_ENV=production` and `SESSION_ENCRYPT` and `SESSION_SECURE_COOKIE` are unset: `config/session.php` calls `DeploymentSecurity`, and `RequireProductionHttps` uses the same environment value. `LOG_LEVEL` defaults to `debug` in `config/logging.php` until the host sets `warning` or `error`. `LOG_STACK=daily` selects the daily channel, which keeps 14 files unless `LOG_DAILY_DAYS` is set. `config/mail.php` defaults to the `log` mailer until `MAIL_MAILER` is changed.
