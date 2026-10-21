# Stage 67 — Inbox visual redesign

Date: 21 October 2026

The Inbox page was rebuilt to follow the approved reference. Notices still come from the existing controller payload. Mark as read still posts to `/inbox/{id}/read`. No schema, migration, payroll, attendance, or authorization code was changed. `meetara_hrms` and `meetara_hrms_browser` were not opened. This is not a production-readiness claim, and the page was not opened in a browser.

## Files changed

- `resources/js/pages/inbox/index.tsx` — Inbox heading, notification list, filters, detail panel, and empty states.
- `tests/Browser/roles.spec.ts` — the Inbox button assertion now looks for “Mark as read”, matching the new label. Playwright was not run.

`InboxController` and the inbox routes were not changed.

## Visual changes

The page title is Inbox, with the existing sentence that email, SMS, and push delivery are not connected. On a wide screen the list sits on the left and the open notice on the right. The list is a white card titled Notifications, with an unread count taken from the loaded notices. All, Unread, and Read filters and a newest/oldest sort run in the browser on those same notices.

Each row shows a category icon chosen from the title, the title, a short preview of the saved message, a local date and time, and an unread or read mark. Unread titles are semibold and unread rows keep a filled dot; read rows use a hollow dot. The selected row has a blue background.

The detail card shows the icon, title, timestamp, and a Read or Unread badge, then the full message. Mark as read is shown only while the notice is unread and posts to the existing route. Open the related page is shown only when the saved URL is not empty. An empty inbox, an empty filter, and a panel with nothing selected each have their own short message.

## Behavior preserved

Notifications still load from `InboxController::rows`. Selecting a row updates the detail panel. Mark as read still uses `router.post('/inbox/{id}/read')` and the controller still limits that update to the signed-in user. Counts and read state use the `read` flag already on each notice. Related links use the saved `url`. No notices, timestamps, or delivery channels were invented.

## Tests and build

`vendor/bin/phpunit` exited 0: 165 tests, 2435 assertions, 14 skipped. The suite uses sqlite `:memory:` only.

`npm run build` exited 0. Vite 6.1.1 transformed 2046 modules in 3.69 seconds.

The editor reported no diagnostics for `resources/js/pages/inbox/index.tsx`. `npx tsc --noEmit` still reports older errors in attendance, auth, employees, offices, and welcome. None of those errors are in the Inbox page.

## Checks not performed

The page was not opened in a browser. Playwright still targets `meetara_hrms_browser` and was not run, so selection and mark-as-read were not exercised in a browser.

The reference also shows a header search box, a notification bell, a sidebar unread badge, and separate Employee, Designation, Department, and Reporting Manager fields. Those were not added. The shell does not receive an inbox count, and each notice only has a title, message, URL, read flag, and timestamp. The header illustration is a mail icon, not a custom drawing.
