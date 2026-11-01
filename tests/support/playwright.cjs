/**
 * Playwright for the browser tests (tests/*_browser.cjs), from this project:
 *   npm install --prefix tests/browser        (once; tests/browser/node_modules is not in git)
 * ECW_PLAYWRIGHT can point at another copy of the package. The browser is Playwright's own
 * Chromium when it was installed (npx --prefix tests/browser playwright install chromium),
 * otherwise this PC's Microsoft Edge (or ECW_BROWSER_CHANNEL: chrome, msedge…).
 */
'use strict';
const path = require('node:path');

function loadPlaywright() {
  const tried = [process.env.ECW_PLAYWRIGHT, path.join(__dirname, '..', 'browser', 'node_modules', 'playwright')].filter(Boolean);
  for (const p of tried) {
    try { return require(p); } catch (e) { if (e.code !== 'MODULE_NOT_FOUND') throw e; }
  }
  console.error('SKIP Playwright is not installed. Run once: npm install --prefix tests/browser');
  process.exit(2);
}

const pw = loadPlaywright();
const launch = pw.chromium.launch.bind(pw.chromium);
pw.chromium.launch = async (options = {}) => {
  if (process.env.ECW_BROWSER_CHANNEL) return launch({ ...options, channel: process.env.ECW_BROWSER_CHANNEL });
  try { return await launch(options); }
  catch (e) {
    if (!/Executable doesn't exist|browserType\.launch/.test(String(e && e.message))) throw e;
    return launch({ ...options, channel: 'msedge' });   // no bundled Chromium: the installed Edge
  }
};
module.exports = pw;
