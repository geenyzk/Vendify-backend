import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import readline from 'node:readline/promises';

const baseUrl = process.argv[2];
const output = process.argv[3];
const extraOrigins = (process.argv[4] || '').split(',').map(value => value.trim()).filter(Boolean);
if (!baseUrl || !output || !['http:', 'https:'].includes(new URL(baseUrl).protocol)) {
  throw new Error('Usage: node save-auth-state.mjs <Vendify base URL> <output JSON path>');
}

const origins = new Set([new URL(baseUrl).origin, ...extraOrigins.map(value => new URL(value).origin)]);
const browser = await chromium.launch({ headless: false });
const context = await browser.newContext();
await context.route('**/*', route => {
  const url = new URL(route.request().url());
  return ['data:', 'blob:'].includes(url.protocol) || origins.has(url.origin)
    ? route.continue()
    : route.abort('blockedbyclient');
});
const page = await context.newPage();
await page.goto(new URL('/login', baseUrl).toString());
const prompt = readline.createInterface({ input: process.stdin, output: process.stdout });
await prompt.question('Log in to Vendify in the opened browser, then press Enter here to save the session. ');
if (!new URL(page.url()).pathname.startsWith('/admin')) throw new Error('The browser is not on a Vendify admin page.');
fs.mkdirSync(path.dirname(output), { recursive: true, mode: 0o750 });
await context.storageState({ path: output });
await prompt.close();
await browser.close();
console.log(`Saved encrypted-session material to ${output}. Protect this file like a password.`);
