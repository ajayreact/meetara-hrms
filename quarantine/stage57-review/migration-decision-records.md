# Migration decision records

Each record is a review item. None of these files is an active migration. None is labeled an original migration. Payload hashes were rechecked in Stage 57 and match Stage 56. Column comparisons with the schema dump and the earliest backup are the recorded Stage 51–53 results. Stage 57 did not re-parse those SQL snapshots and did not open a database.

The provider setting `Schema::defaultStringLength(191)` is active. Unqualified `string()` calls in a booted migration use length 191. Explicit lengths stay as written in the payload.

## 1. `2026_10_19_023112_create_permission_tables`

- Source evidence: the name is dump ledger row 4, batch 1. No Meetara PHP file. No quarantined payload. A prior transcript stored a partial read of a published stub, not a complete Write. The VSP-CRM stub SHA-256 is `bd097ba10f37a131a9a8885ed4723387521a2f222f636b37b911e7b9ed34eec2`. Meetara Composer files do not require Spatie.
- Expected schema: five tables, `permissions`, `roles`, `model_has_permissions`, `model_has_roles`, and `role_has_permissions`, with `tenant_id` on the team-bearing tables. Detail and options are in `permission-migration-options.md`.
- Order: after the three `0001` files and before `2026_10_19_100000_create_hrms_tables`. It does not require `users.tenant_id`. Later `2026_10_19_230000_add_role_assignment_events` creates its table only when `roles` already exists.
- Dump and backup: both snapshots contain the five tables. There is no payload to diff against them.
- `up()` and `down()`: unknown for the Meetara file. The external stub's `down()` drops the five tables.
- Rollback risk: dropping permission tables removes role assignments the application reads from `model_has_roles`.
- Confidence: table shape is supported by snapshots. File bytes are not recovered. Status: `partial_evidence_only`.
- Approval required: a choice among the three options in `permission-migration-options.md`. No option is authorized now. Do not install Spatie and do not create a migration from this record.

## 2. `2026_10_19_100000_create_hrms_tables`

- Source evidence: quarantined payload, 19,262 bytes, SHA-256 `4ab8b5a55312240eea8bcfbfc5257ed1dbf62827ce2f8235466ad48f7bff7eda`. Extracted from a transcript tool call. Not a surviving original.
- Expected schema: creates `tenants` and the core HRMS tables through `notifications`. Adds `users_tenant_id_foreign` with `nullOnDelete()` after `tenants` exists. Does not add the five users company columns. Unqualified strings depend on the provider default of 191.
- Order: after `0001`, after the users columns exist, and after the permission name in the dump ledger. Filename order places it after `023112`. The foreign key fails if `users.tenant_id` is missing. Later historical payloads alter tables this file creates.
- Differences: both snapshots have `varchar(191)` for this payload's unqualified strings and `ON DELETE SET NULL` on `users.tenant_id`. The payload does not write the length 191 or an `ON UPDATE` clause. The dump's later column changes, such as payroll status defaults and the ticket assignee target, come from on-disk migrations after this file. The earliest backup is the closer "after this payload" picture, and it is still not a line-by-line proof of the PHP.
- `up()`: creates tables and the users foreign key. It is not idempotent. `down()` drops the created tables and the users foreign key.
- Rollback risk: `down()` drops tenant and employee data and every table created here.
- Confidence: `recovered_but_unverified`. Schema outcome is supported. Byte identity with the original file is not proved.
- Approval required: explicit approval to copy this payload, unchanged, into `database/migrations`, and a separate approval for a disposable rehearsal. The users-column decision has to be approved first or this file cannot add the foreign key. Not authorized now.

## 3. `2026_10_19_120000_create_remaining_phase_tables`

- Source evidence: quarantined payload, 11,046 bytes, SHA-256 `106fa2cfce41fce713fac720edbb28cc9d34934b446f9be7337b5bc084470770`. Transcript payload, not a verified original.
- Expected schema: `users.api_token` as `string('api_token', 64)`, posts, polls, salary structures, payroll runs and items, loans, expenses, perks, tax declarations, and related tables. It also sets `plan_feature.enabled` for a fixed feature list.
- Order: dump batch 2, after `100000`. It alters `users` and `employees` and creates tables that `140000` payroll day counts and `220000` later modify.
- Differences: explicit lengths match both snapshots. Unqualified strings are `varchar(191)` in both snapshots. This payload defaults payroll status to `processed`. The earliest backup still has `processed`. The dump has `draft` because on-disk `2026_10_19_220000_add_payroll_workflow_tables` modifies the column. That difference is expected if the later file runs. It is not a reason to edit this payload.
- `up()`: schema changes plus the `plan_feature` update. `down()` drops the created tables, `employees` tax-id columns, and `api_token`. It does not reverse `plan_feature.enabled`.
- Rollback risk: `down()` drops payroll and related rows. A rollback leaves the feature-flag update in place.
- Confidence: `recovered_but_unverified`.
- Approval required: explicit approval to promote this unchanged payload, with the data update accepted as part of the file, and a separate disposable rehearsal. Not authorized now.

## 4. `2026_10_19_130000_add_holiday_calendars`

- Source evidence: quarantined payload, 842 bytes, SHA-256 `b6f58cf6148d2605c0df145ad8e6e3d652f46e9b871d57418e3723c352037c07`. Transcript payload, not a verified original.
- Expected schema: `holidays.calendar` and `employees.holiday_calendar`, each `string(..., 10)` default `india`.
- Order: dump batch 3, after `100000` has created `holidays` and `employees`. No dependency on `120000` is visible in the payload. Filename order still places it after `120000`.
- Differences: both snapshots store `varchar(10)` with that default. The length is explicit, so the provider default does not apply. There is no `hasColumn` guard.
- `up()`: two `Schema::table` calls. `down()` drops both columns.
- Rollback risk: `down()` drops the calendar values. A second `up()` on a database that already has the columns fails.
- Confidence: `recovered_but_unverified`. The file is small and the snapshot columns match the explicit definitions.
- Approval required: explicit approval to promote the unchanged payload after `100000` is available. Not authorized now.

## 5. `2026_10_19_140000_add_office_wifi_and_wfh`

- Source evidence: quarantined payload, 1,630 bytes, SHA-256 `797f4d2aa1d9a2a8a79213edd49fe93f0e742ab1a1e489cbedad4cdd8ab1dee1`. Transcript payload, not a verified original.
- Expected schema: `locations.ip_address`, `attendance_logs.check_in_ip` varchar(45), `attendance_logs.verified_as` varchar(20), and table `wfh_requests` with unqualified `reason` and `status`.
- Order: dump batch 4. The full filename sorts before `2026_10_19_140000_add_payroll_day_counts`. The shared timestamp does not create a code dependency. This file needs `locations` and `attendance_logs` from `100000`. `wfh_days` on payroll items is a different file.
- Differences: explicit lengths match both snapshots. `wfh_requests.reason` and `status` are `varchar(191)` in both snapshots. Later `200000` adds location networks and attendance break tables. It does not replace this file.
- `up()`: two alters and one create, without existence guards. `down()` reverses those objects.
- Rollback risk: `down()` drops `wfh_requests` and the added columns.
- Confidence: `recovered_but_unverified`.
- Approval required: explicit approval to promote the unchanged payload, keeping it ahead of the payroll day-count file. Not authorized now.

## 6. `2026_10_19_140000_add_payroll_day_counts`

- Source evidence: quarantined payload, 949 bytes, SHA-256 `50355fcf9828e2f16cee0bf86f4b4d167423b1d03f26ffcaea929c2934e6d089`. Transcript payload, not a verified original.
- Expected schema: `payroll_items.working_days`, `present_days`, `leave_days`, `wfh_days`, and `lop_days`, each `decimal(5,1)` default 0, after `special_allowance`.
- Order: dump batch 5, after the office-wifi filename and after `120000` has created `payroll_items`. `wfh_days` does not reference `wfh_requests`.
- Differences: the dump shows `decimal(5,1)` NOT NULL defaults. There is no string-length issue. No `hasColumn` guard.
- `up()`: one `Schema::table`. `down()` drops the five columns.
- Rollback risk: `down()` drops day-count values. A second `up()` fails if the columns already exist.
- Confidence: `recovered_but_unverified`.
- Approval required: explicit approval to promote the unchanged payload after `120000`. Not authorized now.

## 7. `2026_10_19_160000_add_profile_details_and_tickets`

- Source evidence: quarantined payload, 1,424 bytes, SHA-256 `395625052dc84659d62a769d531369d493889c2c61487a871ea25832029e180f`. Transcript payload, not a verified original.
- Expected schema: nullable profile text columns on `employees`, and `tickets` with `assignee_id` constrained to `employees`. Five ticket strings are unqualified.
- Order: dump batch 6, after both `140000` files and before on-disk `200000`. It needs `employees` from `100000`.
- Differences: the earliest backup references `tickets.assignee_id` to `employees` and has no `tickets.description`. The dump references `users` because on-disk `260000` retargets the foreign key, and `description` comes from on-disk `250000`. Those later files explain the dump. They are not edits to make inside this payload. Unqualified ticket strings are `varchar(191)` in both snapshots.
- `up()`: alters `employees` and creates `tickets`. `down()` reverses that create and those columns.
- Rollback risk: `down()` drops tickets. Running this file and then `260000` rewrites assignee ids. That later rewrite is data-changing and is outside this decision.
- Confidence: `recovered_but_unverified`.
- Approval required: explicit approval to promote the unchanged payload. A rehearsal has to include the later assignee retarget before anyone compares the result with the dump. Not authorized now.
