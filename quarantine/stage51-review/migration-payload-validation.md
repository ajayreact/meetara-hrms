# Stage 51 migration payload validation

The six files in `quarantine/stage44-historical-migration-payloads/` were not modified. A file-only PHP check rehashed them. Each hash matches Stage 50. None is a verified original. None was copied to `database/migrations`.

The comparison sources are `database/schema/mysql-schema.sql` and `D:\wamp64\backups\meetara_hrms-before-office-networks.sql`. The backup is the earlier snapshot: it contains the effects of these payloads and does not contain the later on-disk changes from `2026_10_19_200000` onward. The schema dump is the later snapshot, through `2026_10_19_270000`.

`Illuminate\Database\Schema\Builder::$defaultStringLength` is 255 in the installed framework. The application does not call `Schema::defaultStringLength(191)`. Both snapshots store unqualified strings as `varchar(191)`. Re-running these payloads with the current default would not be proven to reproduce that length. Explicit lengths in the payloads are not affected.

## `2026_10_19_100000_create_hrms_tables`

SHA-256 `4ab8b5a55312240eea8bcfbfc5257ed1dbf62827ce2f8235466ad48f7bff7eda`. Status `recovered_but_unverified`.

`up()` creates the company, employee, leave, attendance, overtime, and notification tables. It then adds `users.tenant_id` foreign key to `tenants` with `nullOnDelete`. It does not create `tenant_id` or the other four users columns. `down()` drops the created tables and that foreign key. The drop list deletes later data and schema that depends on these tables.

Schema agreement: `tenants` in the dump matches this payload, including `week_off` JSON not null and `timezone` default `Asia/Kolkata`. `users_tenant_id_foreign` matches both snapshots. `notifications.id` is `char(36)` with the morph index. Base employee, holiday, location, and attendance columns that this file creates are still present.

This file is not idempotent. Each `Schema::create` commits separately on MySQL, so a failure in the middle leaves tables without a migration row. `down()` is destructive.

Dependency: the Stage 51 users repair must add `users.tenant_id` first. Later files `200000` through `270000`, plus the October 20 onboarding and offer-letter migrations, alter or depend on tables this payload creates.

## `2026_10_19_120000_create_remaining_phase_tables`

SHA-256 `106fa2cfce41fce713fac720edbb28cc9d34934b446f9be7337b5bc084470770`. Status `recovered_but_unverified`.

`up()` adds `users.api_token` `varchar(64)` nullable unique, and `employees.pan`, `uan`, and `esi_number`. It creates posts, polls, salary structures, payroll runs, payroll items, loans, expenses, perks, statutory settings, and tax declarations. It also updates existing `plan_feature` rows to `enabled = true` for a fixed feature-key list. `down()` drops the new tables and the added columns. It does not reverse the `plan_feature` update.

Backup agreement: `payroll_runs.status` default `processed`, `payroll_items.payroll_status` default `processed`, and `users.api_token` match this payload. Dump disagreement is explained by `2026_10_19_220000_add_payroll_workflow_tables`, which changes both defaults to `draft` and adds payroll review columns, `payroll_items.currency`, and `payroll_items_run_employee_unique`.

`pan`, `uan`, and `esi_number` have explicit lengths and sit after `updated_at` in both snapshots, which matches an alter without `after()`.

Partial failure can leave a created table plus the feature-flag update. The data update is not schema and is not undone by `down()`.

Depends on `100000` tables `employees`, `features`, and `plan_feature`.

## `2026_10_19_130000_add_holiday_calendars`

SHA-256 `b6f58cf6148d2605c0df145ad8e6e3d652f46e9b871d57418e3723c352037c07`. Status `recovered_but_unverified`.

`up()` adds `holidays.calendar` `varchar(10)` default `india` after `location_id`, and `employees.holiday_calendar` with the same definition after `work_mode`. Both snapshots have those columns in those positions. The dump also has `holidays.status`, added by on-disk `2026_10_19_210000_add_holiday_status_and_leave_events`. The backup does not have `holidays.status`.

`down()` drops both columns. There is no `hasColumn` guard. A second run would fail before a migration row is recorded if the first run committed one alter and then failed.

Depends on `holidays` and `employees` from `100000`.

## `2026_10_19_140000_add_office_wifi_and_wfh`

SHA-256 `797f4d2aa1d9a2a8a79213edd49fe93f0e742ab1a1e489cbedad4cdd8ab1dee1`. Status `recovered_but_unverified`.

Lexical order places this file before `2026_10_19_140000_add_payroll_day_counts`. The two files do not reference each other.

`up()` adds nullable `locations.ip_address` text after `address`, nullable `attendance_logs.check_in_ip` `varchar(45)` and `verified_as` `varchar(20)` after `work_mode`, and creates `wfh_requests` with the unique key on `employee_id, work_date`. The dump and backup both contain those additions. The dump's `locations.timezone`, `locations.status`, `attendance_logs.location_id`, `check_out_ip`, and `recovery_note` come from on-disk `2026_10_19_200000_add_office_networks_and_attendance_breaks`. The backup does not contain them.

`down()` drops `wfh_requests` and the three columns. `wfh_days` on payroll items is not a foreign key to `wfh_requests`.

Depends on `locations` and `attendance_logs` from `100000`.

## `2026_10_19_140000_add_payroll_day_counts`

SHA-256 `50355fcf9828e2f16cee0bf86f4b4d167423b1d03f26ffcaea929c2934e6d089`. Status `recovered_but_unverified`.

`up()` adds `working_days`, `present_days`, `leave_days`, `wfh_days`, and `lop_days` as `decimal(5,1)` default 0 after `special_allowance`. Both snapshots show `decimal(5,1) NOT NULL DEFAULT '0.0'` in that order, before `overtime_amount`.

`down()` drops those five columns. No guard. Depends on `payroll_items.special_allowance` from `120000`.

## `2026_10_19_160000_add_profile_details_and_tickets`

SHA-256 `395625052dc84659d62a769d531369d493889c2c61487a871ea25832029e180f`. Status `recovered_but_unverified`.

`up()` adds nullable `employees.family_info`, `emergency_contact`, and `social_links` after `address`. Both snapshots have them in that order, before `department_id`. It creates `tickets` with `assignee_id` nullable and constrained to `employees` with `nullOnDelete`.

The backup foreign key is `tickets.assignee_id` to `employees(id)` `ON DELETE SET NULL`. The dump foreign key is to `users(id)` because on-disk `2026_10_19_260000_point_ticket_assignee_at_users` retargets it and can rewrite assignee ids. The dump also has `tickets.description`, added by on-disk `2026_10_19_250000`. The backup create text has no `description` column.

`down()` drops `tickets` and the three employee columns. That would discard help-desk rows and profile text.

Depends on `employees` from `100000`.

## Mismatches that are not payload errors

| Snapshot difference | Explained by |
|---|---|
| Payroll status defaults `draft` in the dump, `processed` in the backup | `2026_10_19_220000_add_payroll_workflow_tables` |
| Extra payroll review columns and `currency` | Same on-disk file |
| `holidays.status` | `2026_10_19_210000_add_holiday_status_and_leave_events` |
| Location timezone and status, attendance location and recovery columns | `2026_10_19_200000_add_office_networks_and_attendance_breaks` |
| `tickets.assignee_id` references `users` in the dump and `employees` in the backup | `2026_10_19_260000_point_ticket_assignee_at_users` |
| `tickets.description` | `2026_10_19_250000_add_help_desk_overtime_and_audit_tables` |

## Missing evidence

No second copy of any payload was found. Hash agreement proves the quarantine bytes are unchanged. It does not prove those bytes are the file MySQL executed. Unqualified `string()` columns remain `varchar(191)` in the snapshots and `varchar(255)` under the current framework default.
