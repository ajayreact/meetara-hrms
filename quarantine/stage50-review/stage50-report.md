# Stage 50 report

## Summary

Six historical migration payloads remain preserved and unverified. Their Stage 50 hashes match Stage 43 and Stage 44. The permission migration is partial evidence only. No missing file was verified byte-for-byte, and none were promoted into `database/migrations`.

A fresh-install rehearsal is blocked before the first reconstructed migration. The users-column repair does not exist, and `2026_10_19_100000_create_hrms_tables` only adds a foreign key on `users.tenant_id`.

## Recovered and missing

Recovered but unverified, still in `quarantine/stage44-historical-migration-payloads/`:

- `2026_10_19_100000_create_hrms_tables`
- `2026_10_19_120000_create_remaining_phase_tables`
- `2026_10_19_130000_add_holiday_calendars`
- `2026_10_19_140000_add_office_wifi_and_wfh`
- `2026_10_19_140000_add_payroll_day_counts`
- `2026_10_19_160000_add_profile_details_and_tickets`

Not recovered as a Meetara file:

- `2026_10_19_023112_create_permission_tables`

The Spatie 8.3.0 stub elsewhere on disk matches the saved 80 lines and the permission tables when teams are enabled and the team key is `tenant_id`. That is not proof the published Meetara file was byte-for-byte that stub.

No original missing migration was verified byte-for-byte.

## Users repair

The specification is in `users-column-repair-review.md`. The five candidate lines are in the Stage 46 diff. The schema dump and the earliest SQL backup agree with those lines. The repair file was not created. Partial presence must stop before DDL. `0001` was not edited.

## Permission

The recorded tables match the stub with `teams = true` and `team_foreign_key = tenant_id`. The current application does not call Spatie. It still needs `roles` and `model_has_roles` for query-builder role assignment. Spatie was not installed. `quarantine/stage48-review/permission.php` was not copied to `config/`.

## First fresh-install blocker

The users-column repair is unspecified as a file and the seven historical bodies are not verified files in `database/migrations`. A clean database that runs the current `0001` cannot satisfy `100000`.

## Checks

SHA-256 of the six payloads, the users diff, the permission candidate, the rehearsal design, the Spatie stub, and every file in `database/migrations` was recomputed with `Get-FileHash`. Payload hashes match the earlier inventory. The Spatie stub hash is unchanged.

PHPUnit was not run. The standard suite opens a database connection, and this stage forbids that.

## Files created

Only under `quarantine/stage50-review/`:

- `recovery-inventory.md`
- `users-column-repair-review.md`
- `permission-migration-review.md`
- `on-disk-migration-review.md`
- `migration-manifest.md`
- `stage50-report.md`

No other project files were modified. Stage 44 payloads were not overwritten.

## Databases

No database connection was opened. No database was created, dropped, migrated, or modified. `.env`, Composer files, cached configuration, the schema dump, and existing migration files were not changed. The live database was not accessed.

This stage does not approve deployment or a rehearsal.

## Next step

Wait for an explicit approval that names one new disposable database and separately approves creating the guarded users-column repair and placing any candidate under `database/migrations`. Until that approval, do not connect and do not promote candidates.
