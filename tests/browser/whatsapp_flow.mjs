/**
 * WhatsApp contact wiring, end to end in a real browser.
 *
 *   node tests/browser/whatsapp_flow.mjs
 *
 * seller_contact_details and buyer_contact_details have always carried a
 * whatsapp_number column and nothing read it, so every WhatsApp button pointed
 * at the contact's phone number whether or not that number was on WhatsApp.
 * This guards the three links in the chain:
 *
 *   admin/index.php  copies whatsapp_number from the application on approval
 *   api.php          returns it on the sellers/buyers actions
 *   directory-navigation.js  prefers it, falling back to phone_number
 *
 * FIXTURE: expects seller_contact_details id 1 to have whatsapp_number
 * +265991000001 and id 2 to have +265991000002, against the repo seed data.
 * See tests/README.md.
 */

import { chromium } from 'playwright';
const base = 'http://127.0.0.1:8080';
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || '/opt/pw-browsers/chromium' });
const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
const page = await ctx.newPage();
await page.route('**/*', r => r.request().url().startsWith(base) ? r.continue() : r.abort());
const errors = [];
page.on('pageerror', e => errors.push(e.message));
page.on('console', m => { const t=m.text(); if (m.type()==='error' && !t.includes('net::ERR') && !t.startsWith('Failed to load resource')) errors.push(t); });
const assert=(c,m)=>{console.log(`   ${c?'PASS':'FAIL'}  ${m}`); if(!c) process.exitCode=1;};

await page.goto(base + '/sellers.php', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2200);

async function openByName(name) {
  await page.fill('#directory-search', name);
  await page.waitForTimeout(400);
  await page.locator('.directory-card').first().click();
  await page.waitForTimeout(600);
}

console.log('1. Contact WITH a dedicated whatsapp_number (+265991000001)');
await openByName('Chimwemwe');
let href = await page.locator('#directory-detail-body a[href^="https://wa.me/"]').first().getAttribute('href');
let body = await page.locator('#directory-detail-body').innerText();
assert(href === 'https://wa.me/265991000001', `WhatsApp link uses the dedicated number: ${href}`);
assert(body.includes('265991000001'), 'dedicated WhatsApp number shown as text');
const tel = await page.locator('#directory-detail-body a[href^="tel:"]').first().getAttribute('href');
assert(tel.includes('881'), `Call link still uses the phone number: ${tel}`);
await page.keyboard.press('Escape'); await page.waitForTimeout(500);

console.log('2. Contact WITHOUT one — falls back to the phone number');
await page.fill('#directory-search', '');
await page.waitForTimeout(300);
await openByName('Chisomo');
href = await page.locator('#directory-detail-body a[href^="https://wa.me/"]').first().getAttribute('href');
body = await page.locator('#directory-detail-body').innerText();
assert(href === 'https://wa.me/265889012345', `falls back to phone: ${href}`);
assert(!/WHATSAPP\s*\n\s*\+/i.test(body), 'no duplicate WhatsApp row when it equals the phone');
await page.keyboard.press('Escape'); await page.waitForTimeout(500);

console.log('3. Searching by WhatsApp number finds the contact');
await page.fill('#directory-search', '991000002');
await page.waitForTimeout(500);
const n = await page.locator('.directory-card').count();
assert(n === 1, `search on a WhatsApp-only number matched ${n} card(s)`);

console.log('4. No console errors');
assert(errors.length === 0, errors.length ? errors.join(' | ') : 'none');
await browser.close();
console.log(process.exitCode ? '\nFAILURES' : '\nwhatsapp wiring — all assertions passed');
