# Stage 52 foreign-key audit

## `users.tenant_id`

| Fact | Evidence | Verified |
|---|---|---|
| Constraint name `users_tenant_id_foreign` | Schema dump and `meetara_hrms-before-office-networks.sql` | Yes |
| Column `users.tenant_id` | Both snapshots | Yes |
| Referenced table and column `tenants.id` | Both snapshots | Yes |
| Delete action `ON DELETE SET NULL` | Both snapshots. The quarantined `100000` payload calls `foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete()` | Yes |
| Update action | Neither snapshot contains the text `ON UPDATE` on this constraint or on any other constraint. The static count is zero in both files | The clause is omitted. The information_schema spelling was not read, because this stage did not connect |

Laravel's `nullOnDelete()` sets only `onDelete` to `set null`. `Grammar::compileForeign()` appends `on update ...` only when `onUpdate` is not null. The payload therefore emits `ON DELETE SET NULL` and no `ON UPDATE` clause.

MySQL's documented default for an omitted `ON UPDATE` clause is `RESTRICT`.

The same dump pattern supports that reading. `2026_10_19_200000_add_office_networks_and_attendance_breaks.php` calls `foreignId('location_id')->nullable()->constrained('locations')` with no delete action. `constrained()` does not set `onDelete`. The dump prints that foreign key with no `ON DELETE` and no `ON UPDATE`. Constraints that call `cascadeOnDelete()` or `nullOnDelete()` print `ON DELETE CASCADE` or `ON DELETE SET NULL`. The snapshots print non-default actions and omit the default action.

`NO ACTION` does not appear in either snapshot. It is a different recorded rule from `RESTRICT`, even where InnoDB enforces them alike. The Stage 51 candidate accepts both. That accepts a constraint the snapshots do not record.

## Required check

An existing foreign key is compatible only when all of these match:

- Name `users_tenant_id_foreign`.
- Columns exactly `tenant_id`.
- Referenced table `tenants` and referenced column `id`.
- Delete rule `set null`.
- Update rule exactly `restrict`.

A missing foreign key remains compatible with this repair. The repair runs before `tenants` exists, and `100000` adds the key. More than one foreign key on `tenant_id` is rejected. `CASCADE`, `SET NULL`, `NO ACTION`, an empty update rule, or any other update rule is rejected. The candidate must not alter a rejected key.

The Stage 52 revision at `quarantine/stage52-review/candidates/2026_10_19_010000_add_users_company_columns.php` implements that check. The Stage 51 file is unchanged.

If a future rehearsal observes an update rule other than `restrict`, the candidate stops before DDL. That observation would be new evidence. It is not a reason to accept `NO ACTION` now.
