import { expect, test, type Page } from '@playwright/test';
import { accounts, denied, finishClean, signIn, signOut, watchPage, type BrowserAccounts } from './support';

const data = accounts();

test.describe.configure({ mode: 'serial' });

test.beforeEach(({ page }, testInfo) => {
    watchPage(page, testInfo);
});

test.afterEach(async ({ page }, testInfo) => {
    if (testInfo.status === testInfo.expectedStatus) {
        await finishClean(page);
    }
});

test('super admin sees companies and cannot open payroll', async ({ page }) => {
    await signIn(page, data.emails.platform, data.password);
    await expect(page.getByText('(Super Admin)')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Companies' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Audit log' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Payroll' })).toHaveCount(0);
    await page.getByRole('link', { name: 'Companies' }).click();
    await expect(page.getByRole('cell', { name: 'Browser North' })).toBeVisible();
    await page.getByRole('button', { name: 'Add company' }).click();
    await page.getByRole('button', { name: 'Save company' }).click();
    await expect(page.getByText('The name field is required.')).toBeVisible();
    await page.locator('#company-name').fill('Browser West');
    await page.locator('#company-slug').fill('browser west');
    await page.getByRole('button', { name: 'Save company' }).click();
    await expect(page.getByText('Company saved. No invitation email was sent.')).toBeVisible();
    await expect(page.getByText('Mail delivery is not configured, so an invitation email cannot be sent.')).toBeVisible();
    await page.getByLabel('Company Admin email').fill('admin@browser-west.test');
    await page.getByRole('button', { name: 'Send invitation' }).click();
    await expect(page.getByText('Mail delivery is not configured. The invitation was not created.')).toBeVisible();
    await expect(page.getByText('Invitation email sent.')).toHaveCount(0);
    await expect(page.getByRole('cell', { name: 'Browser West' })).toBeVisible();
    await page.getByRole('button', { name: 'Browser North' }).click();
    await expect(page.getByText('This company already has a Company Admin.')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Deactivate company' })).toBeDisabled();
    await page.getByRole('link', { name: 'Audit log' }).click();
    await expect(page.getByText('No audit events yet').or(page.getByRole('cell', { name: 'Payroll' }))).toBeVisible();
    await expect(page.locator('body')).not.toContainText('88.00');
    await page.setViewportSize({ width: 390, height: 844 });
    for (const path of ['/dashboard', '/companies', '/audit']) {
        await page.goto(path);
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
        expect(overflow).toBe(false);
    }
    await expect(page.getByText('No audit events yet').or(page.getByRole('cell', { name: 'Payroll' }))).toBeVisible();
    await page.goto('/companies');
    await expect(page.getByRole('button', { name: 'Browser North' })).toBeVisible();
    await page.setViewportSize({ width: 1280, height: 800 });
    await denied(page, '/payroll', 403);
    await denied(page, '/salary', 403);
    await denied(page, '/payments', 403);
    await denied(page, '/payslips', 403);
    await denied(page, `/employees/${data.ids.northReport}`, 404);
    await page.goto('/route-map');
    await expect(page.getByRole('heading', { name: 'Route Map' })).toBeVisible();
    await expect(page.getByText('Your account cannot open Route Map')).toHaveCount(0);
    await signOut(page);
});

test('employee workflows stay on that employee', async ({ page }) => {
    await signIn(page, data.emails.report, data.password);
    await expect(page.getByText('(Employee)')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Payroll' })).toHaveCount(0);
    await page.goto('/my-profile');
    await expect(page.getByText('report@browser-north.test')).toBeVisible();
    await expect(page.locator('body')).not.toContainText('outside@browser-north.test');
    await page.goto('/employees');
    await expect(page.getByText('Your account cannot open Employees')).toBeVisible();
    await denied(page, `/employees/${data.ids.northOutside}`, 404);
    await denied(page, `/employees/${data.ids.southReport}`, 404);
    await denied(page, '/payroll', 403);
    await denied(page, '/salary', 403);
    await page.goto('/my-attendance');
    await expect(page.getByRole('heading', { name: 'My Attendance' })).toBeVisible();

    await page.goto('/help-desk');
    await expect(page.getByText('No tickets yet.')).toBeVisible();
    await page.getByRole('button', { name: 'Create ticket' }).click();
    await page.locator('#title').fill('Bad');
    await page.locator('#description').fill('This description is long enough.');
    await page.getByRole('button', { name: 'Open ticket' }).click();
    await expect(page.getByText('at least 4 characters')).toBeVisible();
    await page.locator('#title').fill('Door badge browser');
    await page.locator('#description').fill('The side door badge does not open.');
    await page.getByRole('button', { name: 'Open ticket' }).click();
    await expect(page.getByText('Ticket opened.')).toBeVisible();
    await page.getByLabel('Comment').fill('x');
    await page.getByRole('button', { name: 'Add comment' }).click();
    await expect(page.getByText('at least 2 characters')).toBeVisible();
    await page.getByLabel('Comment').fill('The side door badge failed again.');
    await page.getByRole('button', { name: 'Add comment' }).click();
    await expect(page.getByText('Comment added.')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Start' })).toHaveCount(0);
    await expect(page.getByText('opened')).toBeVisible();

    await page.goto('/leave');
    await page.locator('#leave-type').selectOption({ label: 'Unrecorded Leave' });
    await page.locator('#from').fill('2026-10-21');
    await page.locator('#to').fill('2026-10-21');
    await page.locator('#reason').fill('Family event');
    await page.getByRole('button', { name: 'Submit request' }).click();
    await expect(page.getByText('No leave balance is recorded for this year.')).toBeVisible();
    await page.locator('#leave-type').selectOption({ label: 'Recorded Casual' });
    await page.locator('#from').fill('2026-10-21');
    await page.locator('#to').fill('2026-10-21');
    await page.locator('#reason').fill('Family event');
    await page.getByRole('button', { name: 'Submit request' }).click();
    await expect(page.getByText('Leave request submitted.')).toBeVisible();
    await page.locator('#from').fill('2026-10-21');
    await page.locator('#to').fill('2026-10-21');
    await page.locator('#reason').fill('Family event');
    await page.getByRole('button', { name: 'Submit request' }).click();
    await expect(page.getByText('Those dates overlap a pending or approved request.')).toBeVisible();
    await page.getByRole('button', { name: 'Cancel request' }).click();
    await expect(page.getByText('Leave request cancelled.')).toBeVisible();
    await page.locator('#from').fill('2026-10-22');
    await page.locator('#to').fill('2026-10-22');
    await page.locator('#reason').fill('Family event');
    await page.getByRole('button', { name: 'Submit request' }).click();
    await expect(page.getByText('Leave request submitted.')).toBeVisible();

    await page.goto('/overtime');
    await expect(page.getByText('No overtime requests yet.')).toBeVisible();
    await page.locator('#ot-date').fill('2026-10-23');
    await page.locator('#ot-hours').fill('0.25');
    await page.locator('#ot-reason').fill('Covered the late release window.');
    await page.getByRole('button', { name: 'Submit request' }).click();
    await expect(page.getByText('format is invalid')).toBeVisible();
    await page.locator('#ot-hours').fill('1.5');
    await page.getByRole('button', { name: 'Submit request' }).click();
    await expect(page.getByText('Overtime request saved. Pay was not calculated.')).toBeVisible();
    await expect(page.getByText('Requested 1.5 hours. Not calculated.')).toBeVisible();
    expect(await postForm(page, '/overtime', {
        work_date: '2026-10-23',
        hours: '1.5',
        reason: 'Covered the late release window.',
    })).toBe(422);
    await page.goto('/overtime');
    await expect(page.getByText('Requested 1.5 hours. Not calculated.')).toHaveCount(1);
    await signOut(page);
});

test('outside employee and team lead stay inside the team', async ({ page }) => {
    await signIn(page, data.emails.outside, data.password);
    await page.goto('/overtime');
    await page.locator('#ot-date').fill('2026-10-24');
    await page.locator('#ot-hours').fill('1');
    await page.locator('#ot-reason').fill('Stayed for the handoff window.');
    await page.getByRole('button', { name: 'Submit request' }).click();
    await expect(page.getByText('Overtime request saved. Pay was not calculated.')).toBeVisible();
    await denied(page, `/employees/${data.ids.northReport}`, 404);
    await signOut(page);

    await signIn(page, data.emails.lead, data.password);
    await expect(page.getByText('(Team Lead)')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Employees' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Offer letters' })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Salary' })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Payroll' })).toHaveCount(0);
    await denied(page, '/salary', 403);
    await denied(page, '/offer-letters', 403);
    await denied(page, '/payroll', 403);
    await denied(page, '/payslips', 403);
    await denied(page, `/employees/${data.ids.northOutside}`, 404);
    await page.goto('/employees');
    await expect(page.getByText('report@browser-north.test')).toBeVisible();
    await expect(page.locator('body')).not.toContainText('outside@browser-north.test');
    await page.goto('/overtime');
    await expect(page.getByText('BRN-OUT')).toHaveCount(0);
    await expect(page.getByText('Requested 1.5 hours. Not calculated.')).toBeVisible();
    await page.getByRole('button', { name: 'Approve' }).click();
    await expect(page.getByText('Overtime request approved. Pay was not calculated.')).toBeVisible();
    await expect(page.getByText('Requested 1.5 hours. Not calculated.')).toBeVisible();
    await page.goto('/leave');
    await page.getByRole('row', { name: /2026-10-22/ }).getByLabel('Decision note').fill('Approved for the team');
    await page.getByRole('row', { name: /2026-10-22/ }).getByRole('button', { name: 'Approve' }).click();
    await expect(page.getByRole('row', { name: /2026-10-22/ })).toContainText('Approved');
    await page.locator('#from').fill('2026-10-27');
    await page.locator('#to').fill('2026-10-27');
    await page.locator('#reason').fill('Family event');
    await page.getByRole('button', { name: 'Submit request' }).click();
    await expect(page.getByRole('row', { name: /BRN-LEAD/ }).getByRole('button', { name: 'Approve' })).toHaveCount(0);
    await signOut(page);
});

test('hr manages help desk and cannot run payroll', async ({ page }) => {
    await signIn(page, data.emails.hr, data.password);
    await expect(page.getByText('(HR)')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Offer letters' })).toBeVisible();
    await page.getByRole('link', { name: 'Offer letters' }).click();
    await expect(page.getByText('Email delivery is not used for offer letters.')).toBeVisible();
    await expect(page.getByLabel('Employee')).toBeVisible();
    await expect(page.locator('#offer-employee option', { hasText: 'BRS-REP' })).toHaveCount(0);
    await expect(page.getByText('No offer letters yet.')).toBeVisible();
    await expect(page.getByText('Offer letter storage is not ready on this database.')).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Payroll' })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Payments' })).toHaveCount(0);
    await denied(page, '/payroll', 403);
    await denied(page, '/payments', 403);
    await page.goto('/employees');
    await page.getByRole('row', { name: /accounts@browser-north.test/ }).getByRole('button', { name: 'View' }).click();
    await expect(page.getByRole('button', { name: 'Enable Accounts access' })).toHaveCount(0);
    await expect(page.locator('select', { hasText: 'Keep the current access level' }).locator('option', { hasText: 'Accounts' })).toHaveCount(0);
    await page.goto('/help-desk');
    await page.getByRole('link', { name: /Door badge browser/ }).click();
    await page.getByLabel('Assignee').selectOption({ label: 'North HR' });
    await page.getByRole('button', { name: 'Assign' }).click();
    await expect(page.getByText('Ticket assigned.')).toBeVisible();
    await page.getByRole('button', { name: 'Start' }).click();
    await expect(page.getByText('Ticket updated.')).toBeVisible();
    await page.getByRole('button', { name: 'Resolve' }).click();
    await expect(page.getByText('status resolved')).toBeVisible();
    await page.goto('/inbox');
    await expect(page.getByText('Help desk ticket opened')).toBeVisible();
    await expect(page.locator('body')).not.toContainText('The side door badge does not open.');
    await page.getByRole('button', { name: 'Mark as read' }).click();
    await expect(page.getByText('Notification marked read.')).toBeVisible();
    await page.reload();
    await expect(page.getByText('Read').first()).toBeVisible();
    await signOut(page);
});

test('company admin assigns accounts and cannot process payroll', async ({ page }) => {
    await signIn(page, data.emails.accounts, data.password);
    await expect(page.getByText('(Employee)')).toBeVisible();
    await denied(page, '/payroll', 403);
    await signOut(page);

    await signIn(page, data.emails.owner, data.password);
    await expect(page.getByText('(Company Admin)')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Offer letters' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Browser North' })).toBeVisible();
    await page.goto('/company/settings');
    await expect(page.getByText('Not configured').first()).toBeVisible();
    await expect(page.getByText('No statutory rate is stored or applied.')).toBeVisible();
    await denied(page, `/employees/${data.ids.southReport}`, 404);
    await denied(page, '/payments', 403);
    await page.goto('/employees');
    await expect(page.locator('body')).not.toContainText('report@browser-south.test');
    await page.getByRole('row', { name: /accounts@browser-north.test/ }).getByRole('button', { name: 'View' }).click();
    await expect(page.getByRole('heading', { name: 'BRN-ACC Person' })).toBeVisible();
    await page.getByRole('button', { name: 'Enable Accounts access' }).click();
    await expect(page.getByText('Accounts access is available to assign. Nobody received it automatically.')).toBeVisible();
    await page.locator('select', { hasText: 'Keep the current access level' }).selectOption({ label: 'Accounts' });
    await page.getByRole('button', { name: 'Save Changes' }).click();
    await expect(page.getByText('Employee updated.')).toBeVisible();
    await signOut(page);
});

test('accounts payroll confirmation does not describe a bank transfer', async ({ page }) => {
    await signIn(page, data.emails.accounts, data.password);
    await expect(page.getByText('(Accounts)')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Employees' })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Offer letters' })).toHaveCount(0);
    await page.goto('/employees');
    await expect(page.getByText('Your account cannot open Employees')).toBeVisible();
    await page.goto('/attendance');
    await expect(page.getByText('Your account cannot open Attendance')).toBeVisible();
    await page.goto('/leave');
    await expect(page.getByRole('button', { name: 'Approve' })).toHaveCount(0);

    for (const code of ['BRN-OWN', 'BRN-LEAD', 'BRN-REP', 'BRN-OUT', 'BRN-ACC']) {
        await page.goto('/salary');
        await page.locator('#salary-employee').selectOption({ label: `${code} Person · ${code}` });
        await page.locator('#basic').fill('10.00');
        await page.locator('#hra').fill('0.00');
        await page.locator('#special').fill('0.00');
        await page.locator('#effective').fill('2026-01-01');
        await page.locator('#currency').fill('INR');
        await page.getByRole('checkbox').check();
        await page.getByRole('button', { name: 'Save salary record' }).click();
        await expect(page.getByText('Salary record saved.')).toBeVisible();
    }

    await page.goto('/payroll/runs');
    await expect(page.getByText('No payroll runs yet.')).toBeVisible();
    await page.locator('#year').fill('2026');
    await page.locator('#month').fill('10');
    await page.getByRole('button', { name: 'Open run' }).click();
    await expect(page).toHaveURL(/\/payroll\/runs\/\d+$/);
    const runUrl = page.url();
    await page.getByRole('button', { name: 'Calculate' }).click();
    await expect(page.getByText('Not configured').first()).toBeVisible();
    await page.locator('label', { hasText: 'Note' }).locator('input').fill('Ready for approval');
    await page.getByRole('button', { name: 'Send for review' }).click();
    await expect(page.getByText('Under Review')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Approve' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Send for review' })).toHaveCount(0);
    expect(await postForm(page, `${new URL(runUrl).pathname}/approve`, { note: 'Trying anyway' })).toBe(403);
    await postForm(page, `${new URL(runUrl).pathname}/review`, { note: 'Ready for approval' }, false);
    await page.reload();
    await expect(page.getByText('Calculate this payroll run before sending it for review.')).toBeVisible();
    await signOut(page);

    await signIn(page, data.emails.owner, data.password);
    await page.goto(runUrl);
    await expect(page.getByRole('button', { name: 'Process payroll' })).toHaveCount(0);
    await page.getByRole('button', { name: 'Approve' }).click();
    await expect(page.getByText('Payroll approved.')).toBeVisible();
    expect(await postForm(page, `${new URL(runUrl).pathname}/process`, { note: 'Process now', confirm: 'PROCESS' })).toBe(403);
    await signOut(page);

    await signIn(page, data.emails.accounts, data.password);
    await page.goto(runUrl);
    await expect(page.getByRole('button', { name: 'Process payroll' })).toBeDisabled();
    await page.getByRole('checkbox', { name: /Confirm processing/ }).check();
    await page.locator('label', { hasText: 'Note' }).locator('input').fill('Process now');
    await page.getByRole('button', { name: 'Process payroll' }).click();
    await expect(page.getByText('This run is finalized.')).toBeVisible();
    await page.goto('/payments');
    await expect(page.getByText('It does not send money to a bank.')).toBeVisible();
    await expect(page.getByText('It is not a bank transfer.').first()).toBeVisible();
    await page.getByLabel('Payment date').first().fill('2026-10-30');
    await page.getByLabel('Reference').first().fill('BROWSER-REF');
    await page.getByRole('checkbox', { name: /not a bank transfer/ }).first().check();
    await page.getByRole('button', { name: 'Record payment' }).first().click();
    await expect(page.getByText('Payment recorded. This is a record of confirmation, not a bank transfer.')).toBeVisible();
    await signOut(page);

    await signIn(page, data.emails.report, data.password);
    await page.goto('/payslips');
    await expect(page.getByText('BRN-REP')).toBeVisible();
    await expect(page.locator('body')).not.toContainText('BRN-OUT');
    await page.goto('/inbox');
    await expect(page.getByRole('heading', { name: 'Help desk ticket updated' })).toBeVisible();
    await expect(page.locator('body')).not.toContainText('10.00');
    await denied(page, '/payslips/99999', 404);
    await signOut(page);

    await signIn(page, data.emails.southHr, data.password);
    await expect(page.getByRole('heading', { name: 'Browser South' })).toBeVisible();
    await denied(page, `/employees/${data.ids.northReport}`, 404);
    await page.goto('/help-desk');
    await expect(page.getByText('Door badge browser')).toHaveCount(0);
    await signOut(page);
});

test('linked offer letters follow the employee checklist', async ({ page }) => {
    const { execFileSync } = await import('node:child_process');
    const browserDatabase = process.env.PLAYWRIGHT_DB_DATABASE ?? 'meetara_hrms_browser';
    execFileSync('php', ['tests/Browser/seed-unlinked-offer.php'], {
        env: { ...process.env, DB_CONNECTION: 'mysql', DB_DATABASE: browserDatabase },
    });

    await signIn(page, data.emails.hr, data.password);
    await page.goto('/offer-letters');
    await expect(page.getByText('No employee record is linked.')).toBeVisible();
    await page.getByRole('button', { name: 'Save draft' }).click();
    await expect(page.getByText('The employee id field is required.')).toBeVisible();
    await page.getByLabel('Employee').selectOption({ label: 'BRN-REP · BRN-REP Person' });
    await page.locator('#offer-salary').fill('45000');
    await page.locator('#offer-joining').fill('2026-04-01');
    await page.locator('#offer-hr').fill('North HR');
    await page.locator('#offer-signature').fill('North HR');
    await page.getByRole('button', { name: 'Save draft' }).click();
    await expect(page.getByText('Offer letter draft saved.')).toBeVisible();

    const draft = page.locator('section', { has: page.getByRole('heading', { name: 'OPT Recruiter · BRN-REP Person' }) });
    await expect(draft.getByText(`Linked employee record ${data.ids.northReport}.`)).toBeVisible();
    await draft.getByRole('button', { name: 'Preview' }).click();
    await expect(page.getByText('Offer letter preview saved. It is not issued.')).toBeVisible();
    await expect(draft.getByText('DRAFT — NOT APPROVED FOR ISSUANCE')).toBeVisible();
    await expect(draft.getByText('[PLACEHOLDER: approved appointment-letter wording is not on file]')).toBeVisible();
    await expect(page.getByText('Issuance blocked — template approval required')).toBeVisible();
    await expect(page.getByText('A draft preview is not an approved offer letter.')).toBeVisible();
    await expect(draft.getByRole('button', { name: 'Issue' })).toHaveCount(0);

    await page.goto(`/employees/${data.ids.northReport}`);
    await expect(page.locator('li', { hasText: 'Offer letter generated/issued' }).filter({ hasText: 'Completed' })).toHaveCount(0);
    await signOut(page);

    await signIn(page, data.emails.owner, data.password);
    await page.goto('/offer-letters');
    await page.getByLabel('Employee').selectOption({ label: 'BRN-OUT · BRN-OUT Person' });
    await page.locator('#offer-salary').fill('45000');
    await page.locator('#offer-joining').fill('2026-04-01');
    await page.locator('#offer-hr').fill('North Owner');
    await page.locator('#offer-signature').fill('North Owner');
    await page.getByRole('button', { name: 'Save draft' }).click();
    const outside = page.locator('section', { has: page.getByRole('heading', { name: 'OPT Recruiter · BRN-OUT Person' }) });
    await outside.getByRole('button', { name: 'Preview' }).click();
    await expect(outside.getByRole('button', { name: 'Issue' })).toHaveCount(0);
    await signOut(page);

    await signIn(page, data.emails.report, data.password);
    await expect(page.getByRole('link', { name: 'Offer letters' })).toHaveCount(0);
    await denied(page, '/offer-letters', 403);
    await signOut(page);

    await signIn(page, data.emails.lead, data.password);
    await expect(page.getByRole('link', { name: 'Offer letters' })).toHaveCount(0);
    await denied(page, '/offer-letters', 403);
    await signOut(page);

    await signIn(page, data.emails.accounts, data.password);
    await expect(page.getByRole('link', { name: 'Offer letters' })).toHaveCount(0);
    await denied(page, '/offer-letters', 403);
    await signOut(page);

    await signIn(page, data.emails.southHr, data.password);
    await page.goto('/offer-letters');
    await expect(page.locator('#offer-employee option', { hasText: 'BRN-REP' })).toHaveCount(0);
    await expect(page.locator('body')).not.toContainText('BRN-REP Person');
    await denied(page, `/employees/${data.ids.northReport}`, 404);
    await signOut(page);
});

async function postForm(page: Page, path: string, form: Record<string, string>, follow = true): Promise<number> {
    return page.evaluate(async ({ path, form, follow }) => {
        const token = decodeURIComponent(document.cookie.split('; ').find((item) => item.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length) ?? '');
        const response = await fetch(path, {
            method: 'POST',
            redirect: follow ? 'follow' : 'manual',
            headers: {
                'X-XSRF-TOKEN': token,
                Accept: 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams(form),
        });

        return response.status;
    }, { path, form, follow });
}
