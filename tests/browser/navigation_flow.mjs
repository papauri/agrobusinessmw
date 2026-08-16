/**
 * Dashboard routing, nav drawer targets and browser history.
 *
 *   node tests/browser/navigation_flow.mjs
 *
 * Guards the single-navigation-system rule: openService routes to the standalone
 * pages itself. Three separate hook scripts used to monkey-patch it, so where a
 * tile went depended on script load order.
 */
import { chromium } from 'playwright';
const base = 'http://127.0.0.1:8080';
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || '/opt/pw-browsers/chromium' });
const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
const page = await ctx.newPage();
await page.route('**/*', r => r.request().url().startsWith(base) ? r.continue() : r.abort());
const assert = (c, m) => { console.log(`   ${c?'PASS':'FAIL'}  ${m}`); if(!c) process.exitCode = 1; };

// Dashboard tiles must reach the standalone pages, with no monkey-patch involved.
await page.goto(base + '/index.php', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2000);
// Language gate: pick English if the picker is showing.
const langBtn = page.locator('[data-lang="en"]').first();
if (await langBtn.count() && await langBtn.isVisible()) { await langBtn.click(); await page.waitForTimeout(1200); }

console.log('1. Dashboard service routing (openService is the only navigation system)');
for (const [service, expected] of [['sellers','sellers.php'],['buyers','buyers.php'],['market-insights','market-insights.php'],['register','register.php']]) {
  await page.goto(base + '/index.php', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  await page.evaluate(s => window.app.openService(s), service);
  await page.waitForTimeout(1500);
  assert(page.url().includes(expected), `openService('${service}') → ${page.url().split('/').pop() || '(none)'}`);
}

console.log('2. Nav drawer links');
await page.goto(base + '/index.php', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1500);
const links = await page.locator('#app-nav .app-nav-link').count();
assert(links >= 12, `nav drawer has ${links} links`);
const hrefs = await page.locator('#app-nav .app-nav-link').evaluateAll(els => els.map(e => e.getAttribute('href')));
console.log('3. Every nav target resolves (no 404)');
for (const h of hrefs) {
  const res = await page.request.get(base + '/' + h);
  assert(res.status() === 200, `${h} → ${res.status()}`);
}
console.log('4. Browser Back/Forward across pages');
await page.goto(base + '/index.php', { waitUntil: 'domcontentloaded' }); await page.waitForTimeout(1200);
await page.goto(base + '/sellers.php', { waitUntil: 'domcontentloaded' }); await page.waitForTimeout(1800);
await page.goBack(); await page.waitForTimeout(1200);
assert(page.url().endsWith('index.php'), 'back returned to index.php');
await page.goForward(); await page.waitForTimeout(1800);
assert(page.url().includes('sellers.php'), 'forward returned to sellers.php');
assert((await page.locator('.directory-card').count()) > 0, 'directory re-rendered after forward');
await browser.close();
console.log(process.exitCode ? '\nNAV FAILURES' : '\nnavigation — all assertions passed');
