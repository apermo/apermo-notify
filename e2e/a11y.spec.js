const { test } = require('@playwright/test');
const { expectNoA11yViolations } = require('./helpers/a11y');

test.describe('Accessibility (WCAG 2.1 AA)', () => {
    test('homepage has no a11y violations', async ({ page }) => {
        await page.goto('/');
        await expectNoA11yViolations(page);
    });
});
