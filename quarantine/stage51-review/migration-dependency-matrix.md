# Stage 51 migration dependency matrix

A fresh install is still blocked. The users repair is only a quarantine candidate. The permission migration is not in this project. The six historical payloads remain unverified and stay in quarantine.

Lexical order for the shared timestamp is `2026_10_19_140000_add_office_wifi_and_wfh`, then `2026_10_19_140000_add_payroll_day_counts`.

| Order | Migration | Where the body is | Needs | Rollback risk |
|---|---|---|---|---|
| 1 | `0001` users, cache, jobs | `database/migrations`. Users file has no company columns | Nothing | Current `0001` `down()` drops users, tokens, and sessions |
| 2 | `2026_10_19_010000_add_users_company_columns` | Quarantine candidate only | `users.id` and `users.name` from step 1. MySQL. Table collation `utf8mb4_unicode_ci` | Forward-only. `down()` throws |
| 3 | `2026_10_19_023112_create_permission_tables` | Not in this project. Stub is outside the repo | Config `teams=true` and `team_foreign_key=tenant_id`. Spatie is not installed | Stub `down()` drops five tables |
| 4 | `2026_10_19_100000_create_hrms_tables` | Quarantine payload | `users.tenant_id` from step 2, then creates `tenants` before the foreign key | `down()` drops the HRMS tables and the users foreign key |
| 5 | `2026_10_19_120000_create_remaining_phase_tables` | Quarantine payload | `employees`, `features`, `plan_feature`, `users` | Drops payroll and related tables. Does not undo the feature-flag update |
| 6 | `2026_10_19_130000_add_holiday_calendars` | Quarantine payload | `holidays`, `employees` | Drops two columns |
| 7 | `2026_10_19_140000_add_office_wifi_and_wfh` | Quarantine payload | `locations`, `attendance_logs`, `employees`, `users` | Drops `wfh_requests` and three columns |
| 8 | `2026_10_19_140000_add_payroll_day_counts` | Quarantine payload | `payroll_items.special_allowance` from step 5 | Drops five decimal columns |
| 9 | `2026_10_19_160000_add_profile_details_and_tickets` | Quarantine payload | `employees` | Drops `tickets` and three profile columns. Later `260000` retargets assignee to users |
| 10–17 | `2026_10_19_200000` through `2026_10_19_270000` | On disk. Recorded in the schema dump | Tables from steps 4–9 | Several `down()` methods drop tables, columns, or rewrite ticket assignees |
| 18 | `2026_10_20_120000_add_company_lifecycle_events` | On disk. Unapproved. Not in the dump | `tenants`, `users` | Drops the lifecycle table |
| 19 | `2026_10_20_140000_add_company_admin_invitations` | On disk. Unapproved. Not in the dump | Step 18, then `subject_email` | Drops invitations and `subject_email` |
| 20–22 | `2026_10_20_160000`, `180000`, `200000` | On disk. Last-known live ledger included them. Not freshly read | Prior employee and onboarding tables | `down()` drops documents, events, offer letters, or the note column |
| 23 | `2026_10_21_120000_repair_company_lifecycle_subject_email` | On disk. Unapproved. Forward-only | Both company tables | `down()` throws |

## Earliest stop

Stop before any database is created. Step 2 is not approved for `database/migrations`. Step 3 has no file in this project. Step 4 cannot add its foreign key until `users.tenant_id` exists.

The `varchar(191)` gap is a further stop before promoting steps 4–9. The snapshots use that length for unqualified strings. The current framework default is 255, and the application does not set 191.
