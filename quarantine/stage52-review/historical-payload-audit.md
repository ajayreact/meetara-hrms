# Stage 52 historical payload audit

The six files in `quarantine/stage44-historical-migration-payloads/` were hashed again. Each hash matches Stage 50 and Stage 51. They were not edited. None is a proven original. A matching snapshot does not change that.

| File | SHA-256 | Status |
|---|---|---|
| `2026_10_19_100000_create_hrms_tables.php` | `4ab8b5a55312240eea8bcfbfc5257ed1dbf62827ce2f8235466ad48f7bff7eda` | `recovered_but_unverified` |
| `2026_10_19_120000_create_remaining_phase_tables.php` | `106fa2cfce41fce713fac720edbb28cc9d34934b446f9be7337b5bc084470770` | `recovered_but_unverified` |
| `2026_10_19_130000_add_holiday_calendars.php` | `b6f58cf6148d2605c0df145ad8e6e3d652f46e9b871d57418e3723c352037c07` | `recovered_but_unverified` |
| `2026_10_19_140000_add_office_wifi_and_wfh.php` | `797f4d2aa1d9a2a8a79213edd49fe93f0e742ab1a1e489cbedad4cdd8ab1dee1` | `recovered_but_unverified` |
| `2026_10_19_140000_add_payroll_day_counts.php` | `50355fcf9828e2f16cee0bf86f4b4d167423b1d03f26ffcaea929c2934e6d089` | `recovered_but_unverified` |
| `2026_10_19_160000_add_profile_details_and_tickets.php` | `395625052dc84659d62a769d531369d493889c2c61487a871ea25832029e180f` | `recovered_but_unverified` |

## `100000`

`up()` creates the company, employee, leave, attendance, overtime, and notification tables, then adds `users_tenant_id_foreign` with `nullOnDelete()`. It does not create the five users company columns. `down()` drops the created tables and that foreign key. That drop removes later tables and rows that depend on them. Each `Schema::create` is a separate MySQL statement, so a failure in the middle can leave tables without a migration row.

The backup matches this payload's created shape, including the users foreign key. The dump adds later columns and keys from `2026_10_19_200000` through `2026_10_19_270000`. Unqualified `string()` columns are `varchar(191)` in both snapshots and are `unverified` for reproduction, as recorded in `string-length-audit.md`.

Application code reads `tenants`, `employees`, and the other tables this file creates. That use does not prove the file body.

## `120000`

`up()` adds `users.api_token` at explicit `varchar(64)`, employee `pan`, `uan`, and `esi_number` at explicit lengths, and the payroll, loan, expense, perk, and tax tables. It also sets `plan_feature.enabled` for a fixed key list. `down()` drops the new tables and columns. It does not reverse the feature-flag update.

`payroll_runs.status` and `payroll_items.payroll_status` default to `processed` in this payload and in the backup. The dump default is `draft` because `2026_10_19_220000_add_payroll_workflow_tables.php` modifies both columns to `VARCHAR(191) NOT NULL DEFAULT 'draft'`. The length was already 191 in the backup. The default change is explained. The original length mapping is not.

## `130000`

`up()` adds `holidays.calendar` and `employees.holiday_calendar` as `varchar(10)` default `india` in the positions both snapshots show. `down()` drops both columns. There is no `hasColumn` guard. The dump's extra `holidays.status` comes from on-disk `2026_10_19_210000_add_holiday_status_and_leave_events.php`. The backup does not have that column.

The explicit lengths match. The file is still an unverified payload.

## Office Wi-Fi `140000`

Lexical order places `2026_10_19_140000_add_office_wifi_and_wfh` before `2026_10_19_140000_add_payroll_day_counts`. They do not reference each other.

`up()` adds `locations.ip_address`, `attendance_logs.check_in_ip` `varchar(45)`, `attendance_logs.verified_as` `varchar(20)`, and `wfh_requests`. The explicit IP and verified-as lengths match both snapshots. `wfh_requests.reason` and `wfh_requests.status` are unqualified `string()` columns stored as `varchar(191)`. `down()` drops `wfh_requests` and the three columns. `wfh_days` is a decimal on `payroll_items`, not a foreign key to `wfh_requests`.

The dump's location timezone and status, and the attendance location, checkout IP, and recovery note, come from on-disk `2026_10_19_200000`. The backup does not contain them.

## Payroll day-count `140000`

`up()` adds five `decimal(5,1)` columns after `special_allowance`. Both snapshots show `decimal(5,1) NOT NULL DEFAULT '0.0'` in that order, before `overtime_amount`. `down()` drops the five columns. There is no string-length issue in this file. It remains an unverified payload, and rollback deletes stored day counts.

## `160000`

`up()` adds `employees.family_info`, `emergency_contact`, and `social_links` after `address`. Both snapshots have that order. It creates `tickets.assignee_id` as a nullable foreign key to `employees` with `nullOnDelete()`. The backup constraint is `tickets_assignee_id_foreign` to `employees(id)` `ON DELETE SET NULL`. The dump constraint of the same name points to `users(id)` `ON DELETE SET NULL`.

That dump change is the on-disk file `2026_10_19_260000_point_ticket_assignee_at_users.php`, which retargets the key and can rewrite assignee ids. `2026_10_19_250000` adds `tickets.description`. The backup create text has no `description` column. `down()` drops `tickets` and the three profile columns.

The five ticket string columns are unqualified and stored as `varchar(191)`.

## What was not reconciled

The backup is the earlier schema. The dump is the schema after the on-disk migrations through `2026_10_19_270000`. Differences in payroll defaults, ticket assignee targets, holiday status, office-network columns, and the extra payroll foreign keys are present in those later source files. They are not silent edits of the payloads. The payloads stay quarantined.
