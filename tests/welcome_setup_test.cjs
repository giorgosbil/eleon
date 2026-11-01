// Exercise the actual offline guide's settings generator without a browser or server.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const html = fs.readFileSync(path.join(root, 'welcome.html'), 'utf8');
const scripts = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)].map(m => m[1]);
scripts.forEach(s => new vm.Script(s));
const js = scripts[0];
const start = js.indexOf('  const DEFAULTS =');
const end = js.indexOf('  // ------------------------------------------------------------ commands per system');
assert(start > 0 && end > start);
const brand = js.match(/const BRAND = (\{[^\n]+\});/)[1];
const loader = js.slice(js.indexOf("$('#envFile').addEventListener"));
const assign = loader.match(/    Object\.assign\(state, \{[\s\S]*?\n    \}\);/)[0];
const context = vm.createContext({ navigator: { platform: 'Win32' }, store: { get: () => null }, URL });
vm.runInContext(`const BRAND=${brand}; const WEB_PATH='/other_things/web/';\n` + js.slice(start, end) + `
globalThis.guide = { state, buildEnv, parseEnv, load(text) {
  const v = parseEnv(text); loadedEnv = { lines: text.trimEnd().split(/\\r?\\n/), values: v };
  let base = null; try { base = new URL(v.ECW_BASE_URL || ''); } catch (_) {}
  ${assign}
}, reset() { loadedEnv=null; Object.assign(state, DEFAULTS); } };`, context);
const { guide } = context;
let out = guide.parseEnv(guide.buildEnv());
assert.equal(out.ECW_PRODUCT_BRAND, 'eleon');
assert.equal(out.ECW_BIND_ADDRESS, '127.0.0.1');
guide.state.productBrand = 'original';
assert.equal(guide.parseEnv(guide.buildEnv()).ECW_PRODUCT_BRAND, 'original');
for (const profile of [null, 'original', 'eleon']) {
  guide.reset();
  guide.load(`ECW_DB_PASSWORD='private $ value'\nECW_SETUP_TOKEN=test-token\nECW_KEEP_CUSTOM=unchanged\n${profile ? 'ECW_PRODUCT_BRAND='+profile+'\n' : ''}`);
  out = guide.parseEnv(guide.buildEnv());
  assert.equal(out.ECW_PRODUCT_BRAND, profile || 'original');
  assert.equal(out.ECW_DB_PASSWORD, 'private $ value');
  assert.equal(out.ECW_SETUP_TOKEN, 'test-token');
  assert.equal(out.ECW_KEEP_CUSTOM, 'unchanged');
}
guide.reset(); guide.state.mode='server'; guide.state.host='eleon.office.lan';
out = guide.parseEnv(guide.buildEnv());
assert.equal(out.ECW_BASE_URL, 'https://eleon.office.lan:8443');
assert.equal(out.ECW_PRODUCT_BRAND, 'eleon');
for (const match of html.matchAll(/(?:src|href)="((?:web\/assets\/brands\/)[^"]+)"/g)) {
  assert(fs.existsSync(path.join(root, match[1])), 'Missing offline branding asset: '+match[1]);
}
assert(!/product_name|company_name|free trial|paid modules|Pro or Business/.test(html));
console.log('Welcome guide: script syntax, fresh installs, both profiles, legacy imports, secret preservation, server URLs and offline icons verified.');
