import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const baseUrl = process.argv[2];
const output = process.argv[3];
const extraOrigins = (process.argv[4] || '').split(',').map(value => value.trim()).filter(Boolean);
const channel = process.argv[5] || 'chrome';
const mode = process.argv[6] || 'ephemeral';

if (!baseUrl || !output || !['http:', 'https:'].includes(new URL(baseUrl).protocol)) {
  throw new Error('Usage: node save-auth-state.mjs <base URL> <output JSON> [allowed origins] [channel] [ephemeral|persistent]');
}
if (!['ephemeral', 'persistent'].includes(mode)) {
  throw new Error('Browser mode must be "ephemeral" or "persistent".');
}

const appOrigin = new URL(baseUrl).origin;
const origins = new Set([appOrigin, ...extraOrigins.map(value => new URL(value).origin)]);
const absoluteOutputPath = path.resolve(process.cwd(), output);
const persistentProfilePath = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '.auth-profile');
const diagnosticsPath = path.resolve(path.dirname(fileURLToPath(import.meta.url)), 'auth-diagnostics.log');
const sensitiveKey = /password|cookie|token|authorization|secret|session|credential/i;

fs.writeFileSync(diagnosticsPath, '', { mode: 0o600 });
function log(message, error = false) {
  const line = `${new Date().toISOString()} ${message}`;
  fs.appendFileSync(diagnosticsPath, `${line}\n`, { mode: 0o600 });
  (error ? console.error : console.log)(message);
}

function redact(value, key = '') {
  if (sensitiveKey.test(key)) return '[REDACTED]';
  if (Array.isArray(value)) return value.map(item => redact(item));
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.entries(value).map(([childKey, childValue]) => [childKey, redact(childValue, childKey)]));
  }
  if (typeof value === 'string') {
    const sanitized = value
      .replace(/(password|token|authorization|cookie|secret|session)\s*[:=]\s*[^\s,;]+/gi, '$1=[REDACTED]')
      .replace(/Bearer\s+[A-Za-z0-9._~+\/-]+/gi, 'Bearer [REDACTED]');
    return sanitized.length > 500 ? `${sanitized.slice(0, 500)}…` : sanitized;
  }
  return value;
}

function safeUrl(rawUrl) {
  const url = new URL(rawUrl);
  url.username = '';
  url.password = '';
  url.search = '';
  url.hash = '';
  return url.toString();
}

function loginRequest(request) {
  const url = new URL(request.url());
  return request.method() === 'POST' && origins.has(url.origin) && /(?:^|\/)(?:api\/)?login\/?$/i.test(url.pathname);
}

async function responseError(response) {
  const type = response.headers()['content-type'] || '';
  if (!type.includes('json')) return null;
  const body = await response.json().catch(() => null);
  if (!body || typeof body !== 'object') return null;
  if (response.ok() && body.status !== false && body.success !== false) return null;
  const safe = redact(body);
  return safe.message ?? safe.error ?? safe.errors ?? null;
}

log(`[auth] browser channel: ${channel}`);
log(`[auth] profile mode: ${mode}`);
log(`[auth] state output: ${absoluteOutputPath}`);

let browser;
let context;
try {
  if (mode === 'persistent') {
    fs.mkdirSync(persistentProfilePath, { recursive: true, mode: 0o700 });
    context = await chromium.launchPersistentContext(persistentProfilePath, {
      headless: false,
      channel,
    });
    log(`[auth] isolated persistent profile: ${persistentProfilePath}`);
  } else {
    browser = await chromium.launch({ headless: false, channel });
    context = await browser.newContext();
    log('[auth] isolated profile: temporary fresh Chrome profile');
  }

  await context.clearCookies();
  await context.route('**/*', route => {
    const url = new URL(route.request().url());
    if (['data:', 'blob:'].includes(url.protocol) || origins.has(url.origin)) {
      return route.continue();
    }
    log(`[blocked request] type=${route.request().resourceType()} url=${safeUrl(url.toString())}`);
    return route.abort('blockedbyclient');
  });

  const pages = context.pages();
  const page = pages[0] ?? await context.newPage();
  const cdp = await context.newCDPSession(page);
  await cdp.send('Storage.clearDataForOrigin', { origin: appOrigin, storageTypes: 'all' });

  page.on('console', message => {
    if (message.type() === 'error') log(`[console error] ${redact(message.text())}`, true);
  });
  page.on('pageerror', error => log(`[page error] ${redact(error.message)}`, true));
  page.on('framenavigated', frame => {
    if (frame === page.mainFrame()) log(`[redirect] ${safeUrl(frame.url())}`);
  });
  page.on('request', request => {
    if (loginRequest(request)) log(`[login request] status=sent method=${request.method()} url=${safeUrl(request.url())}`);
  });
  page.on('response', async response => {
    if (!loginRequest(response.request())) return;
    log(`[login response] status=${response.status()} url=${safeUrl(response.url())}`);
    const error = await responseError(response);
    if (error) log(`[login response error] ${JSON.stringify(error)}`);
  });

  const loginUrl = new URL('/login', baseUrl).toString();
  await page.goto(loginUrl, { waitUntil: 'domcontentloaded' });
  log(`[auth] current URL: ${safeUrl(page.url())}`);
  log('[auth] Sign in manually. State will be saved only after the authenticated admin dashboard is detected.');

  const deadline = Date.now() + 10 * 60 * 1000;
  let authenticated = false;
  while (Date.now() < deadline) {
    const current = new URL(page.url());
    const invalidVisible = await page.getByText(/invalid credentials|incorrect (?:email|password)|login failed/i).first().isVisible().catch(() => false);
    const adminElement = await page.getByText(/AI Manager|Admin control panel|Data plans/i).first().isVisible().catch(() => false);
    const adminRoute = current.origin === appOrigin && current.pathname.startsWith('/admin');
    const dashboardDetected = current.origin === appOrigin && current.pathname !== '/login' && adminElement;
    if ((adminRoute || dashboardDetected) && !invalidVisible) {
      authenticated = true;
      break;
    }
    await page.waitForTimeout(500);
  }

  if (!authenticated) {
    throw new Error(`Authentication was not confirmed. Current URL: ${safeUrl(page.url())}. State was not saved.`);
  }

  const invalidVisible = await page.getByText(/invalid credentials|incorrect (?:email|password)|login failed/i).first().isVisible().catch(() => false);
  if (new URL(page.url()).pathname === '/login' || invalidVisible) {
    throw new Error('Login is not complete or an invalid-credentials message is visible. State was not saved.');
  }

  fs.mkdirSync(path.dirname(absoluteOutputPath), { recursive: true, mode: 0o750 });
  await context.storageState({ path: absoluteOutputPath });
  log(`[auth] authenticated admin URL: ${safeUrl(page.url())}`);
  log(`[auth] saved state: ${absoluteOutputPath}`);
  log('[auth] success: authenticated=yes');
} finally {
  if (context) await context.close();
  else if (browser) await browser.close();
}
