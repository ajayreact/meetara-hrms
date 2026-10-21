# Stage 53 fresh-install readiness

A reproducible fresh install is not ready. The string-length statement was recovered from a transcript edit, and it is not in the current `AppServiceProvider`. The six payloads remain `recovered_but_unverified`. The permission migration remains `partial_evidence_only`. No reconstruction was approved or applied.

## Order

| Step | File | Predecessor | Stop |
|---|---|---|---|
| 1 | `0001_01_01_000000_create_users_table`, then cache and jobs | None | Stop here for a snapshot-faithful install. Unqualified `string()` would be `varchar(255)` under the current default and is `varchar(191)` in both snapshots. Do not edit these files. |
| 2 | `2026_10_19_010000_add_users_company_columns` | `users.id` and `users.name` from step 1 | Quarantine candidate only. Explicit column SQL. Separate approval required before it can be placed in `database/migrations`. |
| 3 | `2026_10_19_023112_create_permission_tables` | A published config with `teams=true` and `team_foreign_key=tenant_id` | Stop. The Meetara file is missing. Spatie is not a dependency. The external stub is not the original file and is not installed. `roles` and `model_has_roles` still have to exist before the application can store role rows. This stage does not create those tables. |
| 4 | `2026_10_19_100000_create_hrms_tables` | `users.tenant_id` from step 2, then the payload creates `tenants` before the foreign key | Blocked. Unverified payload. Its unqualified strings depend on the missing provider setting. |
| 5 | `2026_10_19_120000_create_remaining_phase_tables` | Employees, features, and users from step 4 | Blocked. Backup payroll defaults are `processed`. The dump defaults are `draft` because on-disk `2026_10_19_220000` changes them. |
| 6 | `2026_10_19_130000_add_holiday_calendars` | Holidays and employees | Blocked as an unverified file. Its two lengths are explicit and match both snapshots. |
| 7 | `2026_10_19_140000_add_office_wifi_and_wfh` | Locations and attendance logs | Blocked. This name sorts before the payroll day-count file. |
| 8 | `2026_10_19_140000_add_payroll_day_counts` | `payroll_items.special_allowance` | Blocked as an unverified file. The decimals match both snapshots. |
| 9 | `2026_10_19_160000_add_profile_details_and_tickets` | Employees | Blocked. The backup foreign key points `tickets.assignee_id` at `employees`. The dump points it at `users` because on-disk `2026_10_19_260000` retargets it. |
| 10–17 | `2026_10_19_200000` through `2026_10_19_270000` | The blocked historical tables | On disk and present in the dump. They are not reachable in a faithful install until steps 4–9 are resolved. |
| 18–19 | `2026_10_20_120000` lifecycle, then `2026_10_20_140000` invitations | Tenants, users, and `subject_email` before the invitation table | On disk and unapproved. Not in the dump. |
| 20–22 | `2026_10_20_160000`, `180000`, `200000` | Prior employee tables | Last-known live ledger included them. Offer-letter unqualified strings have no snapshot. |
| 23 | `2026_10_21_120000_repair_company_lifecycle_subject_email` | Both company tables | Forward-only and unapproved. |

## Earliest stop

The install stops at step 1. Restoring the recorded `Schema::defaultStringLength(191)` would address that length mismatch without editing `0001`, and it is the evidence-backed option in `string-length-remediation-options.md`. It has not been approved and it has not been restored. After that stop, step 3 still has no migration body in this project, and steps 4–9 are unverified payloads.

## Unresolved mismatches

- Current provider default 255 versus snapshot `varchar(191)`.
- Payload files are transcript recoveries, not byte-proven originals.
- Permission tables have a compatible external stub and no Meetara file.
- Payroll status defaults differ between the backup and the dump because of a later on-disk migration.
- `tickets.assignee_id` points at different tables in the backup and the dump because of a later on-disk migration.
- The users foreign-key update rule is inferred as `RESTRICT` and has not been read from a database.
- Offer-letter unqualified string lengths have no snapshot.
