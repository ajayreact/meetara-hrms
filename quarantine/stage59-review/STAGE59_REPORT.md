# Stage 59 report

The first preflight stopped on a line-ending hash mismatch. Later on 21 October 2026 the CRLF bytes were restored, the recorded hash matched, and the approved rehearsal was run. The outcome is below. `meetara_hrms` was not accessed.

## Continuation — rehearsal outcome

The Stage 52 candidate again hashes to `d32a3c27f6ac7ff37a49c728a6af612f55e34d70bde0b1700cfae5e08af4625b`. Restoring CRLF did not change the PHP logic.

`meetara_hrms_rehearsal_20261021` was created empty. Identity checks reported that name. Migrations used only `quarantine/stage59-review/rehearsal-migrations` and did not load the schema dump.

The run completed through `2026_10_19_270000_convert_workflow_tables_to_innodb` (19 ledger rows) and failed on `2026_10_20_120000_add_company_lifecycle_events` with SQLSTATE 1824, failed to open referenced table `tenants`. The server default engine is MyISAM. `tenants` and `users` are MyISAM. `company_lifecycle_events` was left as InnoDB without a ledger row. `users_tenant_id_foreign` is absent. Payloads were not edited.

The permission reconstruction is `quarantine/stage59-review/rehearsal-migrations/2026_10_19_023112_create_permission_tables_reconstruction.php`, SHA-256 `55b8536143e41907f31df3b8ce070e6f046680a3db10ed63758fa7babfc300c4`. It is a new reconstruction, not an original recovered migration.

Fresh installation did not pass. Submission notes are in `docs/SUBMISSION_STATUS.md`.

## Original preflight stop

## Approved scope

These four items were approved for this stage only:

1. Rehearse the six unchanged historical payloads as recovered, unverified source.
2. Use the unchanged Stage 52 users-column candidate and keep `ON UPDATE RESTRICT` strict.
3. Create a labeled schema-only permission reconstruction. Do not install Spatie.
4. Use one new empty database named `meetara_hrms_rehearsal_20261021` for 21 October 2026.

The first stop was on item 2. The candidate bytes on disk did not match the recorded hash, so nothing was created during that stop. The continuation at the top of this file is the later result.

## Hash verification

| Item | Recorded SHA-256 | SHA-256 this stage | Result |
|---|---|---|---|
| `app/Providers/AppServiceProvider.php` | `3abdb9e69eb13197350466b82ac6fad572b1e90c9fc7e3fdea55d32cebe5461f` | same | Match. 7,262 bytes |
| `database/schema/mysql-schema.sql` | `a1f8aa7ed4df6b386068d2d1984d018fd8544f4697f4d76afebc69bec8ae5cbb` | same | Match. 84,502 bytes |
| `0001` users | `10d79883bc08ce46510f9ad8b1830b4d06d586dc56ae2c8bac3b3e491f69a90f` | same | Match |
| `0001` cache | `20f769d3c11748c1cc42c41cd3789b0b9b2cd0db44ee22d378addac3ff8aed4f` | same | Match |
| `0001` jobs | `7c9c712adf4091c87bf2612f4d0dccdfa1df4501553e4ac068b83d7bd5462fa5` | same | Match |
| `2026_10_19_100000_create_hrms_tables.php` | `4ab8b5a55312240eea8bcfbfc5257ed1dbf62827ce2f8235466ad48f7bff7eda` | same | Match. 19,262 bytes |
| `2026_10_19_120000_create_remaining_phase_tables.php` | `106fa2cfce41fce713fac720edbb28cc9d34934b446f9be7337b5bc084470770` | same | Match. 11,046 bytes |
| `2026_10_19_130000_add_holiday_calendars.php` | `b6f58cf6148d2605c0df145ad8e6e3d652f46e9b871d57418e3723c352037c07` | same | Match. 842 bytes |
| `2026_10_19_140000_add_office_wifi_and_wfh.php` | `797f4d2aa1d9a2a8a79213edd49fe93f0e742ab1a1e489cbedad4cdd8ab1dee1` | same | Match. 1,630 bytes |
| `2026_10_19_140000_add_payroll_day_counts.php` | `50355fcf9828e2f16cee0bf86f4b4d167423b1d03f26ffcaea929c2934e6d089` | same | Match. 949 bytes |
| `2026_10_19_160000_add_profile_details_and_tickets.php` | `395625052dc84659d62a769d531369d493889c2c61487a871ea25832029e180f` | same | Match. 1,424 bytes |
| Stage 52 users candidate | `d32a3c27f6ac7ff37a49c728a6af612f55e34d70bde0b1700cfae5e08af4625b` | `6b19bad30448539a0665121d06f108dd3ef8678e97f72d8edd4edd9275dc6143` | Mismatch. 13,497 bytes |

The active migration directory still contains the same 17 files recorded in Stage 56. `bootstrap/cache/config.php` is absent. The configured connection is MySQL on `127.0.0.1:3306`, and the configured database name is `meetara_hrms`. `.env` does not set `DB_CHARSET` or `DB_COLLATION`, so the application defaults remain `utf8mb4` and `utf8mb4_unicode_ci`.

## Candidate mismatch

`quarantine/stage52-review/candidates/2026_10_19_010000_add_users_company_columns.php` was last written at 2026-10-21 13:50:03. It now contains 311 LF newlines and no CR characters.

Restoring CRLF in memory, without writing the file, produces SHA-256 `d32a3c27f6ac7ff37a49c728a6af612f55e34d70bde0b1700cfae5e08af4625b`. That is the recorded Stage 52 hash. The on-disk text still rejects every `ON UPDATE` rule other than `restrict`.

The Stage 51 candidate in the same minute changed the same way. Its on-disk hash is `de0fe0addc7f4de6bf52762b9b41a08301275a9ccb109ff99eaf16ba37c3d041`. Restoring CRLF in memory produces the recorded hash `bc1283747b84e80d5b593fed52b6c8a073a8b389af1fd14606159f729f133d6c`. Stage 51 was not part of this rehearsal. It is noted because both quarantined copies moved together.

This stage did not convert the line endings back and did not edit either candidate.

## Files and database

During the first stop, no permission reconstruction was written and `meetara_hrms_rehearsal_20261021` was not created. Both happened later, as recorded in the continuation.

## Checks not run

Migration, schema comparison, and `vendor/bin/phpunit` were not run. PHPUnit’s last recorded result remains the Stage 55 run: 159 tests, 2334 assertions, 14 skipped, exit 0. That result is not a Stage 59 result.

## Production blockers

Payroll statutory decisions and offer-letter legal approval remain unresolved. The live database migrate policy is unchanged. Fresh installation is still not reproducible.

## Decision required

Approve one of these before a rehearsal starts:

- Accept the current LF file as the Stage 52 candidate, and record `6b19bad30448539a0665121d06f108dd3ef8678e97f72d8edd4edd9275dc6143` as its hash.
- Restore the CRLF bytes so the file again matches `d32a3c27f6ac7ff37a49c728a6af612f55e34d70bde0b1700cfae5e08af4625b`, then repeat preflight.

This stage does not choose. It does not start Stage 60.
