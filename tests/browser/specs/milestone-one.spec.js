import { test, expect } from '@playwright/test';

const password = 'VstaDemo!2026';

async function login(page, panel, email) {
  await page.goto(`/${panel}/login`);
  await page.getByLabel('Email address').fill(email);
  await page.locator('input[type="password"]').fill(password);
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).toHaveURL(new RegExp(`/${panel}/?$`), { timeout: 25_000 });
  await page.waitForLoadState('networkidle');
}

async function enableFlutterSemantics(page) {
  const semantics = page.locator('flt-semantics').first();
  if (await semantics.isVisible({ timeout: 1_000 }).catch(() => false)) {
    return;
  }

  const placeholder = page.locator('flt-semantics-placeholder');
  await placeholder.waitFor({ state: 'attached', timeout: 30_000 });

  // Flutter positions this bootstrap control outside the visual viewport.
  // DOM activation mirrors the accessibility request without pointer geometry.
  await placeholder.evaluate((element) => element.click());
  await semantics.waitFor({ state: 'visible', timeout: 30_000 });
}

test('public home, health, locator markers, filters, list fallback, and detail work', async ({ page, request }) => {
  await page.goto('/');
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

  const health = await request.get('/api/health');
  expect(health.ok()).toBeTruthy();
  const stations = await request.get('/api/v1/public/stations?limit=250');
  expect((await stations.json()).data.length).toBeGreaterThanOrEqual(30);

  await page.goto('/charging-map');
  await expect(page.locator('[data-station-list] .station-card').first()).toBeVisible();
  await expect(page.locator('[data-station-list] .station-card')).toHaveCount(30);
  await expect(page.locator('.leaflet-marker-icon').first()).toBeVisible();
  await Promise.all([
    page.waitForResponse((response) => response.url().includes('/api/v1/public/stations') && response.url().includes('current=AC')),
    page.locator('select[name="current"]').selectOption('AC'),
  ]);
  await expect.poll(() => page.locator('[data-station-list] .station-card').count()).toBeLessThan(30);
  const filteredCount = await page.locator('[data-station-list] .station-card').count();
  expect(filteredCount).toBeGreaterThan(0);
  await page.locator('[data-station-list] .station-card').first().click();
  await expect(page.locator('[data-station-drawer]')).toBeVisible();
  const detailLink = page.getByRole('link', { name: 'Station details' });
  await expect(detailLink).toBeVisible();
  await detailLink.click();
  await expect(page.getByText('Not a real operational EV charger')).toBeVisible();
  await expect(page.locator('[data-station-detail-map] .leaflet-marker-icon')).toBeVisible();
});

test('platform administrator can sign in and use Livewire tables', async ({ page }) => {
  await login(page, 'admin', 'superadmin@demo.vsta.local');
  await expect(page.getByText(/dashboard/i).first()).toBeVisible();
  await page.goto('/admin/sites');
  const search = page.getByRole('searchbox', { name: 'Search', exact: true });
  await search.fill('Bayside');
  await expect(page.getByText(/Bayside Exchange/)).toBeVisible();
  await expect(page.getByText(/Northern Gateway/)).toHaveCount(0);
});

test('operator administrator is isolated to its operator sites', async ({ page }) => {
  await login(page, 'operator', 'operator.admin@demo.vsta.local');
  await page.goto('/operator/sites');
  await expect(page.getByText(/Bayside Exchange/)).toBeVisible();
  await expect(page.getByText(/Northern Gateway/)).toHaveCount(0);
});

test('map providers, tenant-scoped network maps, fallback, and coordinate picker work', async ({ page, browser }) => {
  test.setTimeout(180_000);
  await login(page, 'admin', 'superadmin@demo.vsta.local');

  await page.goto('/admin/network-map');
  await expect(page.locator('[data-portal-network-map]')).toHaveAttribute(
    'data-map-config',
    /"provider":"openstreetmap"/,
  );
  await expect(page.locator('.leaflet-marker-icon').first()).toBeVisible();
  await expect(page.locator('[data-map-list] button')).toHaveCount(15);

  await page.goto('/admin/sites/create');
  const picker = page.locator('[data-location-picker]');
  await expect(picker.locator('.leaflet-container')).toBeVisible();
  const latitude = page.getByLabel('Latitude', { exact: true });
  const longitude = page.getByLabel('Longitude', { exact: true });
  const beforeLatitude = await latitude.inputValue();
  const bounds = await picker.locator('[data-map-canvas]').boundingBox();
  expect(bounds).not.toBeNull();
  await picker.locator('[data-map-canvas]').click({
    position: { x: Math.round(bounds.width * 0.62), y: Math.round(bounds.height * 0.42) },
  });
  await expect.poll(() => latitude.inputValue()).not.toBe(beforeLatitude);
  await expect(longitude).not.toHaveValue('');
  await expect(picker.locator('[data-location-readout]')).toContainText(',');

  await page.goto('/admin/system-settings');
  const adminProvider = page.getByRole('combobox', { name: 'Platform admin', exact: true });
  await adminProvider.selectOption('google');
  await page.getByRole('button', { name: 'Save map settings' }).click();
  await expect(page.getByText('Map settings saved')).toBeVisible();

  try {
    await page.goto('/admin/network-map');
    await expect(page.locator('[data-provider-warning]')).toBeVisible();
    await expect(page.locator('[data-portal-network-map]')).toHaveAttribute(
      'data-map-config',
      /"provider":"openstreetmap".*"fallbackActive":true/,
    );
    await expect(page.locator('.leaflet-marker-icon').first()).toBeVisible();
  } finally {
    await page.goto('/admin/system-settings');
    await page.getByRole('combobox', { name: 'Platform admin', exact: true }).selectOption('openstreetmap');
    await page.getByRole('button', { name: 'Save map settings' }).click();
    await expect(page.getByText('Map settings saved')).toBeVisible();
  }

  const operator = await browser.newPage();
  await login(operator, 'operator', 'operator.admin@demo.vsta.local');
  await operator.goto('/operator/network-map');
  await expect(operator.locator('.leaflet-marker-icon').first()).toBeVisible();
  const operatorSiteCount = await operator.locator('[data-map-list] button').count();
  expect(operatorSiteCount).toBeGreaterThan(0);
  expect(operatorSiteCount).toBeLessThan(15);
  await expect(operator.locator('[data-map-list]')).toContainText('Bayside Exchange');
  await expect(operator.locator('[data-map-list]')).not.toContainText('Northern Gateway');
  await operator.close();
});

test('inventory manager opens physical counts', async ({ page }) => {
  await login(page, 'operator', 'inventory.manager@demo.vsta.local');
  await page.goto('/operator/count-plans');
  await expect(page.getByRole('heading', { name: /count/i }).first()).toBeVisible();
  await expect(page.getByText('DEMO-COUNT-0001')).toBeVisible();
});

test('maintenance manager opens the seeded work-order queue', async ({ page }) => {
  await login(page, 'operator', 'maintenance.manager@demo.vsta.local');
  await page.goto('/operator/work-orders');
  await expect(page.getByText('DEMO-WO-0001')).toBeVisible();
});

test('technician opens assigned work responsively', async ({ browser }) => {
  const technician = await browser.newPage({ viewport: { width: 390, height: 844 } });
  await login(technician, 'operator', 'technician@demo.vsta.local');
  await technician.goto('/operator/technician-workboard');
  await expect(technician.getByText(/Inspect charger cooling path/i)).toBeVisible();
  await technician.close();
});

test('support agent can open the tenant-scoped support queue', async ({ page }) => {
  await login(page, 'operator', 'support@demo.vsta.local');
  await page.goto('/operator/support-tickets');
  await expect(page.getByText('DEMO-SUP-0001')).toBeVisible();
  await expect(page.getByText('Station information question (Demo)')).toBeVisible();
});

test('consumer can sign in to Flutter Web and browse seeded stations', async ({ page }) => {
  test.setTimeout(90_000);
  await page.goto('http://localhost:3000');
  await expect(page).toHaveTitle(/^VTSA(?: CSMS Consumer Demo)?$/);
  await enableFlutterSemantics(page);
  await expect(page.locator('flt-semantics').first()).toBeVisible();
  const skip = page.getByRole('button', { name: 'Skip', exact: true });
  if (await skip.isVisible()) {
    await skip.click();
  }
  await page.getByRole('tab', { name: 'Account', exact: true }).click();
  await page.getByRole('button', { name: 'Sign in', exact: true }).first().click();
  await page.getByLabel('Email address').fill('driver@demo.vsta.local');
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).first().click();
  await expect(page.getByRole('button', { name: /driver@demo\.vsta\.local/ }).first()).toBeVisible();
  await page.getByRole('tab', { name: 'Explore', exact: true }).click();
  await page.getByRole('button', { name: 'List', exact: true }).click();
  await expect(page.getByRole('button', { name: /Riverwalk Hub/ }).first()).toBeVisible();
});

test('Milestone 2 charger and payment features are disabled cleanly', async ({ page }) => {
  await page.goto('/milestone-2/remote-charging');
  await expect(page.getByText('This feature will be available in Milestone 2.')).toBeVisible();
  await expect(page.getByText('No charger, payment, or external-provider request was made.')).toBeVisible();
  await page.goto('/milestone-2/real-payments');
  await expect(page.getByText('This feature will be available in Milestone 2.')).toBeVisible();
  await expect(page.getByText('No charger, payment, or external-provider request was made.')).toBeVisible();
});
