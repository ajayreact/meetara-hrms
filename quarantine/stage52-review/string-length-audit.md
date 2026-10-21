# Stage 52 string-length audit

The schema snapshots and the current Laravel default do not describe the same length for an unqualified `string()`. This stage did not call `Schema::defaultStringLength()` and did not edit migrations.

`Illuminate\Database\Schema\Builder::$defaultStringLength` is 255. No application or database file calls `defaultStringLength(191)`. `config/database.php` sets charset `utf8mb4` and collation `utf8mb4_unicode_ci`. Those settings do not change the string length.

The static comparison is `quarantine/stage52-review/static-string-and-constraint-audit.php`. It reads PHP and SQL text only. Its full row listing is `quarantine/stage52-review/static-string-and-constraint-audit.txt`. The six payload hashes match Stage 50 and Stage 51.

## Result

The six payloads contain 99 `string()` calls.

| Class | Count | Meaning |
|---|---|---|
| Explicit length, both snapshots match | 8 | The blueprint length is the snapshot length. |
| Unqualified `string()`, both snapshots are `varchar(191)` | 91 | The stored length is 191. The blueprint does not contain 191. Current Laravel would create `varchar(255)`. |

No payload `string()` call disagreed with both snapshots on length. No call was left unclassified.

## Explicit lengths

These definitions do not depend on the framework default. Confidence is high for the SQL length. The payload file is still not a proven original.

| Column | Blueprint | Dump and backup |
|---|---|---|
| `users.api_token` | `string('api_token', 64)->nullable()->unique()` | `varchar(64)` null, unique `users_api_token_unique` |
| `employees.pan` | `string('pan', 10)->nullable()` | `varchar(10)` null |
| `employees.uan` | `string('uan', 20)->nullable()` | `varchar(20)` null |
| `employees.esi_number` | `string('esi_number', 20)->nullable()` | `varchar(20)` null |
| `holidays.calendar` | `string('calendar', 10)->default('india')` | `varchar(10) NOT NULL DEFAULT 'india'` |
| `employees.holiday_calendar` | `string('holiday_calendar', 10)->default('india')` | `varchar(10) NOT NULL DEFAULT 'india'` |
| `attendance_logs.check_in_ip` | `string('check_in_ip', 45)->nullable()` | `varchar(45)` null |
| `attendance_logs.verified_as` | `string('verified_as', 20)->nullable()` | `varchar(20)` null |

`2026_10_19_140000_add_payroll_day_counts` has no `string()` call. Its day-count columns are `decimal(5,1)` in the payload and in both snapshots.

## Unqualified `string()` columns

For every column below, the historical blueprint is `string()` with no length. The dump and the backup both store `varchar(191)` with the nullability and character default written in that blueprint. No later migration changes those lengths, except the two payroll defaults in the next section, which stay `varchar(191)`.

The length 191 is snapshot evidence. It is not source evidence of a configured default. The cause is unresolved Laravel configuration. Confidence in the stored SQL is high. Confidence that the current source would reproduce it is none. Each of these columns, and each migration that creates one, is `unverified` for a reproducible fresh installation.

`2026_10_19_100000_create_hrms_tables`: `tenants.name`, `tenants.slug`, `tenants.email`, `tenants.phone`, `tenants.logo_path`, `tenants.timezone`, `tenants.status`, `plans.name`, `plans.slug`, `features.key`, `features.name`, `features.category`, `subscriptions.status`, `departments.name`, `departments.code`, `designations.name`, `locations.name`, `shifts.name`, `shifts.type`, `employees.employee_code`, `employees.first_name`, `employees.last_name`, `employees.email`, `employees.phone`, `employees.gender`, `employees.employment_type`, `employees.work_mode`, `employees.status`, `employment_histories.title`, `employment_histories.department_name`, `employment_histories.designation_name`, `employee_skills.name`, `employee_skills.level`, `document_types.name`, `employee_documents.title`, `employee_documents.file_path`, `employee_documents.original_name`, `letter_templates.name`, `letter_templates.type`, `generated_letters.type`, `generated_letters.title`, `onboarding_templates.name`, `onboarding_template_tasks.title`, `onboarding_template_tasks.assignee_role`, `onboardings.status`, `onboarding_tasks.title`, `onboarding_tasks.assignee_role`, `onboarding_tasks.status`, `exit_requests.status`, `exit_tasks.title`, `exit_tasks.assignee_role`, `exit_tasks.status`, `leave_types.name`, `leave_types.code`, `leave_policies.name`, `leave_policies.accrual`, `leave_requests.status`, `leave_requests.payroll_status`, `holidays.name`, `attendance_logs.work_mode`, `attendance_logs.status`, `attendance_logs.payroll_status`, `overtime_rules.name`, `overtime_entries.status`, `overtime_entries.payroll_status`, `notifications.type`.

`2026_10_19_120000_create_remaining_phase_tables`: `posts.kind`, `posts.title`, `polls.question`, `poll_options.label`, `payroll_runs.status`, `payroll_items.payroll_status`, `loans.type`, `loans.status`, `expenses.category`, `expenses.status`, `expenses.payroll_status`, `perks.name`, `perk_claims.status`, `perk_claims.payroll_status`, `tax_declarations.regime`, `tax_declarations.section`, `tax_declarations.status`, `tax_declarations.proof_path`.

`2026_10_19_140000_add_office_wifi_and_wfh`: `wfh_requests.reason`, `wfh_requests.status`.

`2026_10_19_160000_add_profile_details_and_tickets`: `tickets.category`, `tickets.title`, `tickets.priority`, `tickets.status`, `tickets.attachment_path`.

## Defaults that a later migration changes

`payroll_runs.status` and `payroll_items.payroll_status` are unqualified `string()` calls with default `processed`. The backup stores `varchar(191) NOT NULL DEFAULT 'processed'`. The dump stores `varchar(191) NOT NULL DEFAULT 'draft'`.

`database/migrations/2026_10_19_220000_add_payroll_workflow_tables.php` runs `MODIFY status VARCHAR(191) NOT NULL DEFAULT 'draft'` and `MODIFY payroll_status VARCHAR(191) NOT NULL DEFAULT 'draft'`. That later file explains the default. It does not explain how the original length became 191, because the backup already has `varchar(191)` before that file.

## Stock `0001` files

The same unresolved mapping appears before the historical payloads. Both snapshots store `varchar(191)` for unqualified `string()` columns in the current `0001` users, cache, and jobs migrations:

`users.name`, `users.email`, `users.password`, `password_reset_tokens.email`, `password_reset_tokens.token`, `sessions.id`, `cache.key`, `cache_locks.key`, `cache_locks.owner`, `jobs.queue`, `job_batches.id`, `job_batches.name`, `failed_jobs.uuid`.

`sessions.ip_address` is the explicit `string('ip_address', 45)` and both snapshots store `varchar(45)`. `users.remember_token` comes from `rememberToken()`, and both snapshots store `varchar(100)`. Those two are not the 191 discrepancy.

`users.invite_token` is not in these files. Both snapshots store `varchar(80)`, which matches the Stage 46 diff and the Stage 52 repair candidate.

## Later on-disk migrations

Dump-only varchar columns have explicit lengths in on-disk files, and the backup does not contain them: `locations.timezone` 64, `locations.status` 20, `attendance_logs.check_out_ip` 45, `holidays.status` 20, `location_networks.cidr` 64, and the event `action` columns at 20 or 40. Those lengths are source text, not an inferred default.

`2026_10_20_160000_extend_employee_onboarding_documents.php` uses unqualified `string()` for `onboarding_tasks.title`, `onboarding_tasks.assignee_role`, and, on its empty-table create path, document `title`, `file_path`, and `original_name`. When `100000` has already created those tables, this file does not rebuild the existing `varchar(191)` columns. A run that creates the tables here would use the current default of 255. Neither snapshot contains the offer-letter table. `2026_10_20_180000_add_offer_letters.php` uses unqualified `string()` for `employee_name`, `hr_name`, `hr_signature`, and `file_path`. Those lengths are `unverified`.

`2026_10_19_250000` creates `notifications.type` with unqualified `string('type')` only when the table is absent. Both snapshots already have `notifications.type` as `varchar(191)` from the `100000` payload shape.

## Application validation

Request rules use `max:191` for company name, slug, email, and phone, employee first name, last name, email, phone, and employee code, and ticket title. `ProfileUpdateRequest` uses `max:255` for the account name and email. `StoreOfferLetterRequest` uses `max:191` for `hr_name` and `hr_signature`. Validation shows what the forms accept. It does not set the migration length.

## Fresh-install consequence

A fresh installation that must match the snapshots has to stop before `0001_01_01_000000_create_users_table`. Under the current framework default, its unqualified `string()` columns would be `varchar(255)`, and both snapshots store `varchar(191)`. The same stop applies to the 91 historical columns. Explicit lengths can be reproduced from the blueprint text. Choosing 191 by changing the global default would be a new configuration, not a recovered one, so this stage does not do that.
