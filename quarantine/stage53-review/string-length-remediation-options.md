# Stage 53 string-length remediation options

None of these options is applied. The recorded historical mechanism is one line in `AppServiceProvider::boot()`:

`Schema::defaultStringLength(191);`

That line is not in the current file. See `historical-string-config-search.md`.

## A. Restore the recorded project-level setting

This is the only option with source evidence. The transcript records the exact call, the file it was inserted into, and the key-length failure that prompted it. Both snapshots then store unqualified `string()` columns as `varchar(191)`, including the stock `0001` tables.

Fresh-install effect: unqualified `string()` in `0001` and in the six payloads would be `varchar(191)` without editing those files. Recorded migration rows would stay untouched.

Snapshot compatibility: this matches the stored length. It does not by itself prove the payload files are the originals.

Risk to other migrations: every later unqualified `string()` would also become `varchar(191)`. Offer-letter columns use unqualified `string()` and have no snapshot, so their live length is not proven. `ProfileUpdateRequest` allows 255 characters for account name and email, which would not fit a `varchar(191)` column.

Authenticity: restoring the line repeats a recorded edit. It does not recreate a byte-verified provider file. No removal of the line was found, and the current file lacks it, so putting it back is an approved restoration, not a silent current-tree fact.

Approval: required before any edit to `AppServiceProvider`. This stage does not make that edit.

## B. Write explicit length 191 into the affected columns

The snapshots support `varchar(191)` as the resulting type for the 91 payload columns and the affected `0001` columns. The blueprint text does not contain 191.

Doing this inside the quarantined payloads would replace recovered source with new source and weaken their provenance. Doing it inside `0001` would edit recorded migration files. Copies with explicit lengths could be placed in a new quarantine directory later, and they would still be reconstructions.

Fresh-install effect: explicit lengths reproduce the snapshots even when the provider default stays 255. That avoids changing unrelated unqualified strings such as the offer-letter columns.

Approval: a reconstruction of this kind needs its own approval and must not be described as the original migration text.

## C. Add a narrow schema helper

No helper, macro, or custom grammar appears in the transcript or the current project. The recorded mechanism is the framework default set once in `boot()`.

A helper could set 191 only around selected migrations, but those migrations would have to call it. `0001` is a recorded file and must not gain that call. A helper invoked from `AppServiceProvider` is option A under another name.

This design is not supported as historical source. No helper was created.

## Evidence order

Option A is the recorded mechanism and the one that leaves `0001` and the payloads unchanged. It is not authorized for application in this stage. Options B and C can reproduce lengths only by creating new source. A fresh install remains blocked until one of these is explicitly approved, and approving A still leaves the payload files and the permission migration unverified.
