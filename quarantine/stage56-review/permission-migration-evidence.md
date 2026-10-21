# Permission migration evidence

## What this stage rechecked

- Meetara `composer.json` and `composer.lock` contain no `spatie/laravel-permission` requirement.
- `database/migrations` contains no `2026_10_19_023112_create_permission_tables.php`.
- `app/` contains no `Spatie\Permission`, `HasRoles`, or `assignRole` reference.
- `app/Models/User.php`, `app/Services/Hrms/CompanyNotifier.php`, `app/Services/Hrms/CompanyAdminInvitation.php`, and `app/Services/Hrms/EmployeeOnboarding.php` query `roles` and `model_has_roles` when those tables exist.
- The VSP-CRM stub file is still present and its SHA-256 is still `bd097ba10f37a131a9a8885ed4723387521a2f222f636b37b911e7b9ed34eec2` (6,442 bytes).
- The VSP-CRM `composer.lock` entry is still version `8.3.0`, source reference `60e8ed5b2fbf043c2264433fc2680c76b8b66aa6`.
- The dump still defines the five permission tables. `roles.tenant_id` is nullable. `model_has_permissions.tenant_id` and `model_has_roles.tenant_id` are `NOT NULL`. `role_has_permissions` has no team column. Index names include `roles_team_foreign_key_index` and `model_has_roles_team_foreign_key_index`.
- The quarantined config candidate `quarantine/stage48-review/permission.php` is unchanged at SHA-256 `91a4bfa769fe102220ad07735954c798f6f330fd9a5d6a038c878a8011e3375d`. It sets `teams` to true and `team_foreign_key` to `tenant_id`. Its header states that it is not a surviving Meetara copy.

Spatie was not installed. The stub was not copied. The candidate config was not copied to `config/`.

## Can the original Meetara migration be recovered?

No. The available Meetara evidence is the ledger name, the dump and backup table definitions, application queries against those tables, and a prior transcript read of the first portion of a published stub. The complete published PHP file was not stored. There is no quarantined payload for this name.

The VSP-CRM stub is evidence of what Spatie 8.3.0 publishes. The stub reads `config('permission.teams')` and `config('permission.column_names.team_foreign_key')`. With teams enabled it creates a nullable team column on `roles`, a non-null team column on the two model tables, and a primary key that includes that column. The index name stays `roles_team_foreign_key_index` even when the column is renamed. Those mechanics match the dump if the loaded configuration uses teams and `tenant_id`.

That match is structural. It does not establish that Meetara's published file was byte-identical to this stub, or that Meetara's config file contained only those two changes. The package default team column is `team_id`. The dump uses `tenant_id`, so some configuration other than the package default was in effect when those tables were created. The original config file is gone. The Stage 48 candidate records that inference and is not itself original source.

## What Meetara's own files establish

Meetara's Composer metadata does not establish the package version. The version and git reference exist in the VSP-CRM lock file. Application code establishes that the running app expects `roles` and `model_has_roles`, including `model_has_roles.tenant_id`, and that it talks to those tables through the query builder.

## Classification

Blocked pending additional evidence.

Evidence required before any permission migration can be promoted:

- A Meetara copy of the published migration, or a transcript Write of the complete file.
- A Meetara `composer.lock` entry, or another Meetara record, for the package version that published it.
- The original `config/permission.php`, or a record that separates observed keys from package defaults.
- A disposable rehearsal, separately approved, that compares the created tables with the dump and the earliest backup.

Until then, a fresh install cannot create the permission tables from this repository.
