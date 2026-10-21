# Reconstruction plan

Review only. Nothing in this plan is approved for promotion, installation, or a database rehearsal.

## Already in the active tree

`Schema::defaultStringLength(191);` is in `AppServiceProvider::boot()`.

- Classification: supported by the transcript insertion and applied in Stage 55.
- Limit: the complete original provider was not recovered. The call is present. The historical view composer is not, and it is not part of this plan.
- Further action: none in a reconstruction stage. Do not edit the provider again to chase the old file.

## Six quarantined payloads

Files under `quarantine/stage44-historical-migration-payloads/` for `100000`, `120000`, `130000`, both `140000` names, and `160000`.

- Classification: supported by partial transcript evidence and by schema-dump and backup agreement. Promotion is inferred and requires approval.
- They are `recovered_but_unverified`, not `verified_original`.
- Evidence required before promotion: an explicit decision to copy these bytes into `database/migrations`, a new disposable database name, and a rehearsal that compares the created tables with both snapshots, including the later on-disk changes that explain dump-versus-backup differences. The `120000` `plan_feature` update has to be accepted as part of that payload.
- Do not edit the payload bytes to hard-code `191`. The provider setting covers unqualified `string()` calls.

## Users-column candidate

`quarantine/stage52-review/candidates/2026_10_19_010000_add_users_company_columns.php`.

- Classification: inferred and requiring approval.
- The column definitions are supported by both snapshots and by the Stage 46 blueprint diff. The migration filename is not a historical ledger entry. `ON UPDATE RESTRICT` is inferred.
- Evidence required before any rehearsal: explicit approval of this candidate, a disposable database, and a stop if the observed update rule is not `restrict`.
- Evidence required before promotion into `database/migrations`: that rehearsal result, plus a decision that a new migration name is acceptable for columns the historical tree created inside `0001`.
- Do not edit the active `0001` file. That would change a recorded migration and would not alter a database that has already run it.

## Permission migration

- Classification: blocked pending additional evidence.
- The dump, the backup, and the application queries support the five-table shape with `tenant_id`. The VSP-CRM 8.3.0 stub shows a mechanism that can produce that shape. Meetara does not contain the package, the published migration, or the original config file.
- Evidence required before a file can be written: a recovered Meetara migration body, a Meetara package-version record, and the original permission config or a reviewed substitute that is clearly marked as a substitute. A structural match to the stub is not that evidence.
- Do not install Spatie as part of closing this gap.

## Fresh-install rehearsal

- Classification: blocked pending the decisions above.
- A rehearsal of the current tree, with no promoted files, would exercise the guarded no-op path. It would not reproduce the 18-row historical schema.
- A rehearsal that promotes the six payloads without the users columns would fail in `100000`.
- A rehearsal that also includes the users candidate would still have no permission tables.
- No database name is approved for that work. Existing disposable databases from earlier stages stay untouched.

## Explicitly out of scope

- Changing `AppServiceProvider.php`.
- Editing active migrations, the schema dump, Composer files, `.env`, or cached configuration.
- Copying quarantine files into the active tree.
- Installing or upgrading packages.
- Opening, creating, migrating, seeding, resetting, or dropping a database.
