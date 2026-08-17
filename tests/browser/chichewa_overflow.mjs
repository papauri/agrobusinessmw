/**
 * Chichewa at narrow widths.
 *
 *   node tests/browser/chichewa_overflow.mjs
 *
 * Chichewa strings run noticeably longer than their English equivalents, so a
 * layout that fits at 320px in English can overflow once translated. This
 * loads every page in Chichewa at 320/360/390px and fails on any horizontal
 * overflow.
 */

import { chromium } from 'playwright';
const base = 'http://127.0.0.1:8080';
const pages = ['index.php','register.php','status.php','prices.php','weather.php',
  'market-insights.php','sellers.php','buyers.php','farmers.php','pest-control.php',
  'farming-tips.php','farming-guide.php','basic-info.php'];
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || '/opt/pw-browsers/chromium' });
let bad = 0, checks = 0;
for (const w of [320, 360, 390]) {
  for (const p of pages) {
    const ctx = await browser.newContext({ viewport: { width: w, height: 800 } });
    const page = await ctx.newPage();
    await page.route('**/*', r => r.request().url().startsWith(base) ? r.continue() : r.abort());
    // Seed Chichewa before the page's own scripts run.
    await ctx.addInitScript(() => { try { localStorage.setItem('preferredLanguage', 'ci'); localStorage.setItem('hasSelectedLanguage','true'); } catch(e){} });
    await page.goto(base + '/' + p, { waitUntil: 'domcontentloaded', timeout: 15000 }).catch(()=>{});
    await page.waitForTimeout(1600);
    const m = await page.evaluate(() => {
      let widest = '', mx = 0;
      document.querySelectorAll('*').forEach(el => { const r = el.getBoundingClientRect();
        if (r.right > mx && r.width > 0) { mx = r.right; widest = el.tagName + '.' + String(el.className||'').slice(0,40); } });
      return { ovf: document.documentElement.scrollWidth - document.documentElement.clientWidth, widest, lang: document.documentElement.lang };
    }).catch(()=>({ovf:-1,widest:'',lang:'?'}));
    checks++;
    const ok = m.ovf <= 0;
    if (!ok) { bad++; console.log(`BAD  ${w}px ${p} ovf=${m.ovf} lang=${m.lang} widest=${m.widest}`); }
    await ctx.close();
  }
}
await browser.close();
console.log(`\nChichewa: ${checks} checks, ${bad} overflow problem(s)`);
process.exit(0);
