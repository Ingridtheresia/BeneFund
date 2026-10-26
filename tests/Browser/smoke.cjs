// Run with Playwright installed or PLAYWRIGHT_MODULE pointing to its package directory.
// Uses its own SQLite file, local server, fake accounts, and no gateway requests.
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const { spawn, execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const php = process.env.PHP_BINARY || 'php';
const port = Number(process.env.BROWSER_TEST_PORT || 8765);
const origin = `http://127.0.0.1:${port}`;
const database = path.join(root, 'storage/framework/testing', `browser-${process.pid}.sqlite`);
const output = path.resolve(process.env.BROWSER_TEST_OUTPUT || path.join(root, 'storage/framework/testing/browser-output'));
const env = {
    ...process.env, APP_ENV: 'testing', APP_URL: origin, DB_CONNECTION: 'sqlite',
    DB_DATABASE: database, DB_URL: '', CACHE_STORE: 'array', SESSION_DRIVER: 'database',
    SESSION_SECURE_COOKIE: 'false', MIDTRANS_SERVER_KEY: '', MIDTRANS_CLIENT_KEY: '',
};

async function main() {
    fs.mkdirSync(path.dirname(database), { recursive: true });
    fs.mkdirSync(output, { recursive: true });
    fs.writeFileSync(database, '');
    let server;
    let browser;
    try {
        const fixture = JSON.parse(execFileSync(php, ['tests/Browser/fixture.php'], { cwd: root, env, encoding: 'utf8' }));
        const router = path.join(root, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php');
        server = spawn(php, ['-S', `127.0.0.1:${port}`, router], { cwd: path.join(root, 'public'), env, windowsHide: true, stdio: 'ignore' });
        for (let i = 0; i < 80; i++) {
            try {
                const ready = await fetch(`${origin}/up`);
                if (ready.ok) break;
            } catch {}
            if (i === 79) throw new Error('The isolated PHP test server did not become ready.');
            await new Promise(resolve => setTimeout(resolve, 250));
        }
        browser = await chromium.launch({ headless: true, ...(process.env.BROWSER_EXECUTABLE ? { executablePath: process.env.BROWSER_EXECUTABLE } : {}) });
        const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
        await context.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(`${origin}/login`);
        await page.getByLabel('Email', { exact: true }).fill('donor@browser.test');
        await page.getByLabel('Password', { exact: true }).fill('BrowserTest123!');
        await page.getByRole('button', { name: 'Masuk', exact: true }).click();
        await page.waitForURL('**/dashboard');
        assert.equal(await page.locator('[data-notification-count]').innerText(), '17');

        await page.goto(`${origin}/campaigns/${fixture.campaign}`);
        assert.equal(await page.locator('input[name="payment_channel"]:checked').inputValue(), 'other_qris');
        await page.getByLabel('Metode Pembayaran', { exact: true }).selectOption('bank');
        await page.getByRole('radio', { name: 'BNI Virtual Account', exact: true }).check();
        assert.equal(await page.locator('input[name="payment_channel"]:checked').inputValue(), 'bni_va');
        assert.match(await page.locator('#donation-total').innerText(), /104\.500/);
        assert.equal(await page.locator('input[name="payment_channel"]:enabled').count(), 6);
        for (const logo of await page.locator('.payment-channel-group[data-method="bank"] img').all()) {
            assert.equal(await logo.evaluate(image => image.complete && image.naturalWidth > 0), true, 'Official bank logo must load locally');
        }
        await page.screenshot({ path: path.join(output, 'payments-desktop.png'), fullPage: true });
        await page.getByLabel('Metode Pembayaran', { exact: true }).selectOption('ewallet');
        await page.getByRole('radio', { name: 'ShopeePay', exact: true }).check();
        assert.equal(await page.locator('input[name="payment_channel"]:checked').inputValue(), 'shopeepay');
        assert.match(await page.locator('#donation-total').innerText(), /101\.500/);
        await page.getByLabel('Metode Pembayaran', { exact: true }).selectOption('qris');
        assert.match(await page.locator('#donation-total').innerText(), /100\.000/);

        for (const width of [320, 375, 768, 1024, 1280, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            for (const url of ['/about', `/campaigns/${fixture.campaign}`, `/payments/${fixture.payment}`, '/notifications']) {
                const response = await page.goto(origin + url);
                assert.equal(response.status(), 200, url);
                const dimensions = await page.evaluate(() => ({ viewport: window.innerWidth, content: document.documentElement.scrollWidth }));
                assert.ok(dimensions.content <= dimensions.viewport + 1, `${url} overflows at ${width}px: ${dimensions.content}`);
            }
            if (width === 375) {
                assert.equal(await page.locator('[data-notification-link]').isVisible(), true);
                await page.getByRole('button', { name: 'Menu', exact: true }).click();
                assert.equal(await page.getByRole('navigation', { name: 'Navigasi utama' }).isVisible(), true);
                await page.getByRole('button', { name: 'Menu', exact: true }).click();
                await page.screenshot({ path: path.join(output, 'notifications-mobile.png'), fullPage: true });
                await page.goto(`${origin}/about`);
                await page.screenshot({ path: path.join(output, 'about-mobile.png'), fullPage: true });
            }
        }

        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.goto(`${origin}/about`);
        await page.screenshot({ path: path.join(output, 'about-desktop.png'), fullPage: true });
        await page.goto(`${origin}/payments/${fixture.payment}`);
        assert.equal(await page.locator('.payment-selected-channel img').evaluate(image => image.complete && image.naturalWidth > 0 && image.src.endsWith('/bni.png')), true);
        await page.goto(`${origin}/notifications`);
        assert.equal(await page.locator('.notification-item').count(), 15);
        await page.route('**/notifications/status', route => route.fulfill({
            json: { unread_count: 18, latest_id: 'new-test-activity' },
        }));
        await page.waitForFunction(() => document.querySelector('[data-notification-count]').textContent === '18', null, { timeout: 35000 });
        assert.equal(await page.locator('[data-notification-refresh]').isVisible(), true);
        await page.unroute('**/notifications/status');
        await page.reload();
        await page.locator('.notification-item').first().getByRole('button', { name: 'Tandai dibaca', exact: true }).click();
        await page.waitForURL('**/notifications');
        assert.equal(await page.locator('[data-notification-count]').innerText(), '16');
        await page.getByRole('button', { name: 'Tandai semua dibaca', exact: true }).click();
        await page.waitForURL('**/notifications');
        assert.equal(await page.locator('[data-notification-count]').isVisible(), false);
        await page.getByRole('link', { name: 'Belum dibaca', exact: true }).click();
        await page.getByRole('heading', { name: 'Semua sudah terbaca' }).waitFor();

        const noJs = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 375, height: 900 } });
        await noJs.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
        const fallback = await noJs.newPage();
        await fallback.goto(`${origin}/login`);
        await fallback.getByLabel('Email', { exact: true }).fill('donor@browser.test');
        await fallback.getByLabel('Password', { exact: true }).fill('BrowserTest123!');
        await fallback.getByRole('button', { name: 'Masuk', exact: true }).click();
        await fallback.waitForURL('**/dashboard');
        await fallback.goto(`${origin}/campaigns/${fixture.campaign}`);
        await fallback.getByLabel('Metode Pembayaran', { exact: true }).selectOption('bank');
        await fallback.getByRole('radio', { name: 'BNI Virtual Account', exact: true }).check();
        await fallback.getByRole('button', { name: 'Lanjut Donasi' }).click();
        await fallback.waitForURL('**/payments/*');
        assert.match(await fallback.locator('.payment-selected-channel').innerText(), /BNI Virtual Account/);
        assert.match(await fallback.locator('#payment-panel').innerText(), /104\.500/);
        assert.deepEqual(errors, [], 'Browser must have no uncaught JavaScript errors');
        console.log('PASS: payment choices/fees/logos, inbox/read actions/pagination/live badge updates, mobile navigation, 24 responsive page checks, and checkout without JavaScript.');
        console.log(`Screenshots: ${output}`);
    } finally {
        if (browser) await browser.close();
        if (server && server.exitCode === null) {
            await new Promise(resolve => {
                server.once('exit', resolve);
                server.kill();
            });
        }
        // Keep the disposable database beside the screenshots for inspection; it is never the app database.
    }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
