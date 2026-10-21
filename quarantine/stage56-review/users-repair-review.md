# Users-column repair review

File reviewed, not modified:

`quarantine/stage52-review/candidates/2026_10_19_010000_add_users_company_columns.php`

SHA-256 `d32a3c27f6ac7ff37a49c728a6af612f55e34d70bde0b1700cfae5e08af4625b`, 13,808 bytes. This matches the Stage 52 record. The Stage 51 predecessor remains at `bc1283747b84e80d5b593fed52b6c8a073a8b389af1fd14606159f729f133d6c` and still accepts a looser update rule. The Stage 52 file is the review target. Neither file is verified or approved. Neither file was moved.

This candidate is not one of the seven historical migration names. The schema dump's 18 rows do not include `2026_10_19_010000_add_users_company_columns`. The five columns were originally added by an edit inside `0001_01_01_000000_create_users_table`. The current `0001` file is stock Laravel, hash `10d79883bc08ce46510f9ad8b1830b4d06d586dc56ae2c8bac3b3e491f69a90f`, and does not contain them. The Stage 46 diff that records the blueprint calls is unchanged.

## Five columns

The candidate's single `ALTER TABLE` adds:

- `tenant_id` bigint unsigned null after `id`, plus `users_tenant_id_index`
- `is_platform_admin` tinyint(1) not null default 0 after `tenant_id`
- `invite_token` varchar(80) null after `name`, plus `users_invite_token_unique`
- `invited_at` timestamp null
- `invite_accepted_at` timestamp null

Those definitions match the dump and the earliest backup. The foreign key is intentionally absent. `2026_10_19_100000` adds `users_tenant_id_foreign` after `tenants` exists.

## Preconditions and failure behavior

Before any DDL, `up()` throws when:

- `users` is missing
- the driver is not `mysql`
- `id` or `name` is missing
- some of the five columns are present and some are absent
- all five are present but a column, index, or foreign key is incompatible
- a column is absent while an index or foreign key on that column already exists
- the table collation is not `utf8mb4_unicode_ci`

If all five columns and their indexes already match, `up()` returns without DDL.

If none of the five columns exist, it runs one `ALTER`. A post-check then throws if the result is incompatible. On MySQL that `ALTER` may already be committed, so the post-check stops a retry and does not undo the statement.

`down()` always throws. It does not drop the columns.

## Foreign key

When no `tenant_id` foreign key is present, the candidate treats that as compatible. That is the expected state before `100000`.

When one foreign key is present, it must be named `users_tenant_id_foreign`, cover `tenant_id`, reference `tenants.id`, use delete rule `set null`, and use update rule `restrict`. More than one foreign key is rejected. Any other update spelling is rejected.

The delete rule `SET NULL` is printed in both snapshots. The update rule `RESTRICT` is inferred. Both snapshots omit `ON UPDATE`, and MySQL's default for an omitted `ON UPDATE` clause is `RESTRICT`. This project has not read the live `information_schema` spelling. A future rehearsal that sees a different spelling must stop before DDL. The candidate already does that, because it accepts only `restrict`.

## Rehearsal position

The candidate can be considered for a later disposable rehearsal of these five columns only. That rehearsal needs its own approval, a new database name, and a comparison with the two snapshots. It does not authorize promotion, does not authorize `meetara_hrms`, and does not make a fresh install complete. The permission migration and the six historical payloads would still be unresolved.
