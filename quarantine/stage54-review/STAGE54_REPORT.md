# Stage 54 report

## 1. Evidence reviewed and hashes

| Item | SHA-256 | Result |
|---|---|---|
| `app/Providers/AppServiceProvider.php` | `f61bf0206c0be1a4b8305ddbc3e0d7da995d21c74a66375ef708a99a34ad78c2` | Matches Stage 53. `boot()` does not call `Schema::defaultStringLength`. |
| Transcript `f0697b32-2cd5-4451-9f11-90180714140d.jsonl` | `6ebeb0fa4fb96a0742ab83f8f6445840cb420181306b888d4e00d3f8e7a31b4f` | Matches Stage 53. Line 61 records the insertion. |
| `quarantine/stage53-review/historical-string-config-search.md` | Read in this stage | Same conclusion: statement recovered, original provider file not recovered. |
| `compaction/segment_000.md` lines 10617–10625 | Read in this stage | Same call, first statement of the historical `boot()`, after the 1000-byte key error. |

Transcript-supported facts: the statement text, the Schema facade import, the placement as the first line of `boot()`, the target path, and the tool result that the file was updated. The error text `Specified key was too long; max key length is 1000 bytes` is in that transcript immediately before the edit.

Assumptions that are not file recovery: the call stayed in force for every later migration; the current `boot()` body is not the historical body; no one removed the line outside the searched transcripts. The complete original provider was not recovered.

## 2. Proposed change

`quarantine/stage54-review/app-service-provider-default-string-length.patch`

It adds `use Illuminate\Support\Facades\Schema;` and this first statement in the current `boot()`:

```php
Schema::defaultStringLength(191);
```

The current HTTPS, proxy, policy, and route bindings stay as they are. Unqualified `string()` calls then use length 191. Explicit lengths stay explicit. The rationale is the recorded edit and the snapshot types, not a claim of production safety.

The review note is `quarantine/stage54-review/restoration-review.md`.

## 3. Files created

Only under `quarantine/stage54-review/`:

- `app-service-provider-default-string-length.patch`
- `restoration-review.md`
- `STAGE54_REPORT.md`

No active source file was changed. `AppServiceProvider.php`, `0001`, the six payloads, the Stage 52 candidate, Composer files, `.env`, cached configuration, and the schema dump were not edited.

## 4. Static checks executed

`Get-FileHash` confirmed the provider and transcript hashes above. The current provider was read and does not contain `defaultStringLength`. The transcript excerpt was read and contains the recorded statement.

No new PHP review script was created, so `php -l` had no Stage 54 script to check.

## 5. Checks not executed

PHPUnit was not run. It opens a database connection, and this stage forbids that. No result is claimed for it.

The 91-column inventory script was not re-run. Stage 53 already reported 91 rows of `varchar(191)`. The payloads were not modified in this stage.

No disposable database was created. No migration command was run.

## 6. Remaining fresh-install blockers

- This patch is not applied.
- `0001` unqualified strings are `varchar(191)` in the snapshots and would be `varchar(255)` until the setting is restored and a new install is run.
- The Stage 52 users-column repair is quarantined and unapproved.
- The permission migration is still missing. Spatie is not installed.
- The six historical payloads remain `recovered_but_unverified`.
- Offer-letter unqualified strings have no snapshot.
- Payroll defaults and `tickets.assignee_id` still differ between the backup and the dump because later on-disk migrations changed them.

Restoring the provider line does not remove those blockers.

## 7. Approval

Explicit approval is required before applying `quarantine/stage54-review/app-service-provider-default-string-length.patch`. This stage does not approve it, does not start a rehearsal, and does not start a later stage.

## Databases

No database connection was opened. No database was created, migrated, reset, seeded, or dropped.
