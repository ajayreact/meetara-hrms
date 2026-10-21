# Stage 66A — Company dashboard layout

Date: 21 October 2026

The company dashboard at `/dashboard` was restyled toward the reference image using the props that page already receives. No controller, schema, migration, payroll, attendance, or authorization code was changed. `meetara_hrms` and `meetara_hrms_browser` were not opened. This is not a production-readiness claim.

## Files changed

- `resources/js/pages/dashboard/company-admin.tsx` — company dashboard layout.
- `resources/js/components/hrms/metric-card.tsx` — shared KPI card: icon, value, hint, optional status badge, and a link with an arrow.
- `resources/js/components/hrms/hrms-layout.tsx` — the sidebar stays in view on a tall page (`md:sticky`, full viewport height). The Route Map warning is unchanged.

## Sections

The greeting uses the signed-in person’s first name and the time of day. The line under it names the company. The date card uses the browser’s local calendar in a long English date, and shows a time zone only when every listed office shares one. Otherwise it shows the person’s office name or role.

The four cards are active employees, recorded offices, offices with no approved public network, and saved payroll runs. Each links to the existing employees, offices, or payroll page. An Active badge appears on employees because that count is active employees, and on offices only when every listed office is already Active.

Office overview lists each saved office name, time zone, and status. When the pending-network count is above zero, the card says clock-in stays blocked until a network is saved. There is no office photograph and no map.

Payroll status lists awaiting review, approved, processed, and outstanding payments. With no runs, it says opening the page does not create a run and links to payroll runs.

Roles repeat the existing Company Admin, HR, Accounts, and Team Lead descriptions. Quick links go to Employees, Offices, Payroll, Holidays, and Leave.

## Responsive behavior

KPI cards are one column on a narrow screen, two from the small breakpoint, and four on a wide screen. Office overview and payroll status sit side by side from the `xl` breakpoint and stack below it. Role notes are four columns on a wide screen. Quick links wrap from two columns to five.

## Not copied from the reference

The image includes a search box, a notification count, a city illustration, an office photo, a map, an attendance donut, a weekly bar chart, and a recent-activity feed. Those were not added. The dashboard props do not include that data, and a control for them would not work. The header search in the image was not added for the same reason.

## Tests and visual check

`vendor/bin/phpunit` exited 0: 165 tests, 2435 assertions, 14 skipped. The suite uses sqlite `:memory:` only.

`npm run build` exited 0. Vite 6.1.1 transformed 2046 modules in 3.71 seconds.

The page was not opened in a browser. Playwright still targets `meetara_hrms_browser` and was not run.
