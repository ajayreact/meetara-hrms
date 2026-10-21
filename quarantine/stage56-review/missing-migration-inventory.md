# Missing historical migration inventory

Checked in Stage 56 by listing `database/migrations` and re-hashing `quarantine/stage44-historical-migration-payloads`. None of these seven names is an active migration file. A recovered payload is the text extracted from a transcript tool call. It is not a verified original source file.

The schema dump records all seven names. Its `CREATE TABLE` output is the database state after the later on-disk migrations through `2026_10_19_270000`, not a separate copy of each file. The earliest backup, `D:\wamp64\backups\meetara_hrms-before-office-networks.sql`, supports the pre-`200000` table shapes. This stage did not reopen either SQL file beyond the dump's migration ledger and permission-table definitions. Payload-to-snapshot column agreement cited below is the Stage 51–53 review, and the payload bytes were re-hashed rather than re-derived.

## 1. `2026_10_19_023112_create_permission_tables`

- Original source in this project: absent.
- Transcript: a later read of about 80 lines was recorded. The full published body was not stored as a Write payload.
- Quarantined payload: none.
- Completeness: partial.
- Dump and earliest backup: both contain `permissions`, `roles`, `model_has_permissions`, `model_has_roles`, and `role_has_permissions`.
- `up()` and `down()`: not recovered as a Meetara file.
- Confidence: the table shape is supported. The file bytes are not.
- Decision: blocked. See `permission-migration-evidence.md`.

## 2. `2026_10_19_100000_create_hrms_tables`

- Original source: absent.
- Quarantined payload: 19,262 bytes, SHA-256 `4ab8b5a55312240eea8bcfbfc5257ed1dbf62827ce2f8235466ad48f7bff7eda`.
- Completeness: the quarantined file has `up()` at line 9 and `down()` at line 382.
- Behavior read this stage: `up()` creates `tenants` and then adds `users.tenant_id` as a foreign key to `tenants` with `nullOnDelete()`. It does not create the five users company columns. `down()` drops the tables it created.
- Snapshot support: unqualified `string()` columns in this payload are `varchar(191)` in both snapshots. That length now depends on the restored provider default. The payload itself does not write `191`.
- Assumption: the transcript payload is the file that produced the ledger row. That was not proved by a byte-identical surviving original.
- Decision: the payload can be reviewed for a later approved rehearsal. It must not be promoted on the current evidence, and it cannot succeed before `users.tenant_id` exists.

## 3. `2026_10_19_120000_create_remaining_phase_tables`

- Original source: absent.
- Payload: 11,046 bytes, SHA-256 `106fa2cfce41fce713fac720edbb28cc9d34934b446f9be7337b5bc084470770`.
- Completeness: `up()` at line 10 and `down()` at line 214.
- Behavior read this stage: adds `users.api_token` as `string('api_token', 64)`, creates posts through tax declarations, and sets `plan_feature.enabled` for a fixed feature list. `down()` drops those tables and `api_token`. It does not reverse the `plan_feature` update.
- Snapshot support: explicit lengths and the unqualified strings agree with both snapshots. Payroll status defaults in this payload are `processed`. The dump's `draft` default is explained by the later on-disk `220000` migration, not by this payload.
- Decision: recoverable as an unverified payload. Promotion requires approval. The data update inside `up()` is a reason to rehearse it only on a disposable database.

## 4. `2026_10_19_130000_add_holiday_calendars`

- Original source: absent.
- Payload: 842 bytes, SHA-256 `b6f58cf6148d2605c0df145ad8e6e3d652f46e9b871d57418e3723c352037c07`.
- Completeness: `up()` and `down()` are both present.
- Behavior: adds `holidays.calendar` and `employees.holiday_calendar` as `string(..., 10)` with default `india`. No `hasColumn` guard.
- Snapshot support: both snapshots have those `varchar(10)` columns.
- Decision: unverified payload. Safe to consider only after `100000` has created `holidays` and `employees`, and only with approval.

## 5. `2026_10_19_140000_add_office_wifi_and_wfh`

- Original source: absent.
- Payload: 1,630 bytes, SHA-256 `797f4d2aa1d9a2a8a79213edd49fe93f0e742ab1a1e489cbedad4cdd8ab1dee1`.
- Completeness: `up()` and `down()` are both present.
- Behavior: adds `locations.ip_address`, `attendance_logs.check_in_ip` `varchar(45)`, `verified_as` `varchar(20)`, and creates `wfh_requests`. `reason` and `status` are unqualified strings.
- Order evidence: the full filename sorts before `2026_10_19_140000_add_payroll_day_counts`. The dump records this name as batch 4 and the payroll file as batch 5.
- Decision: unverified payload. It depends on tables from `100000`. It does not depend on the payroll day-count file.

## 6. `2026_10_19_140000_add_payroll_day_counts`

- Original source: absent.
- Payload: 949 bytes, SHA-256 `50355fcf9828e2f16cee0bf86f4b4d167423b1d03f26ffcaea929c2934e6d089`.
- Completeness: `up()` and `down()` are both present.
- Behavior: adds five `decimal(5,1)` columns to `payroll_items`, including `wfh_days`. There is no string column and no foreign key to `wfh_requests`.
- Snapshot support: the dump shows `decimal(5,1)` defaults. The columns belong to this payload's table, which is created by `120000`.
- Decision: unverified payload. Filename order places it after the office-wifi file. The shared timestamp is not a dependency.

## 7. `2026_10_19_160000_add_profile_details_and_tickets`

- Original source: absent.
- Payload: 1,424 bytes, SHA-256 `395625052dc84659d62a769d531369d493889c2c61487a871ea25832029e180f`.
- Completeness: `up()` and `down()` are both present.
- Behavior: adds profile text columns on `employees` and creates `tickets` with `assignee_id` constrained to `employees`.
- Snapshot support: the earliest backup references `employees`. The dump references `users` because the on-disk `260000` migration retargets that foreign key. `tickets.description` is absent from the backup and comes from `250000`.
- Decision: unverified payload. Promotion requires approval and a rehearsal that includes the later retarget, not this file alone.

## Shared limit

Schema agreement is support for the resulting columns. It is not a byte-for-byte recovery of the original PHP. No file in this inventory is classified `verified_original`.
