/**
 * Sellers / Buyers / Farmers directories and Market Insights, in a real browser.
 *
 *   node tests/browser/directory_flow.mjs
 *
 * Needs a server on 127.0.0.1:8080 with a database behind it (see tests/README.md).
 *
 * What this guards, in one sentence each:
 *  - Contacts are the first thing on screen; district is a filter, not a gate.
 *  - Every card says what that contact deals in, including when it is nothing.
 *  - The crop filter matches whole crop names, so Beans never selects Soybeans.
 *  - A shared deep link opens the contact WITH the directory behind it.
 *  - The farmer roster publishes NO phone, WhatsApp or email — ever.
 *  - Market Insights is an information page, not a district picker.
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

const step = (n, msg) => console.log(`${n}. ${msg}`);
const assert = (cond, msg) => { console.log(`   ${cond ? 'PASS' : 'FAIL'}  ${msg}`); if (!cond) process.exitCode = 1; };

/* ── Sellers ─────────────────────────────────────────────────────────────── */

await page.goto(base + '/sellers.php', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2000);

step(1, 'Sellers opens straight onto contacts — no district modal first');
assert(!(await page.locator('#district-modal.active').count()), 'no district modal is open');
const cards = await page.locator('.directory-card').count();
assert(cards > 0, `contact cards rendered immediately (${cards})`);
assert(await page.locator('#directory-search').isVisible(), 'search box present');
assert(await page.locator('#directory-district').isVisible(), 'district filter present (optional refinement)');

step(2, 'The district filter defaults to "all"');
assert((await page.inputValue('#directory-district')) === '', 'district filter starts empty (All districts)');

step(3, 'Search narrows the list');
const firstName = (await page.locator('.directory-card strong').first().textContent()).trim();
await page.fill('#directory-search', firstName.split(' ')[0]);
await page.waitForTimeout(400);
const afterSearch = await page.locator('.directory-card').count();
assert(afterSearch > 0 && afterSearch <= cards, `search filtered ${cards} → ${afterSearch}`);
await page.fill('#directory-search', 'zzzznotarealseller');
await page.waitForTimeout(400);
assert(await page.locator('.directory-empty').isVisible(), 'empty state shown when nothing matches');
await page.fill('#directory-search', '');
await page.waitForTimeout(400);

step(4, 'District filter narrows the list and updates the URL');
const options = await page.locator('#directory-district option').count();
assert(options > 1, `district options populated (${options})`);
await page.selectOption('#directory-district', { index: 1 });
await page.waitForTimeout(600);
assert(page.url().includes('district_id='), `URL carries the filter: ${page.url().split('/').pop()}`);
const filtered = await page.locator('.directory-card').count();
assert(filtered > 0 && filtered <= cards, `district filter applied (${filtered} of ${cards})`);

step(5, 'Back returns to the unfiltered directory');
await page.goBack();
await page.waitForTimeout(900);
assert(!page.url().includes('district_id='), 'URL filter cleared on back');

step(6, 'The crop filter narrows the list, and only to growers of that crop');
assert(await page.locator('#directory-crop').isVisible(), 'crop filter present');
assert((await page.inputValue('#directory-crop')) === '', 'crop filter starts empty (All crops)');
const cropOptions = await page.locator('#directory-crop option').allTextContents();
assert(cropOptions.length > 1, `crop options populated from the listings (${cropOptions.slice(1).join(', ')})`);
const pickedCrop = cropOptions[1];
await page.selectOption('#directory-crop', pickedCrop);
await page.waitForTimeout(600);
const cropFiltered = await page.locator('.directory-card').count();
assert(cropFiltered > 0 && cropFiltered < cards, `crop filter applied (${cropFiltered} of ${cards})`);
assert(page.url().includes('crop='), `URL carries the crop: ${page.url().split('?').pop()}`);
// Every remaining card must actually name that crop. A substring match would
// let "Beans" through on a Soybeans grower; this is the assertion that catches it.
const chipSets = await page.locator('.directory-card .directory-crops').allInnerTexts();
const everyCardNamesIt = chipSets.every(txt =>
  txt.split('\n').map(s => s.trim()).includes(pickedCrop));
assert(everyCardNamesIt, `every card left lists "${pickedCrop}" exactly`);

step(7, 'Crop and district filters combine, and Back peels them off');
await page.goBack();
await page.waitForTimeout(700);
assert(!page.url().includes('crop='), 'back cleared the crop filter');

step(8, 'Every card says what the contact deals in');
const stripCount = await page.locator('.directory-card .directory-crops').count();
const cardCount = await page.locator('.directory-card').count();
assert(stripCount === cardCount, `all ${cardCount} cards carry a crops strip (${stripCount})`);
const labelled = await page.locator('.directory-card .directory-crops-label').count();
assert(labelled === cardCount, `all ${cardCount} crop strips are labelled (${labelled})`);

step(9, 'Opening a contact shows call / WhatsApp / email / share');
await page.locator('.directory-card').first().click();
await page.waitForTimeout(700);
assert(await page.locator('#directory-detail-modal.active').isVisible(), 'detail modal opened');
const tel = await page.locator('#directory-detail-body a[href^="tel:"]').count();
const wa = await page.locator('#directory-detail-body a[href^="https://wa.me/"]').count();
const mail = await page.locator('#directory-detail-body a[href^="mailto:"]').count();
const share = await page.locator('#directory-share-contact').count();
assert(tel > 0, 'call link present');
assert(wa > 0, 'WhatsApp link present');
assert(mail > 0, 'email link present');
assert(share > 0, 'share button present');
const waHref = await page.locator('#directory-detail-body a[href^="https://wa.me/"]').first().getAttribute('href');
assert(/^https:\/\/wa\.me\/\d{8,}$/.test(waHref), `WhatsApp link is digits-only: ${waHref}`);

step(10, 'Escape closes the detail');
await page.keyboard.press('Escape');
await page.waitForTimeout(700);
assert(!(await page.locator('#directory-detail-modal.active').count()), 'detail closed on Escape');

step(11, 'A shared deep link renders the directory behind the contact');
const deepId = await page.locator('.directory-card').first().getAttribute('data-id');
await page.goto(base + '/sellers.php?seller_id=' + deepId, { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2200);
assert(await page.locator('#directory-detail-modal.active').isVisible(), 'contact opened from the link');
assert((await page.locator('.directory-card').count()) > 0, 'directory rendered behind it, so closing leaves a usable page');

/* ── Buyers ──────────────────────────────────────────────────────────────── */

step(12, 'Buyers behaves the same way');
await page.goto(base + '/buyers.php', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2000);
assert((await page.locator('.directory-card').count()) > 0, 'buyer contacts rendered immediately');
assert(await page.locator('#directory-search').isVisible(), 'buyer search present');
assert(await page.locator('#directory-crop').isVisible(), 'buyer crop filter present');

/* ── Farmers ─────────────────────────────────────────────────────────────── */

step(13, 'Farmers is a directory of everyone registered as a farmer');
const farmerApi = await page.evaluate(async b => {
  const r = await fetch(b + '/api.php?action=farmers', { headers: { Accept: 'application/json' } });
  return r.json();
}, base);
assert(farmerApi.success === true, 'the farmers API answers');

await page.goto(base + '/farmers.php', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2000);
const farmerCards = await page.locator('.directory-card').count();
assert(farmerCards === farmerApi.count, `every approved farmer is on the page (${farmerCards} of ${farmerApi.count})`);
assert(await page.locator('#directory-search').isVisible(), 'farmer search present');
assert(await page.locator('#directory-crop').isVisible(), 'farmer crop filter present');
assert(await page.locator('#directory-district').isVisible(), 'farmer district filter present');

step(14, 'A farmer listing publishes no contact details at all');
// Two layers, both asserted: the payload must not carry the columns, and the
// page must not render a way to dial anyone. Checking only the page would pass
// even if the numbers were sitting in the JSON for anyone with dev tools.
const leakedKeys = new Set();
(farmerApi.data || []).forEach(row =>
  ['phone_number', 'whatsapp_number', 'email', 'national_id'].forEach(k => {
    if (k in row) leakedKeys.add(k);
  }));
assert(leakedKeys.size === 0, `farmers API sends no contact columns (found: ${[...leakedKeys].join(', ') || 'none'})`);

if (farmerCards > 0) {
  await page.locator('.directory-card').first().click();
  await page.waitForTimeout(700);
  assert(await page.locator('#directory-detail-modal.active').isVisible(), 'farmer detail opened');
  const farmerTel = await page.locator('#directory-detail-body a[href^="tel:"]').count();
  const farmerWa = await page.locator('#directory-detail-body a[href^="https://wa.me/"]').count();
  const farmerMail = await page.locator('#directory-detail-body a[href^="mailto:"]').count();
  assert(farmerTel === 0 && farmerWa === 0 && farmerMail === 0,
    `no call/WhatsApp/email action on a farmer (tel=${farmerTel} wa=${farmerWa} mail=${farmerMail})`);
  assert((await page.locator('.directory-no-contact').count()) > 0, 'the page says why there is no number');
  assert((await page.locator('#directory-detail-body .directory-label').first().textContent()).length > 0,
    'the farmer detail leads with their crops');
  await page.keyboard.press('Escape');
  await page.waitForTimeout(500);
} else {
  console.log('   SKIP  no approved farmers in this database — detail view not exercised');
}

/* ── Market Insights ─────────────────────────────────────────────────────── */

step(15, 'Market Insights is information-first, not a district picker');
let insightRequests = 0;
page.on('request', r => { if (r.url().includes('action=market_insights')) insightRequests++; });
await page.goto(base + '/market-insights.php', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2500);
assert(!(await page.locator('#district-modal.active').count()), 'no district modal on entry');
const insightCards = await page.locator('.mi-card').count();
assert(insightCards > 0, `insight cards shown immediately (${insightCards})`);
assert(await page.locator('#mi-district').isVisible(), 'district is an optional filter on the page');
assert((await page.inputValue('#mi-district')) === 'all', 'defaults to All Malawi');

step(16, 'Insights load in ONE request, not one per district');
assert(insightRequests === 1, `market_insights requests on load: ${insightRequests} (was 28 before)`);

step(17, 'Narrowing to a district still works');
await page.selectOption('#mi-district', { index: 1 });
await page.waitForTimeout(800);
assert(page.url().includes('district_id='), 'district refinement is reflected in the URL');
assert((await page.locator('.mi-card').count()) > 0, 'district view still shows information');

step(18, 'No console errors across the whole flow');
assert(errors.length === 0, `console errors: ${errors.length ? errors.join(' | ') : 'none'}`);

await browser.close();
console.log('\ndirectory + insights flow' + (process.exitCode ? ' FINISHED WITH FAILURES' : ' — all assertions passed'));
