/**
 * Chichewa across registration and the directory, driven in a real browser.
 *
 *   node tests/browser/language_flow.mjs
 *
 * Needs a server on 127.0.0.1:8080 with a database behind it (tests/README.md).
 *
 * What this guards:
 *  - The language chosen on the dashboard is the language the standalone pages
 *    come up in (they read the same `preferredLanguage` key).
 *  - Registration is fully Chichewa: labels, hints, client validation, and the
 *    server's own validation messages.
 *  - Switching language mid-form re-labels in place and does NOT lose input.
 *  - The directory follows the same setting.
 */

import { chromium } from 'playwright';

const base = 'http://127.0.0.1:8080';
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || '/opt/pw-browsers/chromium' });
const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
const page = await ctx.newPage();
await page.route('**/*', r => r.request().url().startsWith(base) ? r.continue() : r.abort());

const errors = [];
page.on('pageerror', e => errors.push('PAGEERROR: ' + e.message));
page.on('console', m => {
  const t = m.text();
  if (m.type() === 'error' && !t.includes('net::ERR') && !t.startsWith('Failed to load resource')) errors.push(t);
});

const rnd = () => String(Math.floor(Math.random() * 900) + 100);
const PHONE = '0888' + rnd() + rnd();

const step = (n, msg) => console.log(`${n}. ${msg}`);
const assert = (cond, msg) => { console.log(`   ${cond ? 'PASS' : 'FAIL'}  ${msg}`); if (!cond) process.exitCode = 1; };

/* Choose Chichewa the way a reader would: it is persisted under the same key
   app.js writes, so seeding it here is equivalent to using the dashboard switcher.
   Seeded with addInitScript so it is in place before any page script runs.
   The earlier version visited index.php to set it and navigated away
   immediately, which aborted app.js's in-flight connection check and logged a
   console error that step 13 then reported as a failure — a defect in the test,
   not the app. */
await ctx.addInitScript(() => {
  // Seed once. Setting it unconditionally would re-apply Chichewa on every
  // navigation and undo the switch back to English in step 12.
  try {
    if (!localStorage.getItem('preferredLanguage')) localStorage.setItem('preferredLanguage', 'ci');
  } catch (e) { /* blocked storage */ }
});

step(1, 'Registration comes up in Chichewa');
await page.goto(base + '/register.php', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1600);
assert((await page.locator('[data-i18n="heading"]').textContent()).includes("m'gulu la alimi"), 'heading translated');
assert((await page.locator('[data-i18n="roleFarmer"]').textContent()).trim() === 'Mlimi', 'Farmer → Mlimi');
assert((await page.locator('[data-i18n="roleSeller"]').textContent()).trim() === 'Wogulitsa', 'Seller → Wogulitsa');
assert((await page.locator('[data-i18n="roleBuyer"]').textContent()).trim() === 'Wogula', 'Buyer → Wogula');
assert((await page.locator('#register-lang-code').textContent()).trim() === 'CI', 'switcher shows CI');
assert((await page.getAttribute('html', 'lang')) === 'ny', 'html lang="ny" for Chichewa');

step(2, 'Field labels are translated');
await page.locator('.register-role[data-role="farmer"]').click();
await page.waitForTimeout(500);
for (const [key, expected] of [['fieldFullName', 'Dzina lanu lonse'], ['fieldPhone', 'Nambala ya foni'],
                               ['fieldWhatsapp', 'Nambala ya WhatsApp'], ['fieldVillage', 'Mudzi / tauni'],
                               ['fieldDistrict', 'Chigawo']]) {
  const actual = (await page.locator(`[data-i18n="${key}"]`).first().textContent()).trim();
  assert(actual === expected, `${key} → ${actual}`);
}
assert((await page.locator('[data-i18n="optional"]').first().textContent()).includes('sikofunikira'), 'optional marker translated');

step(3, 'Districts and crops still load with the language applied');
assert((await page.locator('#reg-district option').count()) > 20, 'districts loaded');
assert((await page.locator('#reg-crops input[type=checkbox]').count()) > 0, 'crops loaded');
assert((await page.locator('#reg-district option').first().textContent()).includes('Sankhani'), 'district placeholder translated');

step(4, 'Client-side validation speaks Chichewa');
await page.locator('[data-step="2"] [data-next]').click();
await page.waitForTimeout(400);
assert((await page.locator('#error-full_name').textContent()).includes('Lembani dzina'), 'name error in Chichewa');
assert((await page.locator('#error-phone_number').textContent()).includes('yofunika'), 'phone error in Chichewa');

step(5, 'Switching language mid-form keeps what was typed');
await page.fill('#reg-full-name', 'Chimwemwe Nyirenda');
await page.fill('#reg-village', 'Kawale');
await page.locator('#register-lang-toggle').click();
await page.waitForTimeout(500);
assert((await page.inputValue('#reg-full-name')) === 'Chimwemwe Nyirenda', 'typed name survived the switch');
assert((await page.inputValue('#reg-village')) === 'Kawale', 'typed village survived the switch');
assert((await page.locator('[data-i18n="fieldFullName"]').first().textContent()).trim() === 'Full name', 'labels now English');
await page.locator('#register-lang-toggle').click();
await page.waitForTimeout(500);
assert((await page.locator('[data-i18n="fieldFullName"]').first().textContent()).trim() === 'Dzina lanu lonse', 'back to Chichewa');

step(6, 'Server validation answers in Chichewa');
await page.fill('#reg-phone', '712345678');
await page.selectOption('#reg-district', { index: 1 });
await page.locator('[data-step="2"] [data-next]').click();
await page.waitForTimeout(600);
assert((await page.locator('#error-phone_number').textContent()).includes('Lembani nambala ya ku Malawi'), 'phone hint in Chichewa');

step(7, 'A full Chichewa registration succeeds');
await page.fill('#reg-phone', PHONE);
await page.locator('[data-step="2"] [data-next]').click();
await page.waitForTimeout(1500);
assert(await page.locator('[data-step="3"]').isVisible(), 'advanced to crops');
assert((await page.locator('[data-i18n="cropsHeading"]').textContent()).includes('Mumalima'), 'crops heading translated');
await page.locator('#reg-crops input[type=checkbox]').first().check();
await page.locator('[data-step="3"] [data-next]').click();
await page.waitForTimeout(500);
// innerText reflects CSS text-transform, and these labels are uppercased, so
// compare case-insensitively rather than against the source strings.
const review = (await page.locator('#reg-review').innerText()).toLowerCase();
assert(review.includes('udindo') && review.includes('mbewu'), 'review labels translated');
assert(review.includes('mlimi'), 'role shown in Chichewa');
await page.locator('#reg-submit').click();
await page.waitForTimeout(2500);
assert(await page.locator('#register-success').isVisible(), 'success panel shown');
assert((await page.locator('[data-i18n="successHeading"]').textContent()).includes('yatumizidwa'), 'success heading translated');

step(8, 'The duplicate warning is in Chichewa');
await page.goto(base + '/register.php', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1500);
await page.locator('.register-role[data-role="farmer"]').click();
await page.waitForTimeout(400);
await page.fill('#reg-full-name', 'Wina Wake');
await page.fill('#reg-phone', PHONE);
await page.fill('#reg-village', 'Kawale');
await page.selectOption('#reg-district', { index: 1 });
await page.locator('[data-step="2"] [data-next]').click();
await page.waitForTimeout(1600);
const dup = await page.locator('#error-phone_number').textContent();
assert(dup.includes('zalembetsedwa kale'), `duplicate in Chichewa: "${dup.slice(0, 60)}…"`);
assert(dup.includes('nambala ya foni'), 'field label in Chichewa');
assert(!dup.includes('status_'), 'status is translated, not a raw key');

step(9, 'The directory is in Chichewa');
await page.goto(base + '/sellers.php', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2200);
const heroText = await page.locator('.directory-hero').innerText();
assert(heroText.includes('Pezani Ogulitsa'), 'Find Sellers → Pezani Ogulitsa');
assert(heroText.includes('Yambani ndi kufufuza'), 'intro translated');
assert((await page.locator('.directory-district span').first().textContent()).trim() === 'Chigawo', 'District → Chigawo');
assert((await page.locator('#directory-district option').first().textContent()).includes('Zigawo zonse'), 'All districts translated');
assert((await page.locator('#directory-search').getAttribute('placeholder')).includes('Sakani ogulitsa'), 'search placeholder translated');
assert((await page.locator('#directory-count').textContent()).includes('akuoneka'), 'result count translated');

step(10, 'Contact actions are in Chichewa');
await page.locator('.directory-card').first().click();
await page.waitForTimeout(700);
const detail = (await page.locator('#directory-detail-body').innerText()).toLowerCase();
assert(detail.includes('imbani'), 'Call → Imbani');
assert(detail.includes('gawanani'), 'Share → Gawanani');
assert(detail.includes('mbewu') || detail.includes('foni'), 'section labels translated');

step(11, 'Empty state is translated');
await page.keyboard.press('Escape');
await page.waitForTimeout(500);
await page.fill('#directory-search', 'zzzznothing');
await page.waitForTimeout(500);
assert((await page.locator('.directory-empty').innerText()).includes('Palibe amene wapezeka'), 'empty state translated');

step(12, 'Buyers too, and English still works');
await page.goto(base + '/buyers.php', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2000);
assert((await page.locator('.directory-hero').innerText()).includes('Pezani Ogula'), 'Find Buyers → Pezani Ogula');
// The init script seeds 'ci' on every new document, so switch the page in place
// via the same API the UI uses rather than fighting it with another navigation.
await page.evaluate(() => window.AgroLang.set('en'));
await page.waitForTimeout(600);
assert((await page.locator('.directory-hero').innerText()).includes('Find Buyers'), 'directory re-rendered in English in place');
await page.waitForTimeout(2000);
assert((await page.locator('.directory-hero').innerText()).includes('Find Buyers'), 'English restored');
await page.goto(base + '/register.php', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1500);
assert((await page.locator('[data-i18n="roleFarmer"]').textContent()).trim() === 'Farmer', 'registration back in English');

step(13, 'No console errors across the whole flow');
assert(errors.length === 0, `console errors: ${errors.length ? errors.join(' | ') : 'none'}`);

await browser.close();
console.log('\nlanguage flow' + (process.exitCode ? ' FINISHED WITH FAILURES' : ' — all assertions passed'));
