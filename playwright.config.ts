import { defineConfig, devices } from '@playwright/test';

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
        // php -S keeps this process environment. php artisan serve can drop it and fall back to .env.
        command: 'php -S 127.0.0.1:8001 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php',
        cwd: 'public',
        url: 'http://127.0.0.1:8001/login',
        reuseExistingServer: false,
        timeout: 120_000,
        env: {
            DB_CONNECTION: 'mysql',
            DB_DATABASE: 'meetara_hrms_browser',
            APP_URL: 'http://127.0.0.1:8001',
            TRUSTED_PROXIES: '127.0.0.1',
        },
    },
    projects: [
        {
            name: 'desktop',
            testMatch: /roles\.spec\.ts/,
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
