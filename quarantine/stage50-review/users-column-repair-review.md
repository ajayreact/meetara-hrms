# Stage 50 users-column repair review

The migration file was not created. `database/migrations/0001_01_01_000000_create_users_table.php` was not edited. SHA-256 of that file on this review: `10d79883bc08ce46510f9ad8b1830b4d06d586dc56ae2c8bac3b3e491f69a90f`. It does not contain the five columns.

Source of the candidate lines: `quarantine/stage46-review/0001_users_columns.unverified.diff` (1,173 bytes, SHA-256 `9ed7e02748dbbe22ba1b8b9691f3d360cb9353a3f27b993a7179e54d2e762180`). Those lines are the transcript read-back. They are candidate definitions.

| Column | Candidate in the diff | Dump and earliest backup | Index or foreign key | Confidence |
|---|---|---|---|---|
| `tenant_id` | `foreignId('tenant_id')->nullable()->index()` | `bigint unsigned` null. Key `users_tenant_id_index`. | The index name is Laravel's default for that call. It is not written in the diff. The foreign key `users_tenant_id_foreign` to `tenants(id)` `ON DELETE SET NULL` is in the quarantined `100000` payload, not in this diff. | Candidate. Dump agrees with the call plus the later foreign key. |
| `is_platform_admin` | `boolean('is_platform_admin')->default(false)` | `tinyint(1) NOT NULL DEFAULT 0` | None in the diff or the dump. | Candidate. Dump agrees. |
| `invite_token` | `string('invite_token', 80)->nullable()->unique()` | `varchar(80)` null. Unique key `users_invite_token_unique`. | Unique name is Laravel's default. It is not written in the diff. | Candidate. Dump agrees. |
| `invited_at` | `timestamp('invited_at')->nullable()` | nullable timestamp | None. | Candidate. Dump agrees. |
| `invite_accepted_at` | `timestamp('invite_accepted_at')->nullable()` | nullable timestamp | None. | Candidate. Dump agrees. |

`D:\wamp64\backups\meetara_hrms-before-office-networks.sql` has the same five column definitions as the schema dump, plus `api_token` from the later `120000` payload.

Application use, which does not prove the migration body:

- `app/Models/User.php` hides `invite_token` and `tenant_id`, and casts `is_platform_admin`, `invited_at`, and `invite_accepted_at`.
- `EmployeeProvisioner` writes all five when it creates a login.
- `CompanyAdminInvitation` writes `tenant_id`, sets `is_platform_admin` false, clears `invite_token` and `invited_at`, and sets `invite_accepted_at`.
- `AccountStatus` blocks a user with `invited_at` set and `invite_accepted_at` null.

## Required future behavior

Do not apply this until a later review creates the file.

- All five absent: add all five from the candidate lines, then stop for a schema check before `2026_10_19_100000_create_hrms_tables`.
- All five present with compatible definitions: issue no DDL.
- Only some present: stop before DDL and leave the database unchanged.
- Any present column with an incompatible definition: stop before DDL. Do not alter it.
- Filename sorts after `0001_01_01_000000_create_users_table` and before `2026_10_19_100000_create_hrms_tables`.
- `0001` stays unchanged.

Gap: the diff has blueprint calls, not SQL type strings or index names. Those SQL details are inferred from the dump and from Laravel's usual names. They are not a second copy of the original migration.
