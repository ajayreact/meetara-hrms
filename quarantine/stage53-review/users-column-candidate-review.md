# Stage 53 users-column candidate review

Reviewed file, unchanged:

`quarantine/stage52-review/candidates/2026_10_19_010000_add_users_company_columns.php`

SHA-256 `d32a3c27f6ac7ff37a49c728a6af612f55e34d70bde0b1700cfae5e08af4625b`.

The file was not run and was not copied to `database/migrations`. Its five column definitions use explicit SQL from the snapshots. They do not use Laravel's default string length, so the `varchar(191)` gap does not change those five definitions.

## Behavior checked in source

- The users table, MySQL driver, `id`, `name`, and all five columns are read before the only `ALTER TABLE`.
- One to four present columns throw before DDL.
- A present column whose type, nullability, default, collation, index, or foreign key does not match throws before DDL.
- All five compatible columns return before DDL.
- All five absent columns are added in one statement, with `users_tenant_id_index` and `users_invite_token_unique`.
- `down()` throws and does not drop columns or keys.

## Foreign key

When a foreign key on `tenant_id` exists, the candidate accepts it only when all of these match:

- Name `users_tenant_id_foreign`.
- Local column `tenant_id`.
- Referenced table `tenants` and referenced column `id`.
- Delete rule `set null`.
- Update rule exactly `restrict`.

A missing foreign key is accepted. This repair is intended to run before `tenants` exists. `2026_10_19_100000_create_hrms_tables` adds the key. More than one foreign key on the column is rejected. `NO ACTION` is rejected.

## Gap

The snapshots omit `ON UPDATE`. MySQL documents that omission as `RESTRICT`, and the candidate requires the information_schema value `restrict`. This stage did not query a database, so that spelling is still inferred. If a future run observes another spelling, the candidate stops before DDL when the columns are already present. That is the safe failure. It is not a reason to accept another update rule now.

The candidate is safe enough for a separate review of these five columns. It is not approved for `database/migrations`, and it does not make a full fresh install reproducible.
