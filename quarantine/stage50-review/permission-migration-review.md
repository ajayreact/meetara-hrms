# Stage 50 permission migration review

## Three separate facts

1. The five permission tables in `database/schema/mysql-schema.sql` match `D:\wamp64\backups\meetara_hrms-before-office-networks.sql` and match the Spatie 8.3.0 stub when `teams` is true and `team_foreign_key` is `tenant_id`.
2. The original Meetara file `2026_10_19_023112_create_permission_tables.php` has not been proven byte-for-byte identical to that stub. Only the first 80 lines were saved, and they match the stub. The stub was not copied into this project.
3. The current application does not call Spatie's authorization API. It uses `App\Auth\CompanyRole` and query-builder reads and writes of `roles` and `model_has_roles`. A fresh install still needs compatible `roles` and `model_has_roles` tables. `permissions` and `role_has_permissions` are in the historical schema. Application code under `app/` does not query them.

## Stub

Path: `D:\wamp64\www\VSP-CRM\vendor\spatie\laravel-permission\database\migrations\create_permission_tables.php.stub`

SHA-256: `bd097ba10f37a131a9a8885ed4723387521a2f222f636b37b911e7b9ed34eec2`

Bytes: 6442

Version evidence: `D:\wamp64\www\VSP-CRM\composer.lock` name `spatie/laravel-permission`, version 8.3.0, git `60e8ed5b2fbf043c2264433fc2680c76b8b66aa6`.

`down()` drops `role_has_permissions`, `model_has_roles`, `model_has_permissions`, `roles`, and `permissions`. That is destructive. It is not a recovery procedure.

## Candidate config

`quarantine/stage48-review/permission.php`

SHA-256: `91a4bfa769fe102220ad07735954c798f6f330fd9a5d6a038c878a8011e3375d`

Bytes: 1856

It sets `teams` true and `team_foreign_key` `tenant_id`. Table names match the dump. It is not a byte-for-byte copy of the lost Meetara config. It references Spatie classes, and Spatie is not a dependency of this project. It was not installed into `config/`.

## Table agreement with teams enabled and team key `tenant_id`

| Table | Agreement |
|---|---|
| `permissions` | `id`, `name`, `guard_name`, timestamps. Unique `(name, guard_name)`. No team column. |
| `roles` | Nullable `tenant_id`, `roles_team_foreign_key_index`, unique `(tenant_id, name, guard_name)`. No foreign key from `tenant_id` to `tenants`. |
| `model_has_permissions` | `permission_id` cascades to `permissions`. `tenant_id` not null. Primary key `(tenant_id, permission_id, model_id, model_type)`. |
| `model_has_roles` | `role_id` cascades to `roles`. `tenant_id` not null. Primary key `(tenant_id, role_id, model_id, model_type)`. |
| `role_has_permissions` | Both foreign keys cascade. Primary key `(permission_id, role_id)`. No team column. |

The package default `team_id` does not match these tables.
