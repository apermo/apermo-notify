/**
 * End-to-end test for the apermo-notify v0.1 subscribe flow.
 *
 * The test:
 *   1. As an admin (via the WP REST API + nonce), creates a published post
 *      that contains the [apermo_notify] shortcode.
 *   2. Visits the post anonymously, submits the subscribe form, and asserts
 *      the success flash message is rendered.
 *   3. Runs axe-core on the subscribe form to catch obvious WCAG violations.
 *
 * The full email round-trip (confirm + unsubscribe links from a MailHog
 * fixture) is deferred to a follow-up spec because it needs a running mail
 * catcher in CI. Local users can verify that flow manually:
 *   - DDEV: open http://localhost:8025 (MailHog) after submitting.
 *   - wp-env: configure WP_DEFAULT_THEME and an SMTP catcher of your choice.
 */
const { test, expect } = require('@playwright/test');
const { expectNoA11yViolations } = require('./helpers/a11y');

const ADMIN_STORAGE = '.auth/admin.json';

test.describe.serial('apermo-notify v0.1 subscribe flow', () => {
    let postUrl;

    test.beforeAll(async ({ browser }) => {
        // Use the admin's stored session to call /wp-json/wp/v2/posts. This
        // bypasses Gutenberg's UI (which renders inside an iframe canvas in
        // WP 6.3+ and is brittle to drive headlessly).
        const context = await browser.newContext({ storageState: ADMIN_STORAGE });
        const page = await context.newPage();

        // `wpApiSettings.nonce` is localized on most admin screens — fetch it
        // from the dashboard, which is the lightest page that includes it.
        await page.goto('/wp-admin/');
        const nonce = await page.evaluate(() => window.wpApiSettings && window.wpApiSettings.nonce);
        expect(nonce, 'REST nonce should be available on /wp-admin/').toBeTruthy();

        const response = await page.request.post('/wp-json/wp/v2/posts', {
            headers: { 'X-WP-Nonce': nonce },
            data: {
                title: 'apermo-notify E2E target',
                content: '[apermo_notify]',
                status: 'publish',
            },
        });
        expect(
            response.ok(),
            `Expected post creation to succeed, got HTTP ${response.status()}`
        ).toBeTruthy();
        const post = await response.json();
        postUrl = post.link;
        expect(postUrl, 'REST response should include a permalink').toBeTruthy();

        await context.close();
    });

    test('anonymous visitor sees the form, submits it, sees the pending flash', async ({
        browser,
    }) => {
        test.skip(!postUrl, 'Post URL not captured by the admin step.');

        // Fresh context with no admin storage — exercises the nopriv path.
        const context = await browser.newContext();
        const page = await context.newPage();

        await page.goto(postUrl);

        const form = page.locator('form.apermo-notify-form');
        await expect(form).toBeVisible();

        await form.locator('input[name="email"]').fill('e2e-visitor@example.tld');
        await form.locator('button[type="submit"]').click();

        await expect(
            page.locator('.apermo-notify-message--pending')
        ).toContainText(/inbox/i);

        await context.close();
    });

    test('subscribe form passes axe-core WCAG 2.1 AA checks', async ({ browser }) => {
        test.skip(!postUrl, 'Post URL not captured by the admin step.');

        const context = await browser.newContext();
        const page = await context.newPage();

        await page.goto(postUrl);
        await expectNoA11yViolations(page);

        await context.close();
    });
});
