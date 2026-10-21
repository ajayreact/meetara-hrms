import { expect, type Page, type TestInfo } from '@playwright/test';
import { readFileSync } from 'node:fs';

export interface BrowserAccounts {
    database: string;
    password: string;
    emails: Record<string, string>;
    ids: { northReport: number; northOutside: number; southReport: number };
}

export function accounts(): BrowserAccounts {
    const data = JSON.parse(readFileSync('storage/framework/browser-credentials.json', 'utf8')) as BrowserAccounts;

    if (data.database === 'meetara_hrms') {
        throw new Error('The live database meetara_hrms is prohibited.');
    }

    if (!['meetara_hrms_browser', 'meetara_hrms_stage28_20261020', 'meetara_hrms_stage36_browser_20261020', 'meetara_hrms_stage37_browser_20261020', 'meetara_hrms_stage38_browser_20261020'].includes(data.database)) {
        throw new Error('Browser credentials are not for an allowed disposable database.');
    }

    return data;
}

export function watchPage(page: Page, testInfo: TestInfo): void {
    const problems: string[] = [];
    page.on('pageerror', (error) => problems.push(error.message));
    page.on('console', (message) => {
        if (message.type() !== 'error') {
            return;
        }
        const text = message.text();
        if (text.includes('favicon') || text.includes('Failed to load resource')) {
            return;
        }
        problems.push(text);
    });
    (page as Page & { __problems?: string[] }).__problems = problems;
}

export async function finishClean(page: Page): Promise<void> {
    const problems = (page as Page & { __problems?: string[] }).__problems ?? [];
    expect(problems, problems.join('\n')).toEqual([]);
}

export async function signIn(page: Page, email: string, password: string): Promise<void> {
    for (let attempt = 0; attempt < 2; attempt++) {
        const logoResponse = page.waitForResponse((response) => response.url().includes('/brand/meetara-logo.png'));
        await page.goto('/login');
        expect((await logoResponse).status(), 'Meetara logo request').toBe(200);
        const logo = page.getByRole('img', { name: 'Meetara' }).first();
        await expect(logo).toBeVisible();
        await expect.poll(() => logo.evaluate((image: HTMLImageElement) => image.naturalWidth)).toBeGreaterThan(0);
        await page.getByLabel('Work email').fill(email);
        await page.getByLabel('Password', { exact: true }).fill(password);
        const posted = page.waitForResponse((response) => response.url().endsWith('/login') && response.request().method() === 'POST');
        await page.getByRole('button', { name: 'Log in' }).click();
        if ((await posted).status() !== 429) {
            break;
        }
        await page.waitForTimeout(61_000);
    }
    await expect(page).toHaveURL(/\/dashboard$/);
    await expect(page.locator('body')).not.toContainText('Acme People');
}

export async function signOut(page: Page): Promise<void> {
    const logout = page.getByRole('button', { name: 'Logout' });
    if (await logout.count() === 0) {
        await page.goto('/dashboard');
    }
    await logout.click();
    await expect(page).toHaveURL(/\/login$/);
}

export async function denied(page: Page, path: string, status: number): Promise<void> {
    const response = await page.goto(path);
    expect(response?.status()).toBe(status);
    await expect(page.locator('body')).not.toContainText('APP_KEY');
}
