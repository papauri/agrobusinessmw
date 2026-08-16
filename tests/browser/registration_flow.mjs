import { chromium } from 'playwright';

const base = 'http://127.0.0.1:8080';
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
const page = await ctx.newPage();
await page.route('**/*', r => r.request().url().startsWith(base) ? r.continue() : r.abort());

const errors = [];
page.on('pageerror', e => errors.push('PAGEERROR: ' + e.message));
page.on('console', m => { const t = m.text(); if (m.type() === 'error' && !t.includes('net::ERR') && !t.startsWith('Failed to load resource')) errors.push(t); });

// Fresh numbers each run so the suite is repeatable against a database that
// already holds earlier runs' applications.
const rnd = () => String(Math.floor(Math.random() * 900) + 100);
const PHONE = '0888' + rnd() + rnd();
const WA = '0999' + rnd() + rnd();

const step = (n, msg) => console.log(`${n}. ${msg}`);
const assert = (cond, msg) => { console.log(`   ${cond ? 'PASS' : 'FAIL'}  ${msg}`); if (!cond) process.exitCode = 1; };

await page.goto(base + '/register.php', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1500);

step(1, 'Page loads with step 1 (role) visible — district is NOT the first step');
assert(await page.locator('[data-step="1"]').isVisible(), 'step 1 visible');
assert(!(await page.locator('[data-step="2"]').isVisible()), 'step 2 hidden');
assert(await page.locator('.register-role[data-role="farmer"]').isVisible(), 'farmer role button present');

step(2, 'Districts and crops loaded from the API');
const districtOptions = await page.locator('#reg-district option').count();
assert(districtOptions > 20, `district select populated (${districtOptions} options)`);
const cropBoxes = await page.locator('#reg-crops input[type=checkbox]').count();
assert(cropBoxes > 0, `crops loaded (${cropBoxes} checkboxes)`);

step(3, 'Choosing "Seller" advances to step 2 and reveals the business field');
await page.locator('.register-role[data-role="seller"]').click();
await page.waitForTimeout(400);
assert(await page.locator('[data-step="2"]').isVisible(), 'step 2 now visible');
assert(await page.locator('#business-field').isVisible(), 'business field shown for seller');

step(4, 'Client-side validation blocks an empty form');
await page.locator('[data-step="2"] [data-next]').click();
await page.waitForTimeout(300);
assert((await page.locator('#error-full_name').textContent()).length > 0, 'name error shown');
assert((await page.locator('#error-phone_number').textContent()).length > 0, 'phone error shown');
assert(await page.locator('[data-step="2"]').isVisible(), 'still on step 2');

step(5, 'An ambiguous number is rejected rather than guessed');
await page.fill('#reg-full-name', 'Grace Phiri');
await page.fill('#reg-phone', '712345678');
await page.fill('#reg-village', 'Ndirande');
await page.selectOption('#reg-district', { index: 1 });
await page.fill('#reg-business-name', 'Phiri Trading');
await page.locator('[data-step="2"] [data-next]').click();
await page.waitForTimeout(400);
const phoneErr = await page.locator('#error-phone_number').textContent();
assert(phoneErr.includes('country code'), `ambiguous number rejected: "${phoneErr.slice(0, 60)}…"`);

step(6, 'A Malawi local number is accepted and canonicalised in the field');
await page.fill('#reg-phone', PHONE);
await page.fill('#reg-whatsapp', WA);
await page.locator('[data-step="2"] [data-next]').click();
await page.waitForTimeout(1200);
assert((await page.inputValue('#reg-phone')) === '+265' + PHONE.slice(1), `phone canonicalised to ${await page.inputValue('#reg-phone')}`);
assert((await page.inputValue('#reg-whatsapp')) === '+265' + WA.slice(1), `whatsapp canonicalised to ${await page.inputValue('#reg-whatsapp')}`);
assert(await page.locator('[data-step="3"]').isVisible(), 'advanced to crops step');

step(7, 'Crops step requires a selection');
await page.locator('[data-step="3"] [data-next]').click();
await page.waitForTimeout(300);
assert((await page.locator('#error-crops').textContent()).length > 0, 'crop error shown');
await page.locator('#reg-crops input[type=checkbox]').first().check();
await page.locator('#reg-crops input[type=checkbox]').nth(1).check();
await page.locator('[data-step="3"] [data-next]').click();
await page.waitForTimeout(400);
assert(await page.locator('[data-step="4"]').isVisible(), 'advanced to review step');

step(8, 'Review shows the canonical numbers');
const review = await page.locator('#reg-review').innerText();
assert(review.includes('+265' + PHONE.slice(1)), 'review shows canonical phone');
assert(review.includes('+265' + WA.slice(1)), 'review shows canonical whatsapp');
assert(review.includes('Seller'), 'review shows role');

step(9, 'Submit persists and shows a reference');
await page.locator('#reg-submit').click();
await page.waitForTimeout(2500);
const successVisible = await page.locator('#register-success').isVisible();
assert(successVisible, 'success panel visible');
const ref = (await page.locator('#reg-reference').textContent()).trim();
assert(/^AGR-\d{8}-[A-Z0-9]{5}$/.test(ref), `reference generated: ${ref}`);

step(10, 'Duplicate registration is blocked on a second attempt');
await page.goto(base + '/register.php', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1500);
await page.locator('.register-role[data-role="farmer"]').click();
await page.waitForTimeout(400);
await page.fill('#reg-full-name', 'Someone Else');
await page.fill('#reg-phone', PHONE);
await page.fill('#reg-village', 'Ndirande');
await page.selectOption('#reg-district', { index: 1 });
await page.locator('[data-step="2"] [data-next]').click();
await page.waitForTimeout(1500);
const dupErr = await page.locator('#error-phone_number').textContent();
assert(dupErr.includes('already registered'), `preflight caught duplicate: "${dupErr.slice(0, 70)}…"`);

step(11, 'Status page finds the new application');
await page.goto(base + '/status.php', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1800);
await page.locator('.page-intro-actions .btn-primary').click();
await page.waitForTimeout(600);
await page.fill('#status-ref-input', ref);
await page.locator('#status-check-btn').click();
await page.waitForTimeout(1800);
const statusText = await page.locator('#status-result').innerText();
assert(statusText.includes('Grace Phiri'), 'status shows applicant name');
assert(statusText.includes('PENDING'), 'status shows PENDING');
assert(!statusText.includes('Invalid Date'), 'applied date parsed (no Invalid Date)');

step(12, 'No console errors across the whole flow');
assert(errors.length === 0, `console errors: ${errors.length ? errors.join(' | ') : 'none'}`);

await browser.close();
console.log('\nregistration flow finished' + (process.exitCode ? ' WITH FAILURES' : ' — all assertions passed'));
