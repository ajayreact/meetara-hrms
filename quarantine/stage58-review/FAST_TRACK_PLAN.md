# Fast-track plan

Stage 58 is planning only. It does not promote files, install packages, edit the provider, edit migrations, or open a database.

Read before this plan: `quarantine/stage55-review/STAGE55_REPORT.md`, `quarantine/stage56-review/STAGE56_REPORT.md`, `quarantine/stage57-review/STAGE57_REPORT.md`.

## Already done

Do not repeat these stages.

- Stage 55 restored `Schema::defaultStringLength(191)` in `app/Providers/AppServiceProvider.php`. Hash `3abdb9e69eb13197350466b82ac6fad572b1e90c9fc7e3fdea55d32cebe5461f`. `php -l` passed. `vendor/bin/phpunit`: 159 tests, 2334 assertions, 14 skipped, exit 0, sqlite `:memory:` only.
- Stages 56 and 57 inventoried the seven missing files, the users candidate, and the permission options. No source file changed after Stage 55, so PHPUnit does not need to run again until the next source edit.
- Payroll approval and offer-letter issuance are already blocked in code. `PayrollStatutoryReadiness::issues()` always returns one issue for each of provident fund, ESI, labour welfare fund, TDS, and professional tax. `OfferLetterReadiness` always returns a template issue and a company legal-details issue. `ready()` stays false until those issues are actually cleared. Do not invent rates or legal wording.

The existing live database is a different problem from an empty install. Reconstruction is required before a new database can match the application. It is not required to keep describing the database that already has those tables.

## 1. Must fix before a fresh installation

One implementation stage, after the approval below. Do not split the six payloads into six stages.

Order on a new database:

1. Stock `0001` users, cache, and jobs. Leave those files unchanged. The provider setting makes unqualified strings `varchar(191)`.
2. Stage 52 users candidate, `2026_10_19_010000_add_users_company_columns`. It adds the five company columns and does not add the foreign key. Hash `d32a3c27f6ac7ff37a49c728a6af612f55e34d70bde0b1700cfae5e08af4625b`.
3. A new permission migration under the historical name `2026_10_19_023112_create_permission_tables`, labeled as a reconstruction. It must create the five snapshot tables with `tenant_id`. Do not install Spatie. The application reads `roles` and `model_has_roles` through the query builder.
4. The six quarantined payloads, copied unchanged, in filename order. `2026_10_19_140000_add_office_wifi_and_wfh` stays before `2026_10_19_140000_add_payroll_day_counts`. `100000` adds `users_tenant_id_foreign` only after step 2.
5. The migrations already on disk from `2026_10_19_200000` through `2026_10_21_120000`. They already contain the later schema the current app expects. They no-op when the historical tables are missing, which is why they cannot substitute for steps 2–4.

`120000` updates `plan_feature` and its `down()` does not reverse that update. `160000` points `tickets.assignee_id` at `employees`. On-disk `260000` later points it at `users`. Compare the rehearsal with the earliest backup before `260000`, and with the schema dump after `260000`.

## 2. Must fix before production

These do not block the rehearsal, and the rehearsal does not clear them.

- Statutory payroll: a qualified reviewer supplies an authorized rule or an explicit non-applicability decision for each of the five categories. Until that exists, Approve and Process stay disabled. A zero amount is not an exemption.
- Offer letters: an approved template version and verified legal name and address, with an approver and a time. Until that exists, Issue stays unavailable. The preview remains a draft.
- Deploy configuration: `APP_DEBUG` off, session encryption on, and mail not set to `log`, plus an off-host backup that includes private files. Stage 39 recorded the local tree as `APP_ENV=local`, `APP_DEBUG=true`, `SESSION_ENCRYPT=false`, `MAIL_MAILER=log`.
- Migrate policy for the current live database: do not run an unrestricted migrate. The documented live ledger does not include `2026_10_20_120000_add_company_lifecycle_events`, `2026_10_20_140000_add_company_admin_invitations`, or `2026_10_21_120000_repair_company_lifecycle_subject_email`. Those three stay unapproved for `meetara_hrms`.

## 3. Can wait until after launch

- Another search for byte-identical originals. The six payloads are the best available text. Waiting does not make them originals.
- Installing `spatie/laravel-permission`. The running app does not call it.
- A live `information_schema` read of `meetara_hrms` for `ON UPDATE`. The candidate already rejects every update rule other than `restrict`. The rehearsal can show the rule on the new database.
- Editing `0001`, editing payload bytes to hard-code `191`, or regenerating `database/schema/mysql-schema.sql` before the rehearsal comparison exists.
- Calling any restored file a verified original.

## Minimum approval

One decision covers the fresh-install stage. Reply with all four lines, or reject the set.

1. Rehearse the six unchanged payloads as `recovered_but_unverified` source. Do not call them originals.
2. Include the unchanged Stage 52 users candidate in that rehearsal. Keep `ON UPDATE RESTRICT` strict. Do not read it from the live database.
3. Add a labeled schema-only permission reconstruction for the five snapshot tables. Do not install Spatie. Do not copy `quarantine/stage48-review/permission.php` into `config/`.
4. Use one new empty database whose name matches `meetara_hrms_rehearsal_YYYYMMDD`, is absent before the run, and is not `meetara_hrms` or any earlier disposable name.

Payroll rules, offer-letter legal text, and the live migrate policy are separate approvals. This plan does not ask for them yet.

## Shortest sequence

| Step | Work | Gate |
|---|---|---|
| 58 | This plan | Stop here |
| Next source stage | After the four-line approval: add only the permission reconstruction, copy the six payloads and the users candidate into `database/migrations`, run one disposable rehearsal, compare schema, run `vendor/bin/phpunit` | Rehearsal matches the historical core, then the later on-disk migrations apply. Any mismatch stops the run. PHPUnit exit 0 |
| Production stage | Only after statutory and legal decisions exist | `ready()` becomes true only for categories a reviewer has decided. Offer Issue stays off until the legal record exists. Deploy env and backup checklist is done. Live migrate list is explicit |

No stage between this plan and that rehearsal. No second audit of hashes that Stage 57 already rechecked.

## Verification commands

Run these only in the source stage after approval. Do not run them for this plan.

```powershell
php -l app/Providers/AppServiceProvider.php
vendor/bin/phpunit
```

`vendor/bin/phpunit` is the sqlite `:memory:` suite. Do not use `php artisan test`.

The rehearsal is a new database named in the approval. It is not `migrate:fresh`, `db:wipe`, or a rollback against `meetara_hrms`. Expected checks on that database:

- `0001` unqualified string columns are `varchar(191)`.
- The five users columns match the candidate, and `users_tenant_id_foreign` is `ON DELETE SET NULL`. If `ON UPDATE` is not `RESTRICT`, stop.
- The five permission tables exist with `tenant_id` and without a Spatie package.
- The 91 historical unqualified strings are `varchar(191)`.
- Office wifi is recorded before payroll day counts.
- After `270000`, the result agrees with the dump except for differences already explained by later files.
- Lifecycle, invitations, offer letters, and the employee-record note exist only because the current on-disk migrations created them. The dump does not contain those tables.

## Release gates

Fresh-install gate: the approved rehearsal finishes, the comparison is saved, and `vendor/bin/phpunit` passes after the migration files land. That gate allows a new empty environment. It does not allow writing to `meetara_hrms`.

Production gate: the fresh-install gate is satisfied for any new environment, the five statutory decisions and the offer-letter legal record are in the product, the deploy configuration and backup exist, and the live migrate list excludes the three unapproved company-lifecycle files until they have their own approval.

## Safety for this stage

Created only `quarantine/stage58-review/FAST_TRACK_PLAN.md`. No active source file, migration, quarantined payload, candidate, Composer file, `.env`, cached configuration, or schema dump was changed. No database connection was opened.
