/**
 * End-to-end test for the apermo-notify v0.1 subscribe flow.
 *
 * The test:
 *   1. As an admin, creates a published post that contains the
 *      [apermo_notify] shortcode.
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

    test('admin creates a post containing the [apermo_notify] shortcode', async ({
        browser,
    }) => {
        const context = await browser.newContext({ storageState: ADMIN_STORAGE });
        const page = await context.newPage();

        await page.goto('/wp-admin/post-new.php');

        // Bail out cleanly if Gutenberg's "welcome guide" or any modal is open
        // (it intercepts clicks on the title field).
        const closeWelcome = page.locator(
            'button[aria-label="Close"], button.components-modal__header-icon'
        );
        if (await closeWelcome.first().isVisible().catch(() => false)) {
            await closeWelcome.first().click().catch(() => undefined);
        }

        const titleSelector = '[aria-label="Add title"], textarea#title';
        await page.locator(titleSelector).first().fill('apermo-notify E2E target');

        // Use the classic-editor textarea if present, else Gutenberg's
        // code-editor mode for a stable HTML insertion path.
        const classicTextarea = page.locator('textarea#content');
        if (await classicTextarea.isVisible().catch(() => false)) {
            await classicTextarea.fill('[apermo_notify]');
        } else {
            // Switch to code-editor mode in Gutenberg via keyboard shortcut.
            await page.keyboard.press('Control+Shift+Alt+M');
            const codeEditor = page.locator('textarea.editor-post-text-editor');
            await codeEditor.fill('[apermo_notify]');
        }

        await page.locator('#publish, button.editor-post-publish-button').first().click();

        // Confirm the second "Publish" button in the Gutenberg pre-publish panel
        // if it appears.
        const confirmPublish = page.locator(
            'button.editor-post-publish-button__button'
        );
        if (await confirmPublish.isVisible().catch(() => false)) {
            await confirmPublish.click();
        }

        await page.waitForURL(/post=\d+/);
        const viewLink = page.locator('a:has-text("View post"), a.post-permalink').first();
        postUrl = await viewLink.getAttribute('href');
        expect(postUrl).toBeTruthy();

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
