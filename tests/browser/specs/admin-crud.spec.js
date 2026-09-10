import { test, expect } from '@playwright/test';

const password = 'VstaDemo!2026';

async function login(page, email) {
  await page.goto('/admin/login');
  await page.getByLabel('Email address').fill(email);
  await page.locator('input[type="password"]').fill(password);
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).toHaveURL(/\/admin\/?$/);
}

async function expectHealthyAdminPage(page, route) {
  const response = await page.goto(route);
  expect(response?.ok(), `${route} returned ${response?.status()}`).toBeTruthy();
  await expect(page.locator('main')).toBeVisible();
  await expect(page.getByText('Internal Server Error', { exact: true })).toHaveCount(0);
  await expect(page.getByText('MissingTenantContext', { exact: false })).toHaveCount(0);
  await expect(page.getByText('ValueError', { exact: true })).toHaveCount(0);
}

test('platform administrator renders every representative admin module', async ({ page }) => {
  test.setTimeout(240_000);
  await login(page, 'superadmin@demo.vsta.local');

  const routes = [
    '/admin',
    '/admin/organizations',
    '/admin/users',
    '/admin/roles',
    '/admin/permissions',
    '/admin/sites',
    '/admin/charging-stations',
    '/admin/evses',
    '/admin/connectors',
    '/admin/operating-hours',
    '/admin/connector-statuses',
    '/admin/tariffs',
    '/admin/charging-sessions',
    '/admin/charger-commands',
    '/admin/session-reviews',
    '/admin/network-map',
    '/admin/master-data',
    '/admin/inventory-items',
    '/admin/warehouses',
    '/admin/reorder-points',
    '/admin/purchase-requests',
    '/admin/purchase-orders',
    '/admin/goods-receipts',
    '/admin/vendor-invoices',
    '/admin/count-plans',
    '/admin/inventory-adjustments',
    '/admin/stock-movements',
    '/admin/stock-transfers',
    '/admin/maintenance-incidents',
    '/admin/work-orders',
    '/admin/preventive-plans',
    '/admin/payment-providers',
    '/admin/payment-intents',
    '/admin/invoices',
    '/admin/finance-reviews',
    '/admin/reconciliation-lines',
    '/admin/settlement-batches',
    '/admin/support-tickets',
    '/admin/reports',
    '/admin/content/app-store-links',
    '/admin/content/articles',
    '/admin/content/cms-pages',
    '/admin/content/cms-sections',
    '/admin/content/contact-details',
    '/admin/content/faqs',
    '/admin/content/partner-logos',
    '/admin/content/redirects',
    '/admin/content/seo-metadata',
    '/admin/content/testimonials',
    '/admin/audit-events',
    '/admin/integration-health',
    '/admin/system-settings',
    '/admin/security-overview',
    '/admin/memberships',
  ];

  for (const route of routes) {
    await expectHealthyAdminPage(page, route);
  }
});

test('administrator create routes render and reject empty submissions', async ({ page }) => {
  await login(page, 'superadmin@demo.vsta.local');

  for (const route of [
    '/admin/organizations/create',
    '/admin/sites/create',
    '/admin/charging-stations/create',
  ]) {
    await expectHealthyAdminPage(page, route);
    const create = page.getByRole('button', { name: 'Create', exact: true });
    await expect(create).toHaveCount(1);
    await create.click();
    expect(await page.locator('form :invalid').count()).toBeGreaterThan(0);
  }
});

test('read-only administrator cannot reach mutation routes', async ({ page }) => {
  await login(page, 'auditor@demo.vsta.local');

  for (const route of [
    '/admin/organizations/create',
    '/admin/sites/create',
    '/admin/charging-stations/create',
  ]) {
    const response = await page.goto(route);
    expect([403, 404]).toContain(response?.status());
  }
});
