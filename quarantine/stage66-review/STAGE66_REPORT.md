# Stage 66 — Shared shell and reference screens

Date: 21 October 2026

This stage changes presentation. It does not change Laravel business logic, schema, migrations, payroll gates, offer-letter issuance, attendance verification, or authorization. It does not connect to `meetara_hrms` or `meetara_hrms_browser`. The application is not claimed to be production-ready.

## Shared components

- `resources/js/components/hrms/hrms-layout.tsx` — the page background is slate, the sidebar groups stay labeled, and the active item keeps a blue background with a visible focus ring. The header shows the company, role, and office, plus initials drawn from the signed-in name. The Route Map warning stays visible and uses a shorter line height.
- `resources/js/components/hrms/metric-card.tsx` — cards share one height, an icon, a value, an optional factual hint, and an optional link to an existing page.
- `resources/js/components/hrms/section-card.tsx` — section titles and padding are tightened. A “Not linked” badge tone was added for a profile without an employee record.

Other pages inherit the shell, cards, and badges. Employee details, Offices, attendance, leave, holidays, salary, payroll, payslips, offer letters, overtime, help desk, audit, companies, and settings were not redrawn page by page.

## Dashboard, profile, and inbox

The company dashboard greets the signed-in person and shows the local date. Its four figures are the active employee count, office count, offices without an approved public network, and saved payroll runs. Each card links to the existing page. Offices list the saved name, time zone, and status. An empty payroll state explains that opening the page does not create a run and links to payroll runs. Role notes and shortcuts use the existing routes for employees, offices, payroll, and holidays. No office photo, map, or extra chart was added.

The other role dashboards use the same greeting. Accounts uses the same empty-payroll explanation. The platform dashboard no longer opens with a large gradient banner.

My Profile shows initials from the person’s name, then designation, employee ID, email, and status. Work details sit in a compact grid. There is no edit button, because this page does not save profile changes, and no photo was invented.

Inbox keeps a notice list and a detail pane. On a wide screen they sit side by side; on a narrow screen they stack. The list scrolls, the message scrolls, and Mark read stays in a footer under the text and the related-page link. Unread items keep a blue dot and a stronger title. Mark read still posts to the existing route.

## Responsive behavior and accessibility

KPI cards are one column on a phone, two from the small breakpoint, and four on a wide screen. The inbox list is capped and scrolls instead of covering the message. Navigation, logout, inbox rows, and metric links use a visible blue focus ring. Decorative initials are hidden from assistive technology. The Route Map warning remains in the page.

## Tests and visual check

`vendor/bin/phpunit` exited 0: 165 tests, 2435 assertions, 14 skipped. The suite uses sqlite `:memory:` only.

`npm run build` exited 0. Vite 6.1.1 transformed 2046 modules in 3.76 seconds.

Desktop and mobile screenshots were not taken. No browser tool is available, and Playwright still starts against `meetara_hrms_browser`, so it was not run.

## Remaining limitations

The reference montage includes an office photograph, a map preview, and an edit-profile action. Those were not added, because the dashboard does not receive a photo or map, and My Profile does not save edits. Payroll approval and offer-letter issuance stay closed.
