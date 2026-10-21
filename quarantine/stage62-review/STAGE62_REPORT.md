# Office location and attendance verification

Date: 21 October 2026

The Offices page now stores a physical address, optional latitude and longitude, and a verification radius. Clock-in still requires a server-observed public IP that matches an approved network. GPS, when explicitly enabled, is an additional server-side distance check. It does not replace the network check, and a browser cannot supply the verification result.

No migration was applied to `meetara_hrms` or `meetara_hrms_browser`. No MySQL connection was opened. Playwright was not run.

## Behavior

Offices remain the `locations` rows for one company. Employees stay assigned through `employees.location_id`. Company Admin and HR can update an office in their own company. The existing route binding returns 404 for another company, a team lead, or an employee.

New columns, added only when `locations` exists and `latitude` is absent:

- `latitude` decimal(10,7), nullable
- `longitude` decimal(10,7), nullable
- `verification_radius_meters` unsigned integer, default 150
- `gps_verification_enabled` boolean, default false

Validation requires latitude from -90 to 90, longitude from -180 to 180, and both coordinates together. The radius must be an integer from 25 through 2000. GPS cannot be turned on without both coordinates.

The page is arranged as office details, address and map location, attendance verification, approved public networks, and the company working schedule. Status values come from `OfficeVerification::describe()`:

- Location configured / not configured
- Approved network configured / missing
- GPS verification on / off
- Attendance verification ready / blocked

Ready means the office is active, at least one approved public network exists, and GPS is either off or fully configured. A saved map point with no approved network stays blocked. An inactive office stays blocked.

The map is an OpenStreetMap tile picker. It does not use a Google API key or any secret. Typing coordinates still saves the location if the tile service is unavailable. The starting map view is not saved until someone clicks the map or enters coordinates.

## Attendance security

`ClientAddress` is unchanged. Clock-in still ignores a browser-supplied IP, office id, `verified` flag, and any GPS success flag. The office id must match the employee’s assigned office. The observed address must fall inside an approved public CIDR for that office and company.

When GPS is off, submitted coordinates are ignored and `verified_as` stays `public_ip`.

When GPS is on, the server also requires numeric coordinates inside the world ranges and a haversine distance within the saved radius. `GeoDistance` uses a spherical Earth radius of 6,371,000 meters. A passing GPS check sets `verified_as` to `public_ip_and_gps`. The employee’s coordinates are not stored. GPS remains easy to spoof and is not treated as proof that the person is in the building.

If GPS is on but the coordinates or radius are incomplete, clock-in is blocked even from an approved network. An office with no approved network cannot clock in, including when GPS is configured.

A normal website cannot read the connected Wi-Fi SSID. There is no SSID field and no claim that one is verified. Approved public IP/CIDR ranges remain the browser-based network control. Reading an SSID would require a managed native application or another trusted network check.

## Database

`database/migrations/2026_10_21_150000_add_office_location_verification.php` is the migration. The PHPUnit schema fixture adds the same columns so the sqlite suite can exercise them. `meetara_hrms` does not have these columns yet. Until that migration is explicitly approved and applied, the Offices page shows that coordinates cannot be stored, and a save that includes coordinates or enables GPS is rejected. Existing name, address, time zone, status, schedule, and network saves still use the current columns. Clock-in keeps the previous IP-only rule while the GPS column is absent.

An unrestricted `php artisan migrate` against the documented live ledger would also apply this file, together with the still-unapproved lifecycle, invitation, and subject-email repair migrations. Do not run that command.

## Tests

`vendor/bin/phpunit` exited 0: 165 tests, 2435 assertions, 14 skipped. The suite uses sqlite `:memory:` only. New coverage is in `tests/Unit/GeoDistanceTest.php` and `tests/Feature/OfficeLocationAttendanceTest.php`: coordinate and radius limits, company isolation, Company Admin and HR updates, employee and team-lead refusal, distance rejection, spoofed browser flags, missing GPS configuration, and the rule that GPS does not bypass the network check.

`npm run build` exited 0. Vite 6.1.1 transformed 2045 modules in 3.81 seconds. Playwright was not run because its configs target an existing database.

The live Offices screen at `http://127.0.0.1:8000/offices` was not clicked. The new columns are not on that database, so the map and saved coordinates could not be confirmed in the browser.

## Files

Added:

- `database/migrations/2026_10_21_150000_add_office_location_verification.php`
- `app/Support/GeoDistance.php`
- `app/Support/OfficeVerification.php`
- `resources/js/components/hrms/office-map.tsx`
- `tests/Unit/GeoDistanceTest.php`
- `tests/Feature/OfficeLocationAttendanceTest.php`

Updated:

- `app/Models/Office.php`
- `app/Http/Requests/Hrms/OfficeRequest.php`
- `app/Http/Controllers/Hrms/OfficeController.php`
- `app/Services/Hrms/AttendanceService.php`
- `app/Services/Hrms/AttendanceDirectory.php`
- `resources/js/pages/offices/index.tsx`
- `resources/js/components/hrms/section-card.tsx`
- `resources/js/components/hrms/timing-card.tsx`
- `tests/Support/HrmsSchema.php`
- `tests/Unit/MigrationInventoryTest.php`

## Limitations

The migration is not applied to the local or browser database. OpenStreetMap tiles are requested by the browser and can fail without blocking manual entry. GPS can be spoofed. Wi-Fi SSID verification is not implemented. Payroll statutory checks and offer-letter issuance stay closed. This change was not deployed.
