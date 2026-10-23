# Candidate onboarding readiness

This note describes the protections around the public candidate form. It is not a statement that the form is approved for production, that a retention period has been chosen, or that a privacy review is complete. The migration `2026_10_23_200000_add_candidate_onboarding` has not been run against `meetara_hrms`. No real company is enabled by this document.

Application encryption protects stored ciphertext. After an authorized reviewer reveals a value, or downloads a document, that value is in the reviewer's browser. Encryption does not hide it there.

## Sensitive fields

These values are encrypted with Laravel's encrypter before they are stored: Social Security number, bank name, account number, routing number, phone, date of birth, current address, I-94 number, and EAD document number. The review page shows masks. Full Social Security numbers and bank details are returned only by their own POST actions. Phone, date of birth, address, I-94, and EAD values are returned only by the identity POST action. Those responses send `Cache-Control: private, no-store`.

Email stays searchable so a reviewer can find a submission. The duplicate-email check stores an HMAC of the lowercased email using the current application key. That hash does not try previous keys. Work-authorization text and the expiry date stay as entered. They were not part of the encrypted set.

Search, the candidate list, and audit subjects do not include ciphertext or full secret values. Validation errors do not flash the secret fields or the verification token back to the browser.

### Key rotation

Ciphertext can be read after a key change only while the previous key remains in `APP_PREVIOUS_KEYS`. Replacing `APP_KEY` without that previous key makes stored candidate secrets unreadable. There is no second copy of those values. Do not print, rotate, or replace the key as part of ordinary setup. A key that has been exposed has to be treated as compromised; see the incident section below.

## Password confirmation

Revealing a Social Security number, revealing bank details, and opening an identity document require the signed-in user's current password. The check uses the `web` guard and Laravel's `auth.password_confirmed_at` session value. The candidate routes use `CANDIDATE_PASSWORD_TIMEOUT`, which defaults to 900 seconds (15 minutes). The rest of the application keeps its own password timeout.

A confirmed password does not change tenant or role rules. A Company Admin or HR user in another company still receives 404. An employee still receives 403. A missing or expired confirmation receives 423 for the JSON actions and a redirect to `/confirm-password` for a document. The password is not written to the log.

Identity-field reveal (phone, date of birth, address, I-94, and EAD) is still limited to Company Admin and HR in the same company and is audited, but it does not ask for the password again. That split matches the requested confirmation list. Changing it is a separate access decision.

## Uploads

Each document is limited by the application to 5 MB (`CandidateForm::MAX_KILOBYTES`). A larger single file is rejected with a validation error. A request whose body exceeds PHP's `post_max_size` is redirected back to the public form with a fixed notice and is not flashed or logged with the submission body.

Files are stored on the `local` disk, whose root is `storage/app/private`, with generated names. The browser's original filename is not kept. Downloads check the company, the submission, and a path prefix, and send `nosniff`, `private, no-store`, and a sandbox content-security policy. An unsigned `/storage/...` URL is not a substitute for that download route.

Temporary copies are deleted when validation, scanning, or the database write fails. A failed database transaction does not leave the submission row in place.

### Malware scanning

`CANDIDATE_MALWARE_SCAN=required` is the default. The scanner is ClamAV, invoked as a local command from `CANDIDATE_CLAMAV_COMMAND` with a timeout from `CANDIDATE_CLAMAV_TIMEOUT` (default 10 seconds, maximum 30). The command receives the temporary file path as an argument. Scanner output is not written to the log.

This workspace does not have a verified ClamAV installation. While the command is empty or fails, a real submission is rejected and the form cannot be enabled. Scanning is not active here. Do not describe it as active until `clamscan --version` (or the configured command) succeeds on the host that serves the application, and a harmless test file is classified as clean.

Isolated tests set `CANDIDATE_MALWARE_SCAN=off` or bind a test double. A harmless fixture string, `MEETARA-HARMLESS-SCAN-FIXTURE`, is used for the command boundary. Do not place the EICAR test file in this repository.

### PHP and web-server limits

The PHP process serving this workspace has not been changed. Measure the serving process before claiming any new limit is active:

```text
php -r "foreach (['upload_max_filesize','post_max_size','max_file_uploads','memory_limit','max_input_time','max_execution_time'] as $name) { echo $name, '=', ini_get($name), PHP_EOL; }"
```

`php artisan serve` uses the CLI `php.ini`. On this WAMP install that file is `D:\wamp64\bin\php\php8.3.28\php.ini`. Apache, if it is later pointed at the application, can use a different `php.ini`. Change the file that the serving process actually loads.

Recommended settings, not applied:

```ini
upload_max_filesize = 6M
post_max_size = 64M
max_file_uploads = 20
memory_limit = 128M
max_input_time = 120
max_execution_time = 120
```

The application still rejects a document over 5 MB after PHP accepts the request.

For the web server or proxy, allow request bodies of 67108864 bytes (64 MiB):

- Apache, only after `DocumentRoot` is the application's `public/` directory: `LimitRequestBody 67108864` inside that virtual host.
- nginx: `client_max_body_size 64m;`
- IIS: `maxAllowedContentLength` of `67108864`.

Restart the PHP or web-server process after an approved edit, then run the measurement command again in that same process. Do not edit `php.ini` or restart Apache until that edit is explicitly approved.

## Debug, document root, and private files

This workspace is `APP_ENV=local` with `APP_DEBUG=true`. Those values are in `.env` and were not changed. Production deployment must set `APP_ENV=production` and `APP_DEBUG=false` before any real candidate data is collected. Secret form fields are removed before an unhandled exception page is rendered. Names, email, and the stack trace can still appear while debug is on, so production must turn debug off. Handled validation errors on the candidate form do not flash secret fields.

The public entry is `public/index.php`. WAMP's Apache `DocumentRoot` is the `www` directory, not `public/`. `storage/app/private` therefore sits under the web root. `storage/app/private/.htaccess` denies direct access when Apache honors `.htaccess`. That is not a substitute for a `DocumentRoot` of `public/`. A production host must serve only `public/` and must keep `storage/` outside that tree.

`docs/production.md` is the host procedure. Do not copy the local `.env` to a public host.

## Verification check

The default is `CANDIDATE_CAPTCHA=required`. The provider is Cloudflare Turnstile. The public form renders the Turnstile widget only when the form is open and `CANDIDATE_TURNSTILE_SITE_KEY` is present. The secret stays in `CANDIDATE_TURNSTILE_SECRET` and is sent to `https://challenges.cloudflare.com/turnstile/v0/siteverify`. The page does not receive the secret. No key is stored in the repository. A missing, invalid, or expired token is rejected, and an unreachable provider rejects the submission.

If the check is required and the keys are missing, the provider is unreachable, or the token is missing, invalid, or expired, the submission is rejected. The form cannot be enabled until the keys are present. Tests use a fake HTTP response and set `CANDIDATE_CAPTCHA=off`. Turnstile's own privacy terms have to be reviewed before it is turned on for candidates; this note does not accept them.

The honeypot and the 10-submission-per-minute limit remain. The eleventh public post returns the candidate to the form with: "Too many submissions were sent from this network. Wait a minute and try again."

## Public link

The link stays the same when the form is turned off and on. A Company Admin can replace it from Company Settings after confirming that the current link will stop working. The new token is 64 hex characters from `random_bytes`. The previous link stops resolving. Existing submissions and files stay. The new link accepts submissions only while the form is enabled. The token is not written into the audit subject.

Do not rotate a real company's link from a test or a local session pointed at `meetara_hrms`.

## Retention

`CANDIDATE_RETENTION_DAYS` empty means no period is approved. `php artisan candidates:retention-dry-run` then reports that nothing was listed or deleted. It never deletes. There is no scheduler entry for candidate deletion.

`CandidateRetention::purge()` deletes one submission for one tenant only when the caller passes a positive day count and the row is older than that count. It removes the submission, its operations, its per-submission events, and its private files. It writes `candidate_retention_audits` with the company id, reference, counts, and whether the files were removed. It does not copy ciphertext, file contents, email, or document paths into that audit. If the audit insert fails, the database transaction rolls back and the files stay. `retryOrphanFiles()` deletes a private directory only when the submission row is already gone, and it will not delete another company's directory.

Events that remain after a purge are the retention audit row and any form-level events whose submission id is null (form enabled, disabled, or link rotated). Per-submission review events are removed with the submission so notes are not kept after the record is gone.

No production deletion is scheduled. Choose a period only after the privacy review, then approve a separate change that is allowed to call purge.

## Incident response

These are operational steps for an administrator. They do not satisfy a notice or reporting duty. Escalate to qualified privacy and legal counsel before telling candidates, regulators, or the public that an incident is closed.

### Leaked public link

1. Company Admin replaces the public link. The previous URL must stop opening the form.
2. Decide whether to disable the form until the replacement is confirmed.
3. Review `candidate_onboarding_events` for `submission_received` after the leak became known. Do not copy secret fields into the incident notes.
4. Keep the submissions. Do not delete them under an unapproved retention period.

### Compromised HR or Company Admin account

1. Disable that user and invalidate the session. Changing the password invalidates the password-confirmation window as well.
2. Review `ssn_viewed`, `banking_viewed`, `identity_viewed`, and `document_downloaded` events for that actor.
3. A confirmed password in the last 15 minutes could have been used for a reveal. Treat those events as access, not as proof of misuse by themselves.
4. Counsel decides whether the access requires notification.

### Unauthorized Social Security number or bank-detail reveal

1. Identify the actor, company, submission reference, and time from the audit event. The event subject is empty and does not contain the secret.
2. Contain the account as above.
3. Preserve the event row. Do not export the revealed value into a ticket.
4. Counsel decides notification. This application does not send that notice.

### Accidental document disclosure

1. Disable the link or account that was used.
2. Confirm the file path is still under `storage/app/private` and was not copied into `public/`.
3. Preserve the `document_downloaded` event (category only).
4. If a private URL was shared, treat the file as disclosed to whoever received it. Counsel decides the next notice.

### Exposed application encryption key

1. Stop collecting new candidate data. Disable every public form.
2. Assume ciphertext for candidate secrets, bank details, and Social Security numbers can be decrypted by whoever has the key.
3. Put the exposed key in `APP_PREVIOUS_KEYS` only for as long as a planned rotation still needs to read old rows, then remove it. A new `APP_KEY` without the previous key makes old rows unreadable; that is not a cleanup of copies an attacker already decrypted.
4. Do not print the key into tickets, chat, or logs.
5. Counsel and the key custodian decide rotation timing. The email duplicate hash will not match rows created under the old key.

### Scanner unavailable

1. Leave `CANDIDATE_MALWARE_SCAN=required`. The form rejects documents and cannot be enabled.
2. Restore the local ClamAV command. Do not switch the setting to `off` on a host that accepts real submissions.
3. Record the outage window. Documents submitted while scanning was required and unavailable were rejected, not stored.

### Suspected cross-tenant access

1. Compare the actor's `tenant_id` with the submission's `tenant_id` in the audit row. A cross-tenant request is written as a denial log and does not create an event on the other company.
2. If an event exists on a company the actor does not belong to, treat that as a defect and stop the form.
3. Preserve the log line. It contains ids and a reason, not secret values.
4. Do not broaden a query to "all companies" while investigating.

## Legal and privacy decisions still required

A qualified reviewer has to decide the following. This repository does not decide them.

- Whether the public notice, version `2026-10-23.1`, is accurate for each field and for the purpose of collecting it.
- Whether a Social Security number and bank details are necessary before a person is hired.
- Whether immigration and identity documents, including passport, visa, I-94, EAD, degree, and I-20 files, may be collected on the public form.
- Which roles may reveal each secret. Today, Company Admin and HR can reveal a Social Security number and the masked identity fields. Only a Company Admin can reveal bank details. Both can download documents after confirming their password.
- How long submissions, documents, and audit rows are kept, and who may approve deletion.
- Which incidents require notification, to whom, and on what deadline.
- Whether Cloudflare Turnstile is an acceptable verification provider, and what the privacy notice must say about it.
- Which laws apply to the company's actual locations, candidates, and processing. No jurisdiction has been selected in this document.

## Migration

`2026_10_23_200000_add_candidate_onboarding` creates the form, submission, document, operations, event, and retention-audit tables. Secret columns are ciphertext. Rollback drops those tables and the candidate rows in them. Do not roll it back on a database that has real submissions unless destroying that data is the intended outcome.

The file is not approved for `meetara_hrms`. An isolated SQLite file is the schema check used by the test suite. Do not point that check at the live database.
