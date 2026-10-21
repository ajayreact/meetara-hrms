# Permission migration options

No option below is authorized. Stage 57 does not install Spatie, does not change Composer files, and does not create a permission migration.

Current facts:

- The ledger name is `2026_10_19_023112_create_permission_tables`, dump batch 1.
- Meetara `composer.json` and `composer.lock` do not require `spatie/laravel-permission`.
- `app/` does not call Spatie classes. It queries `roles` and `model_has_roles`, including `model_has_roles.tenant_id`.
- The dump and the earliest backup contain `permissions`, `roles`, `model_has_permissions`, `model_has_roles`, and `role_has_permissions`.
- `roles.tenant_id` is nullable and has no foreign key to `tenants`. `model_has_permissions.tenant_id` and `model_has_roles.tenant_id` are NOT NULL. `role_has_permissions` has no team column. Index names include `roles_team_foreign_key_index`.
- The package default team column is `team_id`. The snapshots use `tenant_id`.
- VSP-CRM has Spatie 8.3.0, git `60e8ed5b2fbf043c2264433fc2680c76b8b66aa6`, stub SHA-256 `bd097ba10f37a131a9a8885ed4723387521a2f222f636b37b911e7b9ed34eec2`. That stub emits this shape when teams are on and `team_foreign_key` is `tenant_id`.
- `quarantine/stage48-review/permission.php` sets those two keys. Its hash is `91a4bfa769fe102220ad07735954c798f6f330fd9a5d6a038c878a8011e3375d`. It is marked unverified and is not a recovered Meetara config file.

## Option A — Keep searching for the original Meetara file

Evidence gaps: no full published migration, no Meetara lock entry, and no original `config/permission.php`.

Package and configuration: no Composer change and no new config file. The application keeps using the query builder.

Tenant and team: nothing new is created. Existing snapshots already show `tenant_id` as the team column. A search does not confirm or change that.

Risks: the search can fail again. Fresh install stays unable to create the five tables. This option does not risk installing the wrong package.

This is the only option that does not invent a file. It is still not a decision to start a new search in this stage.

## Option B — Reconstruct from quarantined partial evidence and the snapshots

Evidence gaps: the transcript read is partial. There is no payload with `up()` and `down()`. A reconstruction would be written from the stub's behavior plus the dump, not from a recovered Meetara file. The Stage 48 config candidate fills unknown keys with package defaults. Those defaults were not re-read from a Meetara copy.

Package and configuration: publishing a migration that calls `config('permission.teams')` cannot run unless Spatie is installed and a config file is loaded. Installing the package is a separate decision. Writing a hand-built migration that does not call Spatie would be a new schema file, not a restoration of the historical publisher.

Tenant and team: the snapshots support nullable `roles.tenant_id`, non-null model-table `tenant_id`, and no team column on `role_has_permissions`. A reconstruction can target that shape. It would still be an inference if the original PHP differed in index names, foreign keys, or column order.

Risks: a plausible schema can pass a dump comparison and still be the wrong file. `down()` that drops the five tables deletes role assignments. Authorizing this option would need a written statement that the result is a reconstruction, not an original.

## Option C — Adopt the VSP-CRM Spatie 8.3.0 stub as a documented reconstruction

Evidence gaps: the stub is another project's vendor file. Matching index names and `tenant_id` show compatibility. They do not show that Meetara published that stub unchanged. Meetara has no lock entry for 8.3.0.

Package and configuration: use would require adding `spatie/laravel-permission` at a reviewed version, publishing the stub under the historical filename, and loading teams with `team_foreign_key` `tenant_id`. The Stage 48 candidate is not approved for `config/permission.php`. The package would also register its own models and middleware. The current application does not use those classes.

Tenant and team: with the candidate config, the stub creates the team column as `tenant_id` and does not add a foreign key from `roles.tenant_id` to `tenants`. That matches the snapshots. The package default `team_id` would not match the snapshots. Teams must be on before the migration runs, or the tables would be created without `tenant_id`.

Risks: installing a package to recreate historical tables changes the application dependency set. A later Spatie upgrade could publish a different stub. Adopting the stub would need to be labeled as a new reconstruction pinned to 8.3.0 and that stub hash, not as recovery of the original Meetara file.

## Decision required

Choose A, B, or C in a later stage, or reject all three. Stage 57 does not choose. All three remain not authorized. Until a file exists, `2026_10_19_100000_create_hrms_tables` can still be discussed separately, and a fresh install still cannot create `roles` or `model_has_roles`.
