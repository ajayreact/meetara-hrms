import { expect, test } from '@playwright/test';
import { accounts, finishClean, signIn, watchPage } from './support';

const data = accounts();

if (data.database !== 'meetara_hrms_routemap_browser_20261022') {
    throw new Error('Overnight shift browser tests run only against meetara_hrms_routemap_browser_20261022.');
}

test('an administrator previews US times and saves an overnight India shift', async ({ page }, testInfo) => {
    watchPage(page, testInfo);
    await page.route('https://tile.openstreetmap.org/**', (route) => route.abort());
    await signIn(page, data.emails.owner, data.password);
    await page.goto('/offices');

    const start = page.locator('input[type="time"]').first();
    const end = page.locator('input[type="time"]').nth(1);
    await start.fill('09:30');
    await end.fill('09:30');
    await page.getByRole('button', { name: 'Save schedule' }).click();
    await expect(page.getByText('The end time must be different from the start time.')).toBeVisible();
    await expect(page.getByText('Attendance schedule saved for this company.')).toHaveCount(0);

    await start.fill('18:30');
    await end.fill('03:30');
    await page.getByLabel('Grace minutes').fill('10');
    await page.getByLabel('Preview US times for date.').fill('2026-01-15');

    const startPreview = page.getByText('Start in the United States').locator('xpath=..');
    const endPreview = page.getByText('End in the United States, next India day').locator('xpath=..');
    await expect(startPreview).toContainText('New York:');
    await expect(startPreview).toContainText('8:00 AM EST');
    await expect(startPreview).toContainText('Detroit, Michigan:');
    await expect(startPreview).toContainText('Dallas, Texas:');
    await expect(startPreview).toContainText('7:00 AM CST');
    await expect(startPreview).not.toContainText('Jan 15, 2026');
    await expect(endPreview).toContainText('5:00 PM EST');
    await expect(endPreview).toContainText('4:00 PM CST');
    await expect(endPreview).toContainText('Jan 15, 2026');
    await expect(page.getByText('The end time is earlier than the start time, so it is saved as the following day in India.')).toBeVisible();
    await expect(page.getByText('US times automatically adjust for daylight saving time. Converted times are a preview for the selected date.')).toBeVisible();

    await page.getByLabel('Preview US times for date.').fill('2026-07-15');
    await expect(startPreview).toContainText('9:00 AM EDT');
    await expect(startPreview).toContainText('8:00 AM CDT');
    await expect(endPreview).toContainText('6:00 PM EDT');
    await expect(endPreview).toContainText('5:00 PM CDT');
    await expect(endPreview).toContainText('Jul 15, 2026');

    await page.getByRole('button', { name: 'Save schedule' }).click();
    await expect(page.getByText('Attendance schedule saved for this company.')).toBeVisible();
    await page.reload();
    await expect(page.locator('input[type="time"]').first()).toHaveValue('18:30');
    await expect(page.locator('input[type="time"]').nth(1)).toHaveValue('03:30');
    await expect(page.getByLabel('Grace minutes')).toHaveValue('10');
    await expect(page.getByRole('checkbox', { name: 'saturday' })).not.toBeChecked();
    await expect(page.getByRole('checkbox', { name: 'sunday' })).not.toBeChecked();
    await expect(page.getByRole('checkbox', { name: 'monday' })).toBeChecked();
    await finishClean(page);
});
