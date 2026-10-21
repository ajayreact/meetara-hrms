# Stage 63 — UI/UX layout

Date: 21 October 2026

This stage changes presentation. It does not add a migration, and it does not connect to `meetara_hrms` or `meetara_hrms_browser`. Stage 62 attendance checks stay in place: server-observed public IP/CIDR is still required, GPS remains an optional extra distance check, and a browser is not described as able to read a Wi-Fi name.

## Pages and components changed

- `resources/js/components/hrms/page-tabs.tsx` — new keyboard-operable tab list. Arrow keys move between tabs. Inactive panels stay mounted and are hidden.
- `resources/js/components/hrms/hrms-layout.tsx` — content padding uses the available width (`px-4 py-4 md:px-6 xl:px-8`).
- `resources/js/components/hrms/section-card.tsx` — slightly tighter card padding.
- `resources/js/pages/employees/show.tsx` — employee record tabs and grouped edit form.
- `resources/js/pages/employees/index.tsx` — directory search and shared table header.
- `resources/js/pages/offices/index.tsx` — schedule, office details, map, verification, and networks.
- `resources/js/pages/attendance/manage.tsx` and `attendance/mine.tsx` — table header and two-column timing layout.
- `resources/js/pages/leave/index.tsx`, `inbox/index.tsx`, `help-desk/index.tsx`, `offer-letters/index.tsx`, `payslips/index.tsx`, `settings/company.tsx` — width and pairing of existing sections.
- `app/Http/Controllers/Hrms/EmployeeController.php` — passes offer-letter summaries and activity for the employee page.
- `app/Services/Hrms/EmployeeOnboarding.php` — read-only activity list for the current company and employee.
- `app/Services/Hrms/OfferLetters.php` — company-scoped letter list without salary or preview text.

`resources/js/components/hrms/timing-card.tsx` still contains the Stage 62 GPS clock-in path. This stage did not change that behavior.

## Before and after

The employee record was one long stack of profile, onboarding, historical tasks, documents, and the edit form. It is now a header plus tabs, so only one section is visible at a time.

Offices repeated the company schedule inside the page flow and stacked map, networks, and verification in a narrow column. The schedule is one section at the top. On a wide screen, office details sit beside the map, and attendance verification sits beside approved networks.

Directory, attendance, payslip, leave, inbox, help desk, offer letters, and company settings used leftover empty width or a narrow form column. Related blocks now sit side by side from the `xl` breakpoint and stack on smaller screens. Forms keep a readable width. No extra widgets or sample rows were added.

## Employee details

The header shows name, status, employee ID, department, designation, office, manager, joining date, and email once, plus a link back to the directory.

- Overview: employment summary and required-task progress. Company Admin and HR get Personal information and Employment information in two columns on desktop and one column on a phone, with Save and Cancel. Field errors stay next to their fields.
- Onboarding: completed/total count, progress bar, and compact required rows with status badges. Historical tasks are separate accordions. Decision notes, confirmation, and the `RECORD` check stay inside those rows. `decide()` still rejects a task that has a checklist code, so a historical decision cannot complete the required checklist.
- Documents: the file list is separate from the upload form. Reviewer, date, and note stay with each file. Download still uses the existing authorized route. A viewer who cannot upload or review is told that files stay with the employee and Company Admin or HR, and is not told that the list is empty.
- Offer Letters: shown only to Company Admin and HR. It lists position, version, and status, and links to the offer-letter page. Salary figures and Issue are not copied onto this tab.
- Activity: shown when `employee_record_events` exists. It lists up to 40 saved events for that employee and company. Actor names come only from users in the same company.

Document review and historical decisions still call `form.transform(...)` and then `form.post(...)` as separate statements.

## Offices and Stage 62

Each office shows server-provided badges for location, approved network, GPS, and attendance ready or blocked. The page does not calculate readiness itself.

Preserved on the page and in the existing server code:

- Address, latitude, longitude, verification radius, OpenStreetMap picker, and manual coordinates.
- Server-observed public IP/CIDR verification. GPS is additional only when the office has it enabled.
- Office assignment and authorization checks.
- The blocked message when an office has no approved public network.
- The amber warning, and disabled coordinate inputs, when the database does not yet have the location columns. A save that includes coordinates or turns GPS on is still rejected with “Office coordinates cannot be saved until the location migration is applied.”
- Copy that a website cannot read the connected Wi-Fi name.

`2026_10_21_150000_add_office_location_verification.php` was not applied. The working schedule remains company-wide. Each office still stores its own time zone.

## Tests and build

`vendor/bin/phpunit` exited 0: 165 tests, 2435 assertions, 14 skipped. The suite uses sqlite `:memory:` only.

`npm run build` exited 0. Vite 6.1.1 transformed 2046 modules in 3.70 seconds.

Playwright was not run. Its configuration targets an existing database.

The live Offices page and `/employees/4` were not opened. Loading them would read `meetara_hrms`, which this stage does not access, and no browser tool was available. Tab clicks, mobile wrapping, and the map were not exercised in a browser. The production build confirms the pages compile.

## Known limitations

Dashboards, holidays, payroll overview, payroll review, payroll runs, payroll payments, salary, and the printable payslip were not redrawn beyond the shared shell. Their statutory and issuance gates were left as they were.

`npx tsc --noEmit` still reports older type errors in login, welcome, and some Inertia form generics, including the office form. The Vite build completed.

Desktop and mobile inspection of `/employees/4` and `/offices` was not performed.
