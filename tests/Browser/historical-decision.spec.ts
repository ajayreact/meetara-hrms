import { expect, test, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { accounts, denied, finishClean, signIn, signOut, watchPage } from './support';

const database = process.env.PLAYWRIGHT_DB_DATABASE ?? '';
const data = accounts();

type HistoricalSeed = {
    database: string;
    employeeId: number;
    panTaskId: number;
    tasks: Record<string, number>;
};

test.describe.configure({ mode: 'serial' });

test.beforeEach(({ page }, testInfo) => {
    watchPage(page, testInfo);
});

test.afterEach(async ({ page }, testInfo) => {
    if (testInfo.status === testInfo.expectedStatus) {
        await finishClean(page);
    }
});

test('historical decisions stay off required tasks and unauthorized roles', async ({ page }) => {
    expect(database).toBe('meetara_hrms_stage37_browser_20261020');
    expect(data.database).toBe(database);
    execFileSync('php', ['tests/Browser/seed-historical-tasks.php'], {
        env: { ...process.env, DB_CONNECTION: 'mysql', DB_DATABASE: database },
    });
    const seed = JSON.parse(readFileSync('storage/framework/stage36-historical.json', 'utf8')) as HistoricalSeed;
    expect(seed.database).toBe(database);

    const employeePath = `/employees/${seed.employeeId}`;
    await signIn(page, data.emails.hr, data.password);
    await page.goto(employeePath);
    const checklist = page.locator('section', { has: page.getByRole('heading', { name: 'Onboarding checklist' }) });
    const historical = page.locator('section', { has: page.getByRole('heading', { name: 'Historical Onboarding Tasks' }) });
    await expect(historical.getByText('These tasks are not part of the seven required checklist items.')).toBeVisible();
    await expect(checklist.getByRole('button', { name: 'Save decision' })).toHaveCount(0);
    await expect(historical.getByRole('button', { name: 'Save decision' })).toHaveCount(4);
    await expect(checklist.locator('li', { hasText: 'PAN document uploaded and reviewed' })).toContainText('Pending');

    const documents = historical.locator('li', { hasText: 'Share personal documents' });
    await documents.locator('select').selectOption('completed');
    await documents.getByRole('checkbox', { name: /Confirm this decision/ }).check();
    await documents.getByRole('button', { name: 'Save decision' }).click();
    await expect(documents.getByText('The note field is required.')).toBeVisible();
    await expect(documents.getByText('Pending', { exact: true })).toBeVisible();

    await documents.locator('textarea').fill('Synthetic completed note.');
    await documents.getByRole('button', { name: 'Save decision' }).click();
    await expect(page.getByText('Historical task decision saved.')).toBeVisible();
    await expect(documents.locator('span').filter({ hasText: /^Completed$/ })).toBeVisible();
    await expect(documents.locator('p').filter({ hasText: /^North HR · / })).toBeVisible();
    await expect(documents.getByRole('paragraph').filter({ hasText: 'Synthetic completed note.' })).toBeVisible();

    const introduction = historical.locator('li', { hasText: 'Introduce the team' });
    await introduction.locator('select').selectOption('not_applicable');
    await introduction.getByRole('checkbox', { name: /Confirm this decision/ }).check();
    await introduction.getByRole('button', { name: 'Save decision' }).click();
    await expect(introduction.getByText('The note field is required.')).toBeVisible();
    await introduction.locator('textarea').fill('Synthetic not applicable note.');
    await introduction.getByRole('button', { name: 'Save decision' }).click();
    await expect(introduction.locator('span').filter({ hasText: /^Not applicable$/ })).toBeVisible();
    await expect(introduction.getByText(/Decision recorded by North HR/)).toBeVisible();
    await expect(introduction.locator('p').filter({ hasText: /^North HR · / })).toHaveCount(0);
    await expect(introduction.getByRole('paragraph').filter({ hasText: 'Synthetic not applicable note.' })).toBeVisible();

    const laptop = historical.locator('li', { hasText: 'Issue laptop and access' });
    await laptop.locator('select').selectOption('pending');
    await laptop.locator('textarea').fill('');
    await laptop.getByRole('checkbox', { name: /Confirm this decision/ }).check();
    await laptop.getByRole('button', { name: 'Save decision' }).click();
    await expect(laptop.locator('span').filter({ hasText: /^Pending$/ })).toBeVisible();
    await expect(laptop.getByText(/Decision recorded by North HR/)).toBeVisible();
    await expect(laptop.locator('p').filter({ hasText: /^North HR · / })).toHaveCount(0);
    await expect(checklist.locator('li', { hasText: 'PAN document uploaded and reviewed' })).toContainText('Pending');
    await expect(checklist.locator('li', { hasText: 'Offer letter generated/issued' })).toContainText('Completed');
    expect(await postForm(page, `/employees/${seed.employeeId}/onboarding-tasks/${seed.panTaskId}/decision`, {
        outcome: 'completed',
        note: 'A required task must stay unchanged.',
        confirm: 'RECORD',
    })).toBe(422);
    await page.reload();
    await expect(checklist.locator('li', { hasText: 'PAN document uploaded and reviewed' })).toContainText('Pending');
    await documents.locator('select').selectOption('pending');
    await documents.locator('textarea').fill('Reopened as still open.');
    await documents.getByRole('checkbox', { name: /Confirm this decision/ }).check();
    await documents.getByRole('button', { name: 'Save decision' }).click();
    await expect(documents.locator('span').filter({ hasText: /^Pending$/ })).toBeVisible();
    await expect(documents.getByText(/Decision recorded by North HR/)).toBeVisible();
    await expect(documents.locator('p').filter({ hasText: /^North HR · / })).toHaveCount(0);
    await signOut(page);

    await signIn(page, data.emails.owner, data.password);
    await page.goto(employeePath);
    await expect(page.locator('section', { has: page.getByRole('heading', { name: 'Historical Onboarding Tasks' }) }).getByRole('button', { name: 'Save decision' })).toHaveCount(4);
    await signOut(page);

    await signIn(page, data.emails.lead, data.password);
    await page.goto(employeePath);
    await expect(page.getByText('Issue laptop and access')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Save decision' })).toHaveCount(0);
    expect(await postForm(page, `/employees/${seed.employeeId}/onboarding-tasks/${seed.tasks['Issue laptop and access']}/decision`, {
        outcome: 'completed',
        note: 'A team lead cannot decide this.',
        confirm: 'RECORD',
    })).toBe(403);
    await signOut(page);

    await signIn(page, data.emails.report, data.password);
    await page.goto(employeePath);
    await expect(page.getByRole('button', { name: 'Save decision' })).toHaveCount(0);
    expect(await postForm(page, `/employees/${seed.employeeId}/onboarding-tasks/${seed.tasks['Walk through company policies']}/decision`, {
        outcome: 'completed',
        note: 'An employee cannot decide this.',
        confirm: 'RECORD',
    })).toBe(403);
    await signOut(page);

    await signIn(page, data.emails.accounts, data.password);
    await denied(page, employeePath, 404);
    await signOut(page);

    await signIn(page, data.emails.platform, data.password);
    await denied(page, employeePath, 404);
    await signOut(page);

    await signIn(page, data.emails.southHr, data.password);
    await denied(page, employeePath, 404);
    expect(await postForm(page, `/employees/${seed.employeeId}/onboarding-tasks/${seed.tasks['Walk through company policies']}/decision`, {
        outcome: 'completed',
        note: 'Another company cannot decide this.',
        confirm: 'RECORD',
    })).toBe(404);
    await signOut(page);
});

async function postForm(page: Page, path: string, form: Record<string, string>): Promise<number> {
    return page.evaluate(async ({ path, form }) => {
        const token = decodeURIComponent(document.cookie.split('; ').find((item) => item.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length) ?? '');
        const body = new URLSearchParams(form);
        const response = await fetch(path, {
            method: 'POST',
            redirect: 'manual',
            headers: {
                'X-XSRF-TOKEN': token,
                Accept: 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body,
        });

        return response.status;
    }, { path, form });
}
