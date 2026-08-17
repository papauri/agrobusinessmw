import { chromium } from 'playwright';

const base = 'http://127.0.0.1:8080';
const pages = ['index.php','register.php','status.php','prices.php','weather.php',
  'market-insights.php','sellers.php','buyers.php','farmers.php','pest-control.php',
  'farming-tips.php','farming-guide.php','basic-info.php','privacy.php'];
const widths = process.argv[2] ? process.argv[2].split(',').map(Number) : [320, 360, 390, 430, 768, 1280];

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
let bad = 0, checked = 0;

for (const w of widths) {
  for (const p of pages) {
    const ctx = await browser.newContext({ viewport: { width: w, height: 800 } });
    const page = await ctx.newPage();
    // Everything off-origin (Google Fonts) is unreachable from this sandbox.
    await page.route('**/*', r => r.request().url().startsWith(base) ? r.continue() : r.abort());

    const errors = [], failed = [];
    const isNetNoise = (t) => t.includes('net::ERR') || t.startsWith('Failed to load resource');
    page.on('console', m => { const t = m.text(); if (m.type() === 'error' && !isNetNoise(t)) errors.push(t); });
    page.on('pageerror', e => errors.push('PAGEERROR: ' + e.message));
    page.on('requestfailed', r => { if (r.url().startsWith(base)) failed.push(r.url().replace(base, '')); });

    let status = 'ERR';
    try {
      const resp = await page.goto(base + '/' + p, { waitUntil: 'domcontentloaded', timeout: 15000 });
      status = resp.status();
    } catch (e) { errors.push('GOTO: ' + e.message.split('\n')[0]); }
    await page.waitForTimeout(1400);

    const m = await page.evaluate(() => {
      let widest = '', mx = 0;
      document.querySelectorAll('*').forEach(el => {
        const r = el.getBoundingClientRect();
        if (r.right > mx && r.width > 0) { mx = r.right; widest = el.tagName + '.' + String(el.className || '').slice(0, 45); }
      });
      const small = [];
      document.querySelectorAll('button, a, input, select, [role=button]').forEach(el => {
        const r = el.getBoundingClientRect();
        if (r.width > 0 && r.height > 0 && r.height < 44 && el.offsetParent !== null) {
          small.push(el.tagName + '.' + String(el.className || '').slice(0, 28) + '=' + Math.round(r.height));
        }
      });
      return {
        overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        widest: widest + '@' + Math.round(mx),
        small: small.slice(0, 6),
        smallCount: small.length
      };
    }).catch(() => ({ overflow: -1, widest: '', small: [], smallCount: 0 }));

    const ok = status === 200 && errors.length === 0 && failed.length === 0 && m.overflow <= 0;
    checked++; if (!ok) bad++;
    process.stdout.write(`${ok ? 'OK  ' : 'BAD '} ${String(w).padStart(4)}px ${p.padEnd(21)} http=${status} ovf=${m.overflow} err=${errors.length} req=${failed.length} small=${m.smallCount}\n`);
    if (!ok) {
      errors.slice(0, 3).forEach(e => process.stdout.write('       C: ' + e.slice(0, 160) + '\n'));
      failed.slice(0, 3).forEach(e => process.stdout.write('       R: ' + e + '\n'));
      if (m.overflow > 0) process.stdout.write('       widest: ' + m.widest + '\n');
    }
    if (m.smallCount) process.stdout.write('       smallTargets: ' + m.small.join(', ') + '\n');
    await ctx.close();
  }
}

await browser.close();
process.stdout.write(`\n${checked} checks, ${bad} problem(s)\n`);
process.exit(0);
