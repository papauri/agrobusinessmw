/**
 * Parity test: assets/js/phone-normalizer.js must agree with config/phone.php
 * on every case in tests/phone_test.php.
 *
 *   node tests/phone_test.mjs
 *
 * The two implementations exist because the browser gives instant feedback and
 * the server is the authority. They are only useful if they never disagree — a
 * divergence means the number shown to the farmer is not the number stored.
 *
 * The case table is parsed out of the PHP test so there is one list, not two.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import vm from 'node:vm';

const here = dirname(fileURLToPath(import.meta.url));

// Load the browser normaliser with just enough DOM for its event wiring.
const sandbox = { window: {}, document: { addEventListener() {} }, console };
sandbox.window.document = sandbox.document;
vm.createContext(sandbox);
vm.runInContext(readFileSync(join(here, '..', 'assets', 'js', 'phone-normalizer.js'), 'utf8'), sandbox);
const normalize = sandbox.window.AgroPhone.normalize;

// Parse ['input', 'expected'] / ['input', null] pairs out of the PHP case table.
const php = readFileSync(join(here, 'phone_test.php'), 'utf8');
const table = php.slice(php.indexOf('$cases = ['), php.indexOf('];', php.indexOf('$cases = [')));
const cases = [...table.matchAll(/\[\s*'((?:[^'\\]|\\.)*)'\s*,\s*(?:'((?:[^'\\]|\\.)*)'|null)\s*\]/g)]
  .map(m => [m[1].replace(/\\'/g, "'").replace(/\\\\/g, '\\'), m[2] === undefined ? null : m[2]]);

if (cases.length < 30) {
  console.error(`phone_test.mjs: only parsed ${cases.length} cases from phone_test.php — the parser is out of date`);
  process.exit(1);
}

let failures = 0;
for (const [input, expected] of cases) {
  const actual = normalize(input);
  if (actual !== expected) {
    failures++;
    console.log(`FAIL  ${JSON.stringify(input).padEnd(24)} got ${JSON.stringify(actual).padEnd(18)} want ${JSON.stringify(expected)}`);
  }
}

// Values PHP never sees but the browser will.
for (const [input, expected] of [[null, null], [undefined, null], [0, null]]) {
  const actual = normalize(input);
  if (actual !== expected) {
    failures++;
    console.log(`FAIL  ${String(input).padEnd(24)} got ${JSON.stringify(actual)} want ${JSON.stringify(expected)}`);
  }
}

if (failures === 0) {
  console.log(`phone_test.mjs: ${cases.length + 3}/${cases.length + 3} cases pass (parity with config/phone.php)`);
  process.exit(0);
}
console.log(`phone_test.mjs: ${failures} case(s) FAILED`);
process.exit(1);
