# Stage 52 users-column candidate audit

Stage 51 file, unchanged, SHA-256 `bc1283747b84e80d5b593fed52b6c8a073a8b389af1fd14606159f729f133d6c`:

`quarantine/stage51-review/candidates/2026_10_19_010000_add_users_company_columns.php`

Stage 52 revision, SHA-256 `d32a3c27f6ac7ff37a49c728a6af612f55e34d70bde0b1700cfae5e08af4625b`:

`quarantine/stage52-review/candidates/2026_10_19_010000_add_users_company_columns.php`

`php -l` reported no syntax errors. The file was not executed and was not copied to `database/migrations`.

## Filename order

Lexical order is `0001_01_01_000000_create_users_table`, then `2026_10_19_010000_add_users_company_columns`, then `2026_10_19_023112_create_permission_tables`, then `2026_10_19_100000_create_hrms_tables`.

## What the Stage 51 candidate already does

- It reads the `users` table, driver, `id`, `name`, all five columns, indexes, and foreign keys before the only `ALTER TABLE`.
- One to four of the five columns throws before DDL.
- A present column with a different type, nullability, default, collation, index, or foreign key throws before DDL.
- All five compatible columns return before DDL.
- All five absent columns are added in one `ALTER TABLE` whose SQL is the snapshot text: `bigint unsigned` null, `tinyint(1) NOT NULL DEFAULT '0'`, `varchar(80)` null, and two nullable timestamps, plus `users_tenant_id_index` and `users_invite_token_unique`.
- Those five definitions do not use Laravel's default string length. `invite_token` is explicitly `varchar(80)` in both snapshots and in the Stage 46 diff.
- The candidate requires exactly one index on `tenant_id` and exactly one index on `invite_token`, with those names, column lists, and uniqueness flags. An extra index on any of the five columns fails.
- A non-MySQL driver throws before DDL. A missing `users` table, missing `id` or `name`, or a table collation other than `utf8mb4_unicode_ci` throws before DDL.
- `down()` throws. It does not drop columns, indexes, or foreign keys.
- The post-check after the single `ALTER TABLE` throws without a second schema change if the result does not match.

## What Stage 51 accepts too broadly

`foreignKeyProblems()` treats update rules `restrict` and `no action` as compatible. `foreign-key-audit.md` records why `no action` is not the snapshot rule. That check is the reason for the revision.

## Stage 52 change

The revision requires the update rule to be exactly `restrict`. Every other update rule, including `no action`, fails before DDL when the columns are already present. The revision still does not create or drop the foreign key.

The five column definitions were already supported by both snapshots. The revision does not invent a column type.

## Residual limits

The candidate does not prove the information_schema spelling until it runs. A spelling other than `restrict` stops the migration. Column order is enforced on the add path with `AFTER id` and `AFTER name`. An existing compatible set in a different order is left unchanged, because order is not a different type, default, nullability, index, or foreign key.

A failed post-check means the one `ALTER TABLE` was issued. `down()` will not undo it. The operator has to inspect the table before any retry.

This candidate can be considered for a future disposable rehearsal of these five columns after explicit approval. It does not make a full fresh installation ready. The unqualified `string()` length gap in `0001` and the historical payloads remains.
