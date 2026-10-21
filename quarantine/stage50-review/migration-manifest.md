# Stage 50 migration manifest

Full filename order is lexical. `2026_10_19_140000_add_office_wifi_and_wfh` sorts before `2026_10_19_140000_add_payroll_day_counts`. Those two payloads do not reference each other.

A fresh install is not possible from the files in `database/migrations`. The first stop is immediate: the users-column repair does not exist, the permission migration is not in this project, and the six historical payloads are unverified and must not be copied into `database/migrations` on the strength of this review.

| Step | Name | Class | Approval |
|---|---|---|---|
| 1 | `0001` users, cache, jobs | Verified on disk as the current files. Users file lacks the five company columns. | Do not edit `0001`. |
| 2 | Future guarded users-column repair | Reconstructed but unverified. Body not created. | Explicit approval of the candidate lines before the file exists. |
| 3 | `2026_10_19_023112_create_permission_tables` | Partial evidence only. Stub is outside this project. | Explicit approval, Spatie review, and config review. Do not install in this stage. |
| 4 | `2026_10_19_100000_create_hrms_tables` | Reconstructed but unverified payload. | Must not run until step 2 has created `users.tenant_id`. |
| 5 | `2026_10_19_120000_create_remaining_phase_tables` | Reconstructed but unverified payload. | After step 4. |
| 6 | `2026_10_19_130000_add_holiday_calendars` | Reconstructed but unverified payload. | After step 4. |
| 7 | `2026_10_19_140000_add_office_wifi_and_wfh` | Reconstructed but unverified payload. | After step 4. Before the payroll day-count file. |
| 8 | `2026_10_19_140000_add_payroll_day_counts` | Reconstructed but unverified payload. | After step 5. |
| 9 | `2026_10_19_160000_add_profile_details_and_tickets` | Reconstructed but unverified payload. | After step 4. |
| 10–17 | `2026_10_19_200000` through `2026_10_19_270000` | Verified on disk. Recorded in the schema dump. | Reachable only after the unverified base exists. |
| 18 | `2026_10_20_120000_add_company_lifecycle_events` | Verified on disk. Unapproved. Not in the dump. Last-known live ledger did not include it. | Separate approval. |
| 19 | `2026_10_20_140000_add_company_admin_invitations` | Verified on disk. Unapproved. Requires step 18 and `subject_email`. | Separate approval. After step 18. |
| 20 | `2026_10_20_160000_extend_employee_onboarding_documents` | Verified on disk. Last-known live ledger included it. Absent from the dump. | Do not rerun where it is already recorded. |
| 21 | `2026_10_20_180000_add_offer_letters` | Verified on disk. Last-known live ledger included it. Absent from the dump. | Do not rerun where it is already recorded. |
| 22 | `2026_10_20_200000_add_employee_record_event_note` | Verified on disk. Last-known Stage 35B apply. Absent from the dump. | Do not rerun where it is already recorded. |
| 23 | `2026_10_21_120000_repair_company_lifecycle_subject_email` | Verified on disk. Unapproved. Forward-only. | Only for a database whose column is missing or must be checked. `down()` throws. |

No row is `verified_original` for a missing historical file.

## Dependency matrix

- Users repair needs `users` from current `0001` and must finish before `100000`.
- Permission tables need the candidate config loaded. They do not need `100000`.
- `100000` needs `users.tenant_id` and creates `tenants` before the foreign key.
- `120000` needs employees and users from `100000`.
- `130000` needs `holidays` and `employees`.
- Office Wi-Fi needs `locations` and `attendance_logs`.
- Payroll day counts need `payroll_items.special_allowance`.
- Historical profile/tickets need `employees`.
- `200000` through `270000` need the tables named in `quarantine/stage50-review/on-disk-migration-review.md`.
- Invitations need the lifecycle table, then `subject_email`.
- The subject-email repair needs both company tables and does not create them.
- Offer letters and the event note need the extended onboarding migration's tables.

## Stop point

Stop before creating a disposable database. The users-column repair file does not exist, the permission migration is not recovered into this project, and the six payloads are not verified originals.
