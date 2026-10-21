# Stage 54 restoration review

This note proposes a change. It does not apply it. `app/Providers/AppServiceProvider.php` stays at SHA-256 `f61bf0206c0be1a4b8305ddbc3e0d7da995d21c74a66375ef708a99a34ad78c2`.

The proposed patch is `quarantine/stage54-review/app-service-provider-default-string-length.patch`.

## Transcript facts

Chat `f0697b32-2cd5-4451-9f11-90180714140d`, jsonl SHA-256 `6ebeb0fa4fb96a0742ab83f8f6445840cb420181306b888d4e00d3f8e7a31b4f`, line 61, records two edits to `app/Providers/AppServiceProvider.php`:

- Add `use Illuminate\Support\Facades\Schema;`.
- Make the first statement of `boot()` `Schema::defaultStringLength(191);`.

`compaction/segment_000.md` lines 10617–10625 preserve that text. It follows the recorded error `Specified key was too long; max key length is 1000 bytes`. The tool result says the provider file was updated.

No transcript entry shows that line being removed. The current provider does not contain it. The complete original provider file was not recovered. The historical `boot()` continued with `View::composer('layouts.app', ...)`. The current `boot()` does not. The patch therefore adds the recorded call to the current method. It does not recreate the rest of the historical provider.

## Proposed change

Add the Schema facade import and put this statement first in the current `boot()`:

```php
Schema::defaultStringLength(191);
```

Every existing statement in `register()`, `boot()`, and the private helpers stays in place. The patch does not edit `0001`, the six quarantined payloads, Composer files, `.env`, or the schema dump. It does not add a helper, a Blueprint macro, or another schema grammar.

## How the length is chosen

`Illuminate\Database\Schema\Builder::$defaultStringLength` is 255 until something changes it. `Blueprint::string()` uses an explicit length when the call provides one. When the call is `string('name')` with no length, it uses that default. `Schema::defaultStringLength(191)` changes the default for later unqualified calls in the same process. `string('invite_token', 80)`, `string('pan', 10)`, and the other explicit lengths stay at those lengths.

## What depends on it

A new install of the stock users, cache, and jobs migrations has 13 unqualified `string()` columns. Both snapshots store those as `varchar(191)`. The six quarantined payloads have 91 more, also `varchar(191)` in both snapshots. Those payloads are not in `database/migrations`. Restoring the setting would affect them only if a later approved step ran them.

On-disk migrations that pass an explicit length, including `subject_email` at 191 and the office-network lengths, do not use the default. `2026_10_20_180000_add_offer_letters.php` and parts of `2026_10_20_160000_extend_employee_onboarding_documents.php` use unqualified `string()`. Those columns have no snapshot. Restoring the default would make a future run create them as `varchar(191)`.

`ProfileUpdateRequest` allows 255 characters for the account name and email. A `varchar(191)` users column would reject a longer value at the database. That request is unchanged by this proposal.

Unique indexes on `varchar(191)` utf8mb4 stay inside the 1000-byte key limit that produced the historical error. `varchar(255)` utf8mb4 does not. The proposal does not change index names or foreign keys.

## What this does not finish

The permission migration file is still absent. Spatie is not installed. The six payloads are still `recovered_but_unverified`. The Stage 52 users-column candidate is still quarantined. Matching a historical length does not make this patch production-safe and does not make a fresh install reproducible.

## Later verification, after a separate approval

Do not run these in Stage 54.

1. Apply the patch only after approval. Then run `php -l app/Providers/AppServiceProvider.php`.
2. Run `php quarantine/stage53-review/static-unqualified-string-inventory.php`. It reads files only. The expected result is `rows 91`, and every row remains `varchar(191)` in both snapshots.
3. Run `vendor/bin/phpunit` only in a later stage that accepts the standard suite opening `sqlite :memory:`. Stage 54 does not run it.
4. A disposable rehearsal needs an explicit new database name that matches `meetara_hrms_rehearsal_YYYYMMDD`, proven absent, and not equal to `meetara_hrms` or any earlier disposable name. Do not use `migrate:fresh`, `db:wipe`, or rollback.
5. Expected order if that rehearsal is later approved: `0001` users, cache, and jobs first. With this setting, their unqualified strings should be `varchar(191)`. The install must then stop. The users-column repair is unapproved, the permission file is missing, and the six payloads are unverified. Those stops remain even after this provider line is restored.
