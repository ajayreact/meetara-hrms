# Stage 53 `0001` schema discrepancy review

`database/migrations/0001_01_01_000000_create_users_table.php` was not edited. Its SHA-256 remains `10d79883bc08ce46510f9ad8b1830b4d06d586dc56ae2c8bac3b3e491f69a90f`. The cache and jobs `0001` files were not edited either.

## What the snapshots establish

Both `database/schema/mysql-schema.sql` and `meetara_hrms-before-office-networks.sql` store the same types for the unqualified `string()` columns in these files:

| File | Column | Blueprint | Both snapshots |
|---|---|---|---|
| users | `users.name` | `string('name')` | `varchar(191) NOT NULL` |
| users | `users.email` | `string('email')->unique()` | `varchar(191) NOT NULL`, unique `users_email_unique` |
| users | `users.password` | `string('password')` | `varchar(191) NOT NULL` |
| users | `password_reset_tokens.email` | `string('email')->primary()` | `varchar(191) NOT NULL`, primary key |
| users | `password_reset_tokens.token` | `string('token')` | `varchar(191) NOT NULL` |
| users | `sessions.id` | `string('id')->primary()` | `varchar(191) NOT NULL`, primary key |
| cache | `cache.key` | `string('key')->primary()` | `varchar(191) NOT NULL`, primary key |
| cache | `cache_locks.key` | `string('key')->primary()` | `varchar(191) NOT NULL`, primary key |
| cache | `cache_locks.owner` | `string('owner')` | `varchar(191) NOT NULL` |
| jobs | `jobs.queue` | `string('queue')->index()` | `varchar(191) NOT NULL`, index `jobs_queue_index` |
| jobs | `job_batches.id` | `string('id')->primary()` | `varchar(191) NOT NULL`, primary key |
| jobs | `job_batches.name` | `string('name')` | `varchar(191) NOT NULL` |
| jobs | `failed_jobs.uuid` | `string('uuid')->unique()` | `varchar(191) NOT NULL`, unique |

These lengths are snapshot facts. The files themselves still contain unqualified `string()`. The recorded `Schema::defaultStringLength(191)` edit explains the mapping. The current provider does not contain that call, so running these files today would create `varchar(255)`.

Columns that are not part of the discrepancy, because the blueprint or Laravel helper sets the length and both snapshots agree:

- `sessions.ip_address` is `string('ip_address', 45)` and `varchar(45)`.
- `users.remember_token` comes from `rememberToken()` and is `varchar(100)`.

`users.invite_token` is not created by `0001`. Both snapshots store it as `varchar(80)`. That definition belongs to the quarantined users-column candidate.

## What matters for a fresh HRMS install

`users.name`, `users.email`, and `users.password` are part of the account table the HRMS columns attach to. Their length is part of the fresh-install schema. Cache, jobs, job batches, failed jobs, sessions, and password-reset tokens are framework tables. They have the same length discrepancy and the same recorded cause. They are not HRMS business tables.

Editing any of these `0001` files would change a migration that the schema dump already records as applied. A database that already ran them would not pick up an edited `0001`. The recorded provider setting changes the length for a new install without editing those files.

No change was made to `0001`.
