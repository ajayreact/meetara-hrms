import { expect, test } from '@playwright/test';
import { accounts, signIn } from './support';

const data = accounts();

test('mobile navigation and help desk remain usable', async ({ page }) => {
    const problems: string[] = [];
    page.on('pageerror', (error) => problems.push(error.message));
    await signIn(page, data.emails.report, data.password);
    await page.getByRole('button', { name: 'Open navigation' }).click();
    await expect(page.getByRole('link', { name: 'Help Desk' })).toBeVisible();
    await page.getByRole('link', { name: 'Help Desk' }).click();
    await expect(page.getByRole('button', { name: 'Create ticket' })).toBeVisible();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
    expect(overflow).toBe(false);
    await page.getByRole('button', { name: 'Open navigation' }).click();
    await page.getByRole('button', { name: 'Logout' }).click();
    await expect(page).toHaveURL(/\/login$/);
    expect(problems).toEqual([]);
});
