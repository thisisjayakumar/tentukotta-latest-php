/**
 * Playwright configuration for the browser regression smoke.
 *
 * Run with:
 *   npx playwright test --config=tests/browser/playwright.config.js
 *
 * Uses the system Chrome so no browser binaries need to be downloaded.
 */
module.exports = {
    testDir: __dirname,
    timeout: 30000,
    fullyParallel: false,
    workers: 1,
    reporter: [['list']],
    use: {
        baseURL: process.env.STAGING_URL || 'http://localhost:8080',
        headless: true,
        ignoreHTTPSErrors: true,
        screenshot: 'only-on-failure',
        launchOptions: {
            executablePath: process.env.CHROME_PATH || '/usr/bin/google-chrome',
        },
    },
};
