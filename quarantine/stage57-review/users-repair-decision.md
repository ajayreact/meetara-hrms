# Users-column repair decision

Candidate, rechecked and not modified:

`quarantine/stage52-review/candidates/2026_10_19_010000_add_users_company_columns.php`

SHA-256 `d32a3c27f6ac7ff37a49c728a6af612f55e34d70bde0b1700cfae5e08af4625b`.

This file is not a historical ledger entry. The five columns were originally added by editing `0001_01_01_000000_create_users_table`. The active `0001` file does not contain them. The candidate is a proposed later migration. It is not verified and not approved.

## Five columns

One MySQL `ALTER TABLE` adds:

- `tenant_id` bigint unsigned null after `id`, with `users_tenant_id_index`
- `is_platform_admin` tinyint(1) not null default 0 after `tenant_id`
- `invite_token` varchar(80) null after `name`, with `users_invite_token_unique`
- `invited_at` timestamp null
- `invite_accepted_at` timestamp null

It does not add `users_tenant_id_foreign`. That key belongs to the `100000` payload, after `tenants` exists.

## Preconditions

`up()` throws before DDL when `users` is missing, the driver is not `mysql`, `id` or `name` is missing, the five-column set is partial, an index or foreign key exists for an absent column, or the table collation is not `utf8mb4_unicode_ci`.

If all five columns are present and incompatible, it throws and does not change them. If they already match, it returns without DDL. If they are all absent, it runs one `ALTER` and then checks the result. A failed post-check cannot undo a committed MySQL `ALTER`.

`down()` always throws. It does not drop the columns.

## Foreign-key rules

No foreign key is compatible, because this file is meant to run before `tenants` exists.

One existing foreign key must be named `users_tenant_id_foreign`, reference `tenants.id`, use delete rule `set null`, and use update rule `restrict`. Any other update spelling is a failure. More than one foreign key is a failure.

`ON DELETE SET NULL` is printed in the schema dump and the earliest backup. `ON UPDATE RESTRICT` is not printed. MySQL's default for an omitted `ON UPDATE` clause is `RESTRICT`, and both snapshots omit the clause. The spelling was never read from `information_schema`. The Stage 52 candidate rejects every update rule other than `restrict`, including `no action`.

## Evidence that would resolve the update rule

A read-only `information_schema` query on a database that already has `users_tenant_id_foreign`, using a connection approved for that read, reporting the exact `UPDATE_RULE` and `DELETE_RULE`. The live database `meetara_hrms` is not approved for that read by this stage. A disposable copy is not approved either.

If that reading is `RESTRICT`, the candidate's check matches the snapshots. If it is any other spelling, the candidate must stay unchanged until a later review decides what to do. Do not loosen the check to make a rehearsal pass.

## Decision

The candidate stays quarantined. The five column definitions are strong enough to review. The update-rule assumption is not strong enough to promote the file or to call it verified.

Approval that is not granted now would have to say, separately:

- whether a disposable read may check `UPDATE_RULE`
- whether this new migration name may be rehearsed after that reading
- whether it may later be copied into `database/migrations`

Editing active `0001` is not the repair path. Stage 57 does not edit or promote the candidate.
