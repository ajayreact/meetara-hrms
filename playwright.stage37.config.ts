import { defineConfig, devices } from '@playwright/test';

const database = 'meetara_hrms_stage37_browser_20261020';

export default defineConfig({
    testDir: 'tests/Browser',
    timeout: 240_000,
    expect: { timeout: 15_000 },
    fullyParallel: false,
    workers: 1,
    retries: 0,
    reporter: 'list',
    use: {
        baseURL: 'http://127.0.0.1:8001',
        trace: 'off',
        video: 'off',
        screenshot: 'only-on-failure',
        actionTimeout: 15_000,
    },
    webServer: {
        command: 'php artisan serve --host=127.0.0.1 --port=8001',
        url: 'http://127.0.0.1:8001/login',
        reuseExistingServer: false,
        timeout: 120_000,
        env: {
            DB_CONNECTION: 'mysql',
            DB_DATABASE: database,
            PLAYWRIGHT_DB_DATABASE: database,
            APP_URL: 'http://127.0.0.1:8001',
        },
    },
    projects: [
        {
            name: 'desktop',
            testMatch: /roles\.spec\.ts/,
            use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 } },
        },
        {
            name: 'historical',
            testMatch: /historical-decision\.spec\.ts/,
            dependencies: ['desktop'],
            use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 } },
        },
        {
            name: 'mobile',
            testMatch: /mobile\.spec\.ts/,
            dependencies: ['desktop'],
            use: { ...devices['Pixel 5'] },
        },
    ],
});
