import { defineConfig, devices } from '@playwright/test';

const database = 'meetara_hrms_routemap_browser_20261022';

export default defineConfig({
    testDir: 'tests/Browser',
    testMatch: /(route-map|overnight-shift)\.spec\.ts/,
    timeout: 240_000,
    expect: { timeout: 15_000 },
    fullyParallel: false,
    workers: 1,
    retries: 0,
    reporter: 'list',
    use: {
        ...devices['Desktop Chrome'],
        baseURL: 'http://127.0.0.1:8002',
        viewport: { width: 1280, height: 900 },
        // Loopback can never be an approved network, so requests arrive as if through a trusted proxy.
        extraHTTPHeaders: { 'X-Forwarded-For': '203.0.113.10' },
        trace: 'off',
        video: 'off',
        screenshot: 'only-on-failure',
        actionTimeout: 15_000,
    },
    webServer: {
        // php -S keeps this process environment, so DB_DATABASE cannot fall back to the .env value.
        command: 'php -S 127.0.0.1:8002 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php',
        cwd: 'public',
        url: 'http://127.0.0.1:8002/login',
        reuseExistingServer: false,
        timeout: 120_000,
        env: {
            DB_CONNECTION: 'mysql',
            DB_DATABASE: database,
            APP_URL: 'http://127.0.0.1:8002',
            TRUSTED_PROXIES: '127.0.0.1',
        },
    },
});
