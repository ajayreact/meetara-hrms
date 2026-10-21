# Fresh-install dependency map

This map is from filenames, the schema dump ledger, and the current migration source. It is not the result of a migrate run.

Laravel's migrator orders files by the full filename. A shared timestamp does not by itself set the order. Prerequisite failures are separate from that sort.

## Filename order

Current files, in the order the migrator sorts:

1. `0001_01_01_000000_create_users_table`
2. `0001_01_01_000001_create_cache_table`
3. `0001_01_01_000002_create_jobs_table`
4. `2026_10_19_200000_add_office_networks_and_attendance_breaks`
5. `2026_10_19_210000_add_holiday_status_and_leave_events`
6. `2026_10_19_220000_add_payroll_workflow_tables`
7. `2026_10_19_230000_add_role_assignment_events`
8. `2026_10_19_240000_add_company_payroll_settings`
9. `2026_10_19_250000_add_help_desk_overtime_and_audit_tables`
10. `2026_10_19_260000_point_ticket_assignee_at_users`
11. `2026_10_19_270000_convert_workflow_tables_to_innodb`
12. `2026_10_20_120000_add_company_lifecycle_events`
13. `2026_10_20_140000_add_company_admin_invitations`
14. `2026_10_20_160000_extend_employee_onboarding_documents`
15. `2026_10_20_180000_add_offer_letters`
16. `2026_10_20_200000_add_employee_record_event_note`
17. `2026_10_21_120000_repair_company_lifecycle_subject_email`

Historical names that are not files, inserted at the filename position where they would sort:

- `2026_10_19_023112_create_permission_tables` after the `0001` group and before `100000`
- `2026_10_19_100000_create_hrms_tables`
- `2026_10_19_120000_create_remaining_phase_tables`
- `2026_10_19_130000_add_holiday_calendars`
- `2026_10_19_140000_add_office_wifi_and_wfh` before `2026_10_19_140000_add_payroll_day_counts`, because `add_office_wifi_and_wfh` sorts before `add_payroll_day_counts`
- `2026_10_19_140000_add_payroll_day_counts`
- `2026_10_19_160000_add_profile_details_and_tickets` before `200000`

The quarantined users candidate name `2026_10_19_010000_add_users_company_columns` would sort after the `0001` group and before `023112`. That name is not in the dump ledger. It is a proposed repair for columns that the historical `0001` edit used to create.

## Dump ledger

The dump has 18 rows:

| Batch | Name |
|---|---|
| 1 | `0001` users, cache, jobs, `2026_10_19_023112_create_permission_tables`, `2026_10_19_100000_create_hrms_tables` |
| 2 | `2026_10_19_120000_create_remaining_phase_tables` |
| 3 | `2026_10_19_130000_add_holiday_calendars` |
| 4 | `2026_10_19_140000_add_office_wifi_and_wfh` |
| 5 | `2026_10_19_140000_add_payroll_day_counts` |
| 6 | `2026_10_19_160000_add_profile_details_and_tickets` |
| 7–14 | `2026_10_19_200000` through `2026_10_19_270000` |

The dump does not record the `2026_10_20` lifecycle, invitation, onboarding, offer-letter, or employee-record-event files, or the `2026_10_21` repair. Its `CREATE TABLE` text is the state after `200000`–`270000`. It has no `company_lifecycle_events`, `company_admin_invitations`, `offer_letters`, or `employee_record_events`.

## Prerequisite constraints

- `0001` creates stock `users`, cache, and jobs tables. With the Stage 55 provider setting, unqualified `string()` calls in a booted application use length 191. Explicit lengths such as `sessions.ip_address` and `remember_token` stay as written. `0001` does not create `tenant_id`, `is_platform_admin`, `invite_token`, `invited_at`, or `invite_accepted_at`.
- The users candidate, if it were present, would have to run after `0001` and before `100000`. It must not add the foreign key, because `tenants` does not exist yet.
- The permission migration does not need `users.tenant_id`. The application does need `roles` and `model_has_roles` for company role rows. `230000` creates `role_assignment_events` only when `roles` already exists, so a missing permission migration makes that later file a no-op.
- `100000` creates `tenants` and then adds a foreign key on `users.tenant_id`. Without that column the foreign-key statement fails. `100000` is not idempotent.
- `120000` depends on users and on tables from `100000`. It also writes `plan_feature` rows.
- `130000` alters `holidays` and `employees` from `100000`.
- The office-wifi file alters `locations` and `attendance_logs` and creates `wfh_requests`. It has no code dependency on the payroll day-count file. `wfh_days` is a decimal on `payroll_items`, not a foreign key to `wfh_requests`.
- The payroll day-count file alters `payroll_items` from `120000`.
- `160000` alters `employees` and creates `tickets.assignee_id` referencing `employees`.
- `200000` through `250000` and `270000` check for the historical tables and return without creating them when those tables are absent. `260000` returns when `tickets.assignee_id` is absent.
- `2026_10_20_120000` creates `company_lifecycle_events` only when `tenants` and `users` exist.
- `2026_10_20_140000` throws if `tenants` or `users` is missing, except when `2026_10_20_120000` is already recorded and `company_lifecycle_events` is absent. In that case it returns. The condition does not check the driver.
- `2026_10_20_160000` and `2026_10_20_180000` return when `tenants` is missing. Offer-letter unqualified strings have no snapshot.
- `2026_10_20_200000` returns when `employee_record_events` is missing.
- The repair throws when either company table is missing, except when `2026_10_20_120000` is already recorded and the lifecycle table is absent. That exception also does not check the driver. The repair is not approved for the live database.

## Earliest failure

On the tree as it exists today, the historical ledger diverges at the first missing name: `2026_10_19_023112_create_permission_tables`. The five users columns are also absent from `0001`, so the next historical schema file that could be restored, `100000`, would fail on `users.tenant_id` even if the six payloads were copied into place and the permission file remained missing.

The current on-disk files after `0001` do not raise that error themselves. Their guards allow them to be recorded while `tenants`, `roles`, and the other historical tables stay absent. The invitation and repair early returns apply to that recorded-but-empty lifecycle state on any driver. This stage did not execute that path.

Subsequent blockers, in the order a reconstruction would meet them:

1. Users company columns are not in the active `0001` file, and the repair candidate is unapproved.
2. The permission migration body is missing, and Spatie is not a Meetara dependency.
3. The six payloads are unverified and are not in `database/migrations`.
4. `100000` requires `users.tenant_id` and then creates the tables later files alter.
5. The two `140000` files must keep dump order: office wifi, then payroll day counts.
6. `120000` performs a `plan_feature` update that `down()` does not reverse.
7. Later guarded migrations can record a successful run that does not match the 18-row dump.
8. Lifecycle, invitations, and the subject-email repair are outside the dump and are not approved for a fresh historical replica.
9. `ON UPDATE RESTRICT` remains inferred.

The Stage 55 string-length restoration removes the earlier `varchar(255)` stop at `0001`. It does not remove the stops above.
