import { expect, test } from '@playwright/test';
import { accounts, finishClean, signIn, signOut, watchPage } from './support';

const database = process.env.PLAYWRIGHT_DB_DATABASE ?? '';
const data = accounts();

test.beforeEach(({ page }, testInfo) => {
    watchPage(page, testInfo);
});

test.afterEach(async ({ page }, testInfo) => {
    if (testInfo.status === testInfo.expectedStatus) {
        await finishClean(page);
    }
});

test('document review submits without a script error', async ({ page }) => {
    expect(database).toBe('meetara_hrms_stage38_browser_20261020');
    expect(data.database).toBe(database);

    await signIn(page, data.emails.hr, data.password);
    await page.goto(`/employees/${data.ids.northReport}`);
    await page.getByRole('button', { name: 'Start onboarding checklist' }).click();
    await expect(page.getByRole('heading', { name: 'Employee documents' })).toBeVisible();

    const documents = page.locator('section', { has: page.getByRole('heading', { name: 'Employee documents' }) });
    await documents.locator('select').first().selectOption('pan');
    await documents.locator('input[type="file"]').setInputFiles({
        name: 'pan-card.pdf',
        mimeType: 'application/pdf',
        buffer: Buffer.from('%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF'),
    });
    await documents.getByRole('button', { name: 'Upload document' }).click();
    await expect(page.getByText('Document uploaded. It stays pending until an authorized reviewer verifies it.')).toBeVisible();

    const card = documents.locator('li').first();
    await card.getByRole('button', { name: 'Save review' }).click();
    await expect(card.getByText('The confirm field is required.')).toBeVisible();

    await card.locator('select').selectOption('rejected');
    await card.getByRole('checkbox', { name: /Confirm this review with REJECT/ }).check();
    await card.getByRole('button', { name: 'Save review' }).click();
    await expect(card.getByText('The note field is required when status is rejected.')).toBeVisible();

    await card.getByPlaceholder('Review note').fill('Synthetic rejection note.');
    await card.getByRole('button', { name: 'Save review' }).click();
    await expect(page.getByText('Document review saved.')).toBeVisible();
    await expect(card.locator('span', { hasText: /^Rejected$/ })).toBeVisible();
    await expect(card.getByText('Synthetic rejection note.')).toBeVisible();
    await signOut(page);

    await signIn(page, data.emails.lead, data.password);
    await page.goto(`/employees/${data.ids.northReport}`);
    await expect(page.getByRole('heading', { name: 'Employee documents' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Save review' })).toHaveCount(0);
    await signOut(page);
});
