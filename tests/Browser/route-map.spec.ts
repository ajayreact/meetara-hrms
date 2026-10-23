import { expect, test, type Page } from '@playwright/test';
import { accounts, denied, finishClean, signIn, watchPage } from './support';

const data = accounts();

if (data.database !== 'meetara_hrms_routemap_browser_20261022') {
    throw new Error('Route Map browser tests run only against meetara_hrms_routemap_browser_20261022.');
}

test.describe.configure({ mode: 'serial' });

const geolocationStub = () => {
    const read = (key: string, fallback: string) => {
        try {
            return window.localStorage.getItem(key) ?? fallback;
        } catch {
            return fallback;
        }
    };
    const write = (key: string, value: string) => {
        try {
            window.localStorage.setItem(key, value);
        } catch {
            // about:blank has no storage
        }
    };
    const codes = { PERMISSION_DENIED: 1, POSITION_UNAVAILABLE: 2, TIMEOUT: 3 };
    const geolocation = {
        getCurrentPosition(success: PositionCallback, failure?: PositionErrorCallback | null) {
            write('geoCalls', String(Number(read('geoCalls', '0')) + 1));
            const mode = read('geoMode', 'deny');
            window.setTimeout(() => {
                if (mode === 'grant') {
                    success({ coords: { latitude: 22.5727, longitude: 88.3639, accuracy: 15 }, timestamp: Date.now() } as GeolocationPosition);
                    return;
                }
                failure?.({ ...codes, code: mode === 'timeout' ? 3 : 1, message: mode } as GeolocationPositionError);
            }, 50);
        },
        watchPosition() {
            write('geoWatch', '1');
            return 0;
        },
        clearWatch() {},
    };
    Object.defineProperty(navigator, 'geolocation', { value: geolocation, configurable: true });
};

async function prepare(page: Page, mode: 'deny' | 'grant' | 'timeout' = 'deny'): Promise<void> {
    await page.route('https://tile.openstreetmap.org/**', (route) => route.abort());
    await page.addInitScript(geolocationStub);
    await page.goto('/login');
    await page.evaluate((value) => {
        localStorage.setItem('geoMode', value);
        localStorage.setItem('geoCalls', '0');
        localStorage.removeItem('geoWatch');
    }, mode);
}

async function geoCalls(page: Page): Promise<number> {
    return page.evaluate(() => Number(localStorage.getItem('geoCalls') ?? '0'));
}

async function pageProps(page: Page): Promise<Record<string, unknown>> {
    const raw = await page.locator('#app').getAttribute('data-page');
    return (JSON.parse(raw ?? '{}') as { props: Record<string, unknown> }).props;
}

function officeCard(page: Page, name: string) {
    return page.locator('section').filter({ has: page.getByRole('heading', { name, exact: true }) }).first();
}

async function retryPost(page: Page, path: string, body: Record<string, string>): Promise<number> {
    return page.evaluate(async ({ path, body }) => {
        const token = decodeURIComponent(document.cookie.split('; ').find((item) => item.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
        const response = await fetch(path, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'text/html', 'X-XSRF-TOKEN': token },
            body: JSON.stringify(body),
            redirect: 'manual',
        });
        return response.status;
    }, { path, body });
}

test('company admin saves, validates, clears, and reloads office coordinates', async ({ page }, testInfo) => {
    watchPage(page, testInfo);
    await prepare(page);
    await signIn(page, data.emails.owner, data.password);
    await page.goto('/offices');
    await expect(page.getByText('does not have the office location columns yet')).toHaveCount(0);

    const annex = officeCard(page, 'North Annex');
    await expect(annex.getByText('Saved coordinates: not configured. Route Map lists this office as Not on map.')).toBeVisible();
    await annex.getByRole('button', { name: 'Edit office' }).click();
    const latitude = annex.getByRole('textbox', { name: /^Latitude/ });
    const longitude = annex.getByRole('textbox', { name: /^Longitude/ });
    await expect(latitude).toBeEnabled();
    await expect(longitude).toBeEnabled();
    await expect(annex.getByRole('textbox', { name: /^Map display name/ })).toBeEnabled();
    await expect(annex.getByRole('spinbutton', { name: /^Verification radius/ })).toBeEnabled();

    await latitude.fill('22.517');
    await annex.getByRole('button', { name: 'Save office' }).click();
    await expect(annex.getByText('Enter a longitude with the latitude, or clear both.')).toBeVisible();
    await latitude.fill('95');
    await longitude.fill('88.3654');
    await annex.getByRole('button', { name: 'Save office' }).click();
    await expect(annex.getByText('Latitude must be a number from -90 to 90.')).toBeVisible();
    await latitude.fill('22.5x');
    await annex.getByRole('button', { name: 'Save office' }).click();
    await expect(annex.getByText('Latitude must be a number from -90 to 90.')).toBeVisible();

    await annex.getByRole('application', { name: /Office map/ }).click({ position: { x: 100, y: 60 } });
    await expect(latitude).not.toHaveValue('22.5x');

    await latitude.fill('22.517');
    await longitude.fill('88.3654');
    await annex.getByRole('textbox', { name: /^Map display name/ }).fill('Annex Gate');
    await annex.getByRole('spinbutton', { name: /^Verification radius/ }).fill('250');
    await annex.getByRole('button', { name: 'Save office' }).click();
    await expect(page.getByText('Office updated.')).toBeVisible();

    await page.reload();
    const saved = officeCard(page, 'North Annex');
    await expect(saved.getByText(/Saved coordinates: 22\.517/)).toBeVisible();
    await saved.getByRole('button', { name: 'Edit office' }).click();
    expect(Number(await saved.getByRole('textbox', { name: /^Latitude/ }).inputValue())).toBeCloseTo(22.517, 6);
    expect(Number(await saved.getByRole('textbox', { name: /^Longitude/ }).inputValue())).toBeCloseTo(88.3654, 6);
    await expect(saved.getByRole('textbox', { name: /^Map display name/ })).toHaveValue('Annex Gate');
    await expect(saved.getByRole('spinbutton', { name: /^Verification radius/ })).toHaveValue('250');
    await expect(saved.getByRole('textbox', { name: /^Physical office address/ })).toHaveValue('40 Lake Road, Kolkata');
    await expect(officeCard(page, 'Main office').getByText('203.0.113.0/24')).toBeVisible();

    await saved.getByRole('button', { name: 'Clear coordinates' }).click();
    await saved.getByRole('button', { name: 'Save office' }).click();
    await page.reload();
    const cleared = officeCard(page, 'North Annex');
    await expect(cleared.getByText('Saved coordinates: not configured. Route Map lists this office as Not on map.')).toBeVisible();
    await cleared.getByRole('button', { name: 'Edit office' }).click();
    await expect(cleared.getByRole('textbox', { name: /^Physical office address/ })).toHaveValue('40 Lake Road, Kolkata');

    await cleared.getByRole('textbox', { name: /^Latitude/ }).fill('22.517');
    await cleared.getByRole('textbox', { name: /^Longitude/ }).fill('88.3654');
    await cleared.getByRole('button', { name: 'Save office' }).click();
    await page.reload();
    await expect(officeCard(page, 'North Annex').getByText(/Saved coordinates: 22\.517/)).toBeVisible();
    await finishClean(page);
});

test('route map shows office pins, a list fallback when tiles fail, and date filters', async ({ page }, testInfo) => {
    watchPage(page, testInfo);
    await prepare(page);
    await signIn(page, data.emails.owner, data.password);
    await page.goto('/route-map');

    await expect(page.getByRole('heading', { name: 'Route Map' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'North HQ', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Annex Gate', exact: true })).toBeVisible();
    await expect(page.getByText('South Campus')).toHaveCount(0);
    await expect(page.getByText('South Company Road')).toHaveCount(0);
    await expect(page.getByText(/Not on map/).first()).toBeVisible();
    await expect(page.getByText('Map tiles from OpenStreetMap could not be loaded').first()).toBeVisible();

    const props = await pageProps(page);
    const offices = (props.map as { offices: { mapLabel: string; latitude: number | null; longitude: number | null; onMap: boolean }[] }).offices;
    const annex = offices.find((office) => office.mapLabel === 'Annex Gate');
    expect(annex?.onMap).toBe(true);
    expect(annex?.latitude).toBeCloseTo(22.517, 6);
    expect(annex?.longitude).toBeCloseTo(88.3654, 6);
    expect(offices.find((office) => office.mapLabel === 'North Remote Desk')?.latitude).toBeNull();

    await page.getByRole('button', { name: 'List', exact: true }).click();
    await expect(page.getByRole('button', { name: 'North HQ', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: /North HQ/ }).first().click();
    const selected = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Selected office' }) });
    await expect(selected.getByText('12 Park Street, Kolkata')).toBeVisible();
    await expect(selected.getByText('Active')).toBeVisible();

    await page.locator('input[type="date"]').first().fill('2026-10-01');
    await page.locator('input[type="date"]').nth(1).fill('2026-10-10');
    await page.getByRole('button', { name: 'Apply' }).click();
    await expect(page.getByText('Clock-in · Recorded · 21 m')).toBeVisible();
    await expect(page.getByText('Clock-in · Recorded · 44 m')).toBeVisible();
    await expect(page.getByText('Clock-in · Recorded · 33 m')).toHaveCount(0);

    await page.locator('input[type="date"]').first().fill('2026-10-10');
    await page.locator('input[type="date"]').nth(1).fill('2026-10-16');
    await page.getByRole('button', { name: 'Apply' }).click();
    await expect(page.getByText('Clock-in · Recorded · 33 m')).toBeVisible();
    await expect(page.getByText('Clock-in · Recorded · 21 m')).toHaveCount(0);

    await page.locator('input[type="date"]').first().fill('2026-10-01');
    await page.locator('select').filter({ has: page.locator('option', { hasText: 'Everyone in scope' }) }).selectOption({ label: 'BRN-OUT Person' });
    await page.getByRole('button', { name: 'Apply' }).click();
    await expect(page.getByText('Clock-in · Recorded · 44 m')).toBeVisible();
    await expect(page.getByText('Clock-in · Recorded · 21 m')).toHaveCount(0);

    await page.goto('/company/settings');
    await expect(page.getByText('This switch does not replace approved public-network verification')).toBeVisible();
    const toggle = page.getByLabel('Save a browser location at clock-in and clock-out');
    await expect(toggle).not.toBeChecked();
    await toggle.check();
    await page.getByRole('button', { name: 'Save GPS setting' }).click();
    await expect(page.getByText('GPS capture at clock-in and clock-out is enabled.')).toBeVisible();
    await finishClean(page);
});

test('employee acknowledges the notice, a denied location keeps attendance, and retries add nothing', async ({ page }, testInfo) => {
    watchPage(page, testInfo);
    await prepare(page, 'deny');
    await signIn(page, data.emails.report, data.password);

    await page.goto('/my-attendance');
    await expect(page.getByText('it is not requested until the Route Map notice is acknowledged')).toBeVisible();

    await page.goto('/route-map');
    await expect(page.getByText('Retention: 30 days after capture.')).toBeVisible();
    await page.getByRole('button', { name: 'Acknowledge notice' }).click();
    await expect(page.getByText('You have acknowledged the current notice.')).toBeVisible();
    await expect(page.getByText(/Version 2026-10-22\.1\.r30/)).toBeVisible();
    await expect(page.locator('select').filter({ has: page.locator('option', { hasText: 'Everyone in scope' }) })).toHaveCount(0);
    await denied(page, `/route-map?employee=${data.ids.northOutside}`, 404);

    await page.goto('/route-map?from=2026-10-01&to=2026-10-22');
    await expect(page.getByText('Clock-in · Recorded · 21 m')).toBeVisible();
    await expect(page.getByText('Clock-in · Recorded · 44 m')).toHaveCount(0);
    await expect(page.getByText('BRN-OUT Person')).toHaveCount(0);

    await page.goto('/my-attendance');
    await expect(page.getByText('Optional GPS capture is on')).toBeVisible();
    expect(await geoCalls(page)).toBe(0);
    await page.getByRole('button', { name: 'Clock In' }).click();
    await expect(page.getByText('Location permission was denied. The attendance action was sent without coordinates.')).toBeVisible();
    await expect(page.getByText('Clock-in recorded.')).toBeVisible();
    expect(await geoCalls(page)).toBe(1);

    expect(await retryPost(page, '/attendance/clock-in', { latitude: '22.5727', longitude: '88.3639', capture_result: 'recorded' })).not.toBe(500);

    await page.evaluate(() => localStorage.setItem('geoMode', 'grant'));
    await page.getByRole('button', { name: 'Clock Out' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Clock Out' }).click();
    await expect(page.getByText('Clock-out recorded.')).toBeVisible();
    expect(await geoCalls(page)).toBe(2);
    expect(await retryPost(page, '/attendance/clock-out', { latitude: '22.5727', longitude: '88.3639', capture_result: 'recorded' })).not.toBe(500);

    await page.goto('/route-map');
    await expect(page.getByText('Clock-in · Permission denied')).toHaveCount(1);
    await expect(page.getByText('Clock-out · Recorded · 15 m')).toHaveCount(1);
    await expect(page.getByRole('button', { name: 'BRN-REP Person Clock-out' })).toHaveCount(1);
    await expect(page.getByRole('button', { name: 'BRN-REP Person Clock-in' })).toHaveCount(0);
    expect(await page.evaluate(() => localStorage.getItem('geoWatch'))).toBeNull();
    await finishClean(page);
});

test('a granted location cannot get past a failed network check', async ({ page }, testInfo) => {
    watchPage(page, testInfo);
    await prepare(page, 'grant');
    await signIn(page, data.emails.outside, data.password);
    await page.goto('/route-map');
    await page.getByRole('button', { name: 'Acknowledge notice' }).click();
    await expect(page.getByText('You have acknowledged the current notice.')).toBeVisible();

    await page.goto('/my-attendance');
    await page.getByRole('button', { name: 'Clock In' }).click();
    await expect(page.getByText('Clock-in was blocked because this connection is localhost. The server does not see the office public address.')).toBeVisible();
    await expect(page.getByText('Clock-in recorded.')).toHaveCount(0);
    expect(await geoCalls(page)).toBe(1);

    await page.goto('/route-map');
    await expect(page.getByText('No attendance location records are in this range.')).toBeVisible();
    await finishClean(page);
});

test('team lead, accounts, super admin, and another company stay inside their scope', async ({ page }, testInfo) => {
    watchPage(page, testInfo);
    await prepare(page);

    await signIn(page, data.emails.lead, data.password);
    await page.goto('/route-map?from=2026-10-01&to=2026-10-22');
    await expect(page.getByText('Clock-in · Recorded · 21 m')).toBeVisible();
    await expect(page.getByText('Clock-in · Recorded · 44 m')).toHaveCount(0);
    await expect(page.locator('select option', { hasText: 'BRN-OUT Person' })).toHaveCount(0);
    await denied(page, `/route-map?employee=${data.ids.northOutside}`, 404);
    await page.context().clearCookies();

    await signIn(page, data.emails.accounts, data.password);
    await page.goto('/route-map');
    await expect(page.getByText('Your account cannot open Route Map')).toBeVisible();
    expect(await page.content()).not.toContain('22.571111');
    await page.context().clearCookies();

    await signIn(page, data.emails.platform, data.password);
    await page.goto(`/route-map?employee=${data.ids.northReport}&from=2026-10-01&to=2026-10-22`);
    await expect(page.getByRole('button', { name: 'North HQ', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'South Campus', exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Attendance locations' })).toHaveCount(0);
    const platformProps = await pageProps(page);
    const platformMap = platformProps.map as { canViewPreciseLocations: boolean; points: unknown[]; acknowledgements: unknown[] };
    expect(platformMap.canViewPreciseLocations).toBe(false);
    expect(platformMap.points).toEqual([]);
    expect(platformMap.acknowledgements).toEqual([]);
    const platformHtml = await page.content();
    for (const value of ['22.571111', '22.573333', '22.580001', '22.5727']) {
        expect(platformHtml).not.toContain(value);
    }
    await page.goto('/dashboard');
    expect(await page.content()).not.toContain('22.5727');
    await page.context().clearCookies();

    await signIn(page, data.emails.southHr, data.password);
    await page.goto('/route-map');
    await expect(page.getByRole('button', { name: 'South Campus', exact: true })).toBeVisible();
    await expect(page.getByText('North HQ')).toHaveCount(0);
    await denied(page, `/route-map?employee=${data.ids.northReport}`, 404);
    await page.context().clearCookies();

    await signIn(page, data.emails.southReport, data.password);
    await page.goto('/my-attendance');
    await expect(page.getByText('Optional GPS capture is on')).toHaveCount(0);
    await page.getByRole('button', { name: 'Clock In' }).click();
    await expect(page.getByText('Clock-in was blocked because this connection is localhost. The server does not see the office public address.')).toBeVisible();
    expect(await geoCalls(page)).toBe(0);
    await finishClean(page);
});
