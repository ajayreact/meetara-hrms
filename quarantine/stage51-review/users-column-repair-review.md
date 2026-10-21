# Stage 51 users-column repair review

The candidate is `quarantine/stage51-review/candidates/2026_10_19_010000_add_users_company_columns.php`. It is not in `database/migrations`. `0001_01_01_000000_create_users_table.php` was not edited. Its body still creates only the stock Laravel users, password reset, and session tables.

The filename sorts after `0001_01_01_000000_create_users_table` and before `2026_10_19_023112_create_permission_tables` and `2026_10_19_100000_create_hrms_tables`.

## What each source supports

| Fact | Schema dump | Earliest backup | Stage 46 diff | Application code |
|---|---|---|---|---|
| `tenant_id` is `bigint unsigned`, nullable, default NULL | Yes | Yes | `foreignId('tenant_id')->nullable()->index()` | Reads and writes the column. Does not declare SQL. |
| Index `users_tenant_id_index` on `tenant_id` only, not unique | Yes | Yes | The diff calls `index()` and does not name the index | No |
| Foreign key `users_tenant_id_foreign` to `tenants(id)` `ON DELETE SET NULL` | Yes | Yes | No. The quarantined `100000` payload adds it after `tenants` exists | No |
| `is_platform_admin` is `tinyint(1) NOT NULL DEFAULT '0'` | Yes | Yes | `boolean('is_platform_admin')->default(false)` | Casts boolean and writes `false` |
| `invite_token` is `varchar(80)`, nullable, default NULL, collation `utf8mb4_unicode_ci` | Yes | Yes | `string('invite_token', 80)->nullable()->unique()` | Writes a 40-character token or null. Length 80 is the column, not the generator |
| Unique key `users_invite_token_unique` | Yes | Yes | The diff calls `unique()` and does not name the index | No |
| `invited_at` and `invite_accepted_at` are nullable timestamps with default NULL, and no index | Yes | Yes | `timestamp(...)->nullable()` | Casts datetime. Account status treats invited and not accepted as blocked |

Dump and backup `CREATE TABLE users` lines for these five columns and their keys are the same, including column order: `id`, `tenant_id`, `is_platform_admin`, `name`, `invite_token`, `invited_at`, `invite_accepted_at`, then `email`. `api_token varchar(64)` is also in both snapshots and comes from the quarantined `120000` payload, not from this repair.

The snapshots omit `ON UPDATE` on `users_tenant_id_foreign`. The candidate accepts an existing foreign key only when its delete rule is `SET NULL` and its update rule is `RESTRICT` or `NO ACTION`. A missing foreign key is compatible, because this file runs before `tenants` exists.

## Candidate behavior

- Read all five columns, their indexes, and foreign keys before DDL.
- All five absent, on MySQL, with `users.id` and `users.name` present and table collation `utf8mb4_unicode_ci`: one `ALTER TABLE` adds the five columns and the two indexes. It does not add the foreign key.
- All five present and compatible, including the optional foreign key when it matches the snapshot: no DDL.
- One to four present: throw before DDL.
- Any present column, index, or foreign key that does not match: throw before DDL. No silent change.
- Any driver other than `mysql`: throw before DDL. The snapshots do not define SQLite types.
- `down()` throws. Dropping the columns would remove company membership and invitation state, including after a no-op run on a database that already had them.

## Confidence

The five SQL definitions and the two index names are snapshot-verified. The foreign key is snapshot-verified and belongs to `100000`, so it is intentionally absent from this candidate. The Stage 46 diff agrees with the types and does not by itself prove the historical `0001` edit still exists. This file is a new guarded repair, not that edit.

Unresolved: the snapshots do not print an `ON UPDATE` clause. Unqualified `string()` columns elsewhere in the historical payloads are a separate `varchar(191)` question and do not affect these five definitions, because `invite_token` has an explicit length and the other four are not strings.
