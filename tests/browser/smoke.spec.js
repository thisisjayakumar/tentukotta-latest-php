const { test, expect } = require('@playwright/test');

test('home page renders the themed shell', async ({ page }) => {
    const response = await page.goto('/');
    expect(response.status()).toBe(200);
    await expect(page).toHaveTitle(/Pudhu Padam/i);
    expect((await page.content()).length).toBeGreaterThan(1000);
    expect(await page.locator('a[href]').count()).toBeGreaterThan(5);
});

test('movies listing responds and renders content', async ({ page }) => {
    const response = await page.goto('/movies');
    expect(response.status()).toBe(200);
    expect((await page.content()).length).toBeGreaterThan(1000);
    expect(await page.locator('a[href]').count()).toBeGreaterThan(5);
});

test('search page responds', async ({ page }) => {
    const response = await page.goto('/search?q=movie');
    expect(response.status()).toBe(200);
    expect((await page.content()).length).toBeGreaterThan(1000);
});

test('user login page renders a password form', async ({ page }) => {
    const response = await page.goto('/user/login');
    expect(response.status()).toBe(200);
    await expect(page.locator('input[type="password"]').first()).toBeVisible();
});

test('anonymous admin dashboard is refused', async ({ page }) => {
    const response = await page.goto('/admin/dashboard');
    expect(response.status()).toBe(403);
});
