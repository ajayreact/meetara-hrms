# Stage 64 — Remaining UI layouts

Date: 21 October 2026

This stage changes presentation on the pages Stage 63 left in their earlier layouts. It does not add a migration, and it does not connect to `meetara_hrms` or `meetara_hrms_browser`. Payroll statutory gates, offer-letter issuance, attendance verification, permissions, and company isolation are unchanged. Employee details and Offices were not redesigned.

## Pages changed

- `resources/js/pages/dashboard/employee.tsx` and `resources/js/components/hrms/time-log-chart.tsx` — the month control sits in the timelog header beside the chart legend. The clock card uses a flexible column instead of a fixed 320px track.
- `resources/js/pages/dashboard/company-admin.tsx` — the role notes span the row under offices and payroll, in two columns on a wide screen.
- `resources/js/pages/dashboard/hr.tsx` — the leave queue uses the shared table header. A side list counts the statuses already in that queue.
- `resources/js/pages/dashboard/team-lead.tsx` — each waiting leave request shows the person, the dates, and the status on separate lines.
- `resources/js/pages/dashboard/accounts.tsx` — payroll counts use the existing status badges. Activity rows show the saved time.
- `resources/js/pages/dashboard/super-admin.tsx` — the banner spacing matches the other pages. Platform shortcuts stay in one column so the labels are not crushed.
- `resources/js/pages/payroll/index.tsx` — year and month fields stay a short width. Status counts and recent activity sit beside the selected month.
- `resources/js/pages/payroll/runs.tsx` — opening a month sits beside the run list on a wide screen. The table uses the shared header, and the filter fields stay short.
- `resources/js/pages/payroll/show.tsx` — warnings and actions sit beside statutory readiness and the audit list. The employee table scrolls horizontally with padded header cells. Approve stays disabled unless statutory readiness is proven. Process still requires the confirmation checkbox and the existing blocked check.
- `resources/js/pages/payroll/payments.tsx` — payment cards sit two across on a wide screen.
- `resources/js/pages/payroll/salary.tsx` — amount fields sit two across. Each employee’s history is a small table of effective date, currency, basic, HRA, and special allowance.
- `resources/js/pages/payslips/index.tsx` — body cells use the same padding as the header, and the action column does not wrap.
- `resources/js/pages/payslips/show.tsx` — earnings and deductions use row dividers. Payment status uses the existing badge. The sheet stays a readable document width.
- `resources/js/pages/holidays/index.tsx` — the calendar list sits beside the add form. Edit, save, and activate stay inside a collapsed row. The save and status requests are unchanged.
- `resources/js/pages/attendance/mine.tsx` — the month control is in the history header. The clock card and the selected day sit in the side column, so the page no longer opens with an empty band.
- `resources/js/pages/attendance/manage.tsx` — the roster and the selected row’s detail sit side by side on a wide screen. Header and body cells share the same padding. The selected row is highlighted.
- `resources/js/pages/leave/index.tsx` — history and the review queue sit side by side when review is allowed. Both tables use the shared header.
- `resources/js/pages/inbox/index.tsx` — the open notice is highlighted, and unread titles stay stronger.
- `resources/js/pages/help-desk/index.tsx` — the open ticket is highlighted in the list.
- `resources/js/pages/settings/company.tsx` — the company name sits beside payroll settings on a wide screen. The currency form and the rule list are unchanged.

No new data is invented. Counts on the HR dashboard are grouped from the leave rows already sent to that page.

## Preserved behavior

- Payroll Approve remains disabled while `statutory.ready` is not true. Process still posts only after the confirmation checkbox, and only when the run is not blocked. The process request still sets `confirm` to `PROCESS` and then posts.
- Holiday edit still saves with `put`, and activate or deactivate still changes status through `transform` and then `put`.
- Help-desk status changes still call `transform` and then `post`.
- Attendance clock-in, office location fields, and the Stage 63 employee tabs were not edited.

## Tests and visual check

`vendor/bin/phpunit` exited 0: 165 tests, 2435 assertions, 14 skipped. The suite uses sqlite `:memory:` only.

`npm run build` exited 0. Vite 6.1.1 transformed 2046 modules in 4.06 seconds.

Playwright was not run. Its configuration targets an existing database.

These pages were not opened in a browser. Loading them would read `meetara_hrms`, which this stage does not access, and no browser tool was available. Desktop and mobile wrapping, tab order, and chart interaction were not clicked. The production build confirms the pages compile.

## Remaining limitations

The printable payslip stays centered at a document width so the figures remain readable. Overtime, audit, companies, and profile were left on their current layouts. `resources/js/pages/dashboard.tsx` is the unused starter screen and was not changed; the signed-in dashboards are the role pages above.

`npx tsc --noEmit` was not run again. Stage 63 already recorded older type errors in login, welcome, and some form generics. The Vite build completed.
