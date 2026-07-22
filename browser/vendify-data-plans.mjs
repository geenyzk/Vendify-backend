import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const payload = JSON.parse(await new Promise((resolve, reject) => {
  let body = '';
  process.stdin.setEncoding('utf8');
  process.stdin.on('data', chunk => { body += chunk; });
  process.stdin.on('end', () => resolve(body));
  process.stdin.on('error', reject);
}));

const { baseUrl, storageState, artifactDir, command } = payload;
const approvedPath = /^\/admin\/products\/airtime-data(?:\/data-plans\/(?:new|\d+\/edit))?\/?$/;
const origins = new Set((payload.allowedOrigins || [baseUrl]).map(value => new URL(value).origin));
const artifacts = [];
let browser;

function fail(message) { throw new Error(message); }
function routeFor(action, id) {
  if (action === 'create') return '/admin/products/airtime-data/data-plans/new';
  if (action === 'update' || id) return `/admin/products/airtime-data/data-plans/${Number(id)}/edit`;
  return '/admin/products/airtime-data?tab=data-plans';
}
async function screenshot(page, name) {
  const file = path.join(artifactDir, `${name}.png`);
  await page.screenshot({ path: file, fullPage: true });
  artifacts.push(file);
}
function field(page, label) {
  return page.getByText(label, { exact: true }).first().locator('xpath=ancestor::div[1]');
}
async function setInput(page, label, value) {
  if (value === null || value === undefined) return;
  const input = field(page, label).locator('input').first();
  await input.fill(String(value));
}
async function setSelect(page, label, value) {
  if (value === null || value === undefined) return;
  await field(page, label).locator('select').first().selectOption({ label: String(value) });
}
async function toggle(page, label, desired) {
  if (desired === null || desired === undefined) return;
  const area = page.getByText(label, { exact: true }).first().locator('xpath=ancestor::div[contains(@class,"justify-between")][1]');
  const button = area.locator('button').first();
  const pressed = (await button.getAttribute('aria-pressed')) === 'true' || (await button.getAttribute('data-state')) === 'on';
  if (pressed !== Boolean(desired)) await button.click();
}
async function toggleValue(page, label) {
  const area = page.getByText(label, { exact: true }).first().locator('xpath=ancestor::div[contains(@class,"justify-between")][1]');
  return (await area.locator('button').first().getAttribute('aria-pressed')) === 'true';
}
async function rolePrice(page, role) {
  const row = page.getByText(role, { exact: true }).first().locator('xpath=ancestor::div[contains(@class,"items-center")][1]');
  return { value: await row.locator('input').inputValue(), type: await row.locator('select').inputValue() };
}
async function readForm(page) {
  const input = async label => field(page, label).locator('input').first().inputValue().catch(() => null);
  const select = async label => field(page, label).locator('select').first().locator('option:checked').textContent().catch(() => null);
  return {
    network: await select('Network'), type: await select('Type'), amount: await input('Amount'),
    unit: await select('Unit'), validity: await input('Validity'), provider: await select('Primary provider'),
    primary_plan_id: await input('Primary plan ID'), cost_price: await input('Cost price'),
    active: await toggleValue(page, 'Active'),
    route_to_provider: await toggleValue(page, 'Route to a specific provider'),
  };
}
async function waitForForm(page) {
  await page.getByText(/(?:Edit|New) data plan/, { exact: true }).waitFor({ timeout: 30000 });
  await page.getByText('Provider', { exact: true }).waitFor();
}

try {
  fs.mkdirSync(artifactDir, { recursive: true, mode: 0o750 });
  browser = await chromium.launch({ headless: payload.headless !== false, downloadsPath: undefined });
  const context = await browser.newContext({ storageState, acceptDownloads: false, viewport: { width: 1440, height: 1100 } });
  await context.route('**/*', async route => {
    const request = route.request();
    const url = new URL(request.url());
    if (!['http:', 'https:', 'data:', 'blob:'].includes(url.protocol)) return route.abort('blockedbyclient');
    if (['data:', 'blob:'].includes(url.protocol)) return route.continue();
    if (!origins.has(url.origin)) return route.abort('blockedbyclient');
    if (request.resourceType() === 'document' && url.origin === new URL(baseUrl).origin && !approvedPath.test(url.pathname)) {
      return route.abort('blockedbyclient');
    }
    return route.continue();
  });
  const page = await context.newPage();
  page.on('download', download => download.cancel());
  const target = new URL(routeFor(command.action, command.planId ?? command.plan_id), baseUrl).toString();
  await page.goto(target, { waitUntil: 'networkidle' });
  if (new URL(page.url()).pathname === '/login') fail('Vendify admin session has expired. Refresh the configured storage state.');

  if (command.action === 'health') {
    const current = new URL(page.url());
    const authenticated = current.origin === new URL(baseUrl).origin
      && current.pathname.startsWith('/admin/products/airtime-data');
    if (!authenticated) fail(`Browser is not authenticated; ended at ${current.origin}${current.pathname}.`);
    console.log(JSON.stringify({ ok: true, authenticated: true, page_url: page.url(), artifacts }));
    process.exit(0);
  }

  if (command.action === 'inspect' && !command.planId) {
    await screenshot(page, 'data-plans');
    const table = await page.locator('table').first().innerText().catch(() => '');
    console.log(JSON.stringify({ ok: true, mode: 'read_only', visible_table: table.slice(0, 20000), artifacts }));
    process.exit(0);
  }

  await waitForForm(page);
  const before = await readForm(page);
  await screenshot(page, 'before');
  if (command.action === 'inspect') {
    console.log(JSON.stringify({ ok: true, mode: 'read_only', plan_id: command.planId, values: before, artifacts }));
    process.exit(0);
  }

  await setSelect(page, 'Network', command.network);
  await setSelect(page, 'Type', command.type);
  await setInput(page, 'Amount', command.amount);
  await setSelect(page, 'Unit', command.unit);
  await setInput(page, 'Validity', command.validity);
  await toggle(page, 'Active', command.active);
  await toggle(page, 'Route to a specific provider', command.route_to_provider);
  await setSelect(page, 'Primary provider', command.provider);
  await setInput(page, 'Primary plan ID', command.primary_plan_id);
  await setInput(page, 'Cost price', command.cost_price);

  for (const price of command.role_pricing || []) {
    const row = page.getByText(price.role, { exact: true }).first().locator('xpath=ancestor::div[contains(@class,"items-center")][1]');
    await row.locator('input').fill(String(price.value));
    await row.locator('select').selectOption(price.type === 'percentage' ? 'percentage' : 'fiat');
  }
  await screenshot(page, 'preview');

  let savedId = Number(command.plan_id || 0);
  const responsePromise = page.waitForResponse(response => {
    const method = response.request().method();
    return ['POST', 'PUT', 'PATCH'].includes(method) && response.url().includes('data_plans');
  }, { timeout: 30000 }).catch(() => null);
  await page.getByRole('button', { name: command.action === 'create' ? 'Create & return' : 'Save & return', exact: true }).click();
  const response = await responsePromise;
  if (response && !response.ok()) fail(`Save failed with HTTP ${response.status()}: ${(await response.text()).slice(0, 600)}`);
  if (response && command.action === 'create') {
    const json = await response.json().catch(() => ({}));
    savedId = Number(json?.data?.id ?? json?.id ?? json?.data_plan?.id ?? 0);
  }
  if (!savedId) fail('Plan saved but its id could not be determined for post-save verification.');

  await page.goto(new URL(routeFor('update', savedId), baseUrl).toString(), { waitUntil: 'networkidle' });
  await waitForForm(page);
  const after = await readForm(page);
  after.role_pricing = Object.fromEntries(await Promise.all((command.role_pricing || []).map(async price => [price.role, await rolePrice(page, price.role)])));
  await screenshot(page, 'after');
  const expected = {
    network: command.network, type: command.type,
    amount: command.amount === null || command.amount === undefined ? undefined : String(command.amount),
    unit: command.unit, validity: command.validity,
    provider: command.provider,
    primary_plan_id: command.primary_plan_id === null || command.primary_plan_id === undefined ? undefined : String(command.primary_plan_id),
    cost_price: command.cost_price === null || command.cost_price === undefined ? undefined : String(command.cost_price),
    active: command.active, route_to_provider: command.route_to_provider,
  };
  const mismatches = Object.entries(expected).filter(([, value]) => value !== undefined && value !== null)
    .filter(([key, value]) => String(after[key] ?? '').trim() !== String(value).trim())
    .map(([key, value]) => ({ field: key, expected: value, actual: after[key] }));
  for (const price of command.role_pricing || []) {
    const actual = after.role_pricing[price.role];
    if (!actual || actual.type !== price.type || String(actual.value) !== String(price.value)) {
      mismatches.push({ field: `role_pricing.${price.role}`, expected: price, actual });
    }
  }
  if (mismatches.length) fail(`Post-save verification failed: ${JSON.stringify(mismatches)}`);
  console.log(JSON.stringify({ ok: true, saved: true, verified: true, plan_id: savedId, before, after, verification: { mismatches: [] }, artifacts }));
} catch (error) {
  console.error(error instanceof Error ? error.message : String(error));
  process.exitCode = 1;
} finally {
  if (browser) await browser.close();
}
