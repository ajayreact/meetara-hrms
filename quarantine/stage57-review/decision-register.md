# Decision register

Stage 57 does not authorize any row. "Authorized now?" is No for every reconstruction, promotion, package install, and database action.

| Item | Current status | Evidence confidence | Required decision | Authorized now? |
|---|---|---|---|---|
| Provider `Schema::defaultStringLength(191)` | Applied in Stage 55. Hash `3abdb9e69eb13197350466b82ac6fad572b1e90c9fc7e3fdea55d32cebe5461f` | Transcript insertion. The rest of the historical provider was not recovered | None for this stage. Do not edit the provider again | No |
| `0001` users, cache, and jobs | Active stock migrations. Unchanged hashes | Files on disk | Leave them unchanged. Do not add the company columns to `0001` | No |
| Users company columns | Stage 52 candidate quarantined. Hash `d32a3c27f6ac7ff37a49c728a6af612f55e34d70bde0b1700cfae5e08af4625b` | Column definitions match both snapshots. `ON UPDATE RESTRICT` is inferred | Whether to allow a read-only `UPDATE_RULE` check, and only then whether to rehearse this new migration name | No |
| `2026_10_19_023112_create_permission_tables` | Missing. No Meetara payload | Snapshots support the five-table shape. File bytes are `partial_evidence_only` | Choose search, snapshot reconstruction, or the VSP-CRM 8.3.0 stub. See `permission-migration-options.md` | No |
| Install `spatie/laravel-permission` | Not in Meetara Composer files | VSP-CRM lock is 8.3.0, reference `60e8ed5b2fbf043c2264433fc2680c76b8b66aa6`. That is not a Meetara dependency record | Whether Meetara should gain this dependency at a pinned version | No |
| `quarantine/stage48-review/permission.php` | Quarantined candidate. Hash `91a4bfa769fe102220ad07735954c798f6f330fd9a5d6a038c878a8011e3375d` | `teams` and `tenant_id` match the snapshots. Other keys are package defaults | Whether this candidate may be reviewed as config. It is not a recovered original | No |
| `2026_10_19_100000_create_hrms_tables` | Quarantined payload. Hash `4ab8b5a55312240eea8bcfbfc5257ed1dbf62827ce2f8235466ad48f7bff7eda` | `recovered_but_unverified` | Approve or reject promotion of the unchanged payload after the users columns exist | No |
| `2026_10_19_120000_create_remaining_phase_tables` | Quarantined payload. Hash `106fa2cfce41fce713fac720edbb28cc9d34934b446f9be7337b5bc084470770` | `recovered_but_unverified`. `down()` does not reverse `plan_feature` | Approve or reject promotion, including that data update | No |
| `2026_10_19_130000_add_holiday_calendars` | Quarantined payload. Hash `b6f58cf6148d2605c0df145ad8e6e3d652f46e9b871d57418e3723c352037c07` | `recovered_but_unverified` | Approve or reject promotion after `100000` | No |
| `2026_10_19_140000_add_office_wifi_and_wfh` | Quarantined payload. Hash `797f4d2aa1d9a2a8a79213edd49fe93f0e742ab1a1e489cbedad4cdd8ab1dee1` | `recovered_but_unverified`. Dump batch 4 | Approve or reject promotion, ahead of the payroll day-count file | No |
| `2026_10_19_140000_add_payroll_day_counts` | Quarantined payload. Hash `50355fcf9828e2f16cee0bf86f4b4d167423b1d03f26ffcaea929c2934e6d089` | `recovered_but_unverified`. Dump batch 5 | Approve or reject promotion after `120000` | No |
| `2026_10_19_160000_add_profile_details_and_tickets` | Quarantined payload. Hash `395625052dc84659d62a769d531369d493889c2c61487a871ea25832029e180f` | `recovered_but_unverified`. Dump assignee target differs because of later `260000` | Approve or reject promotion. Dump comparison must include `260000` | No |
| On-disk migrations from `200000` through the subject-email repair | Present. Hashes matched Stage 56 | Source on disk. Several return without DDL when historical tables are missing | Leave them unchanged. Do not treat a guarded no-op as a reconstructed schema | No |
| Schema dump `mysql-schema.sql` | Unchanged. Hash `a1f8aa7ed4df6b386068d2d1984d018fd8544f4697f4d76afebc69bec8ae5cbb` | 18 ledger rows ending at `270000` | Do not regenerate or edit the dump | No |
| Disposable fresh-install rehearsal | Not started. No database name is approved | The current tree cannot reproduce the historical schema | Approve a new database name only after the decisions above | No |
