# Stage 51 permission migration review

No permission migration candidate was created. Spatie was not installed. `quarantine/stage48-review/permission.php` was not copied to `config/`. Application authorization was not changed.

## Schema compatibility

The Spatie 8.3.0 stub at `D:\wamp64\www\VSP-CRM\vendor\spatie\laravel-permission\database\migrations\create_permission_tables.php.stub` is config-driven. Its SHA-256 remains `bd097ba10f37a131a9a8885ed4723387521a2f222f636b37b911e7b9ed34eec2`.

When `permission.teams` is true and `column_names.team_foreign_key` is `tenant_id`, the stub creates:

- `permissions` with unique `(name, guard_name)` and no team column.
- `roles` with nullable `tenant_id`, index `roles_team_foreign_key_index`, and unique `(tenant_id, name, guard_name)`. The stub does not add a foreign key from `roles.tenant_id` to `tenants`.
- `model_has_permissions` and `model_has_roles` with non-null `tenant_id` inside the primary key, team indexes, and cascade foreign keys to `permissions` or `roles`.
- `role_has_permissions` with no team column.

`database/schema/mysql-schema.sql` and `D:\wamp64\backups\meetara_hrms-before-office-networks.sql` match that shape. The package default team column name is `team_id`, which does not match either snapshot.

`down()` drops the five tables. That is destructive and is not a recovery path.

## Historical authenticity

The published Meetara file `2026_10_19_023112_create_permission_tables.php` is still absent. The saved transcript excerpt covers the first 80 lines and matches the stub. The rest of the published file was not saved. Schema agreement does not prove the published file was byte-for-byte the stub.

`quarantine/stage48-review/permission.php` sets `teams` true and `team_foreign_key` `tenant_id`. Its other keys are the 8.3.0 published defaults. It is not a recovered Meetara config file. It references Spatie classes. `composer.json` in this project does not require `spatie/laravel-permission`, so that config cannot be loaded here.

## Application path

`App\Auth\CompanyRole` and the query builder read and write `roles` and `model_has_roles`. The application does not call Spatie's authorization API. A fresh install still needs compatible `roles` and `model_has_roles` tables. `permissions` and `role_has_permissions` are in both snapshots. Current `app/` code does not query them.

## Whether a candidate can be prepared

The stub plus those two config values is enough to prepare a schema-compatible candidate later. It is not enough to label that candidate the original Meetara migration. This stage does not prepare the file, because copying the stub into this project would still be an unverified reconstruction and the required package is not installed.
