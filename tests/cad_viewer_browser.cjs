// Task 05 — CAD viewer inside the file preview overlay.
//
// Same route-mocking technique as file_preview_browser.cjs, extended with
// static-file serving for other_things/cad_viewer/ (outside web/, so it
// needs its own path handling) and four attachments: a real STEP file, a
// real STL file, a corrupt one (proves the parse-error + Download fallback),
// and a fake-oversized one (proves the >50MB confirm gate). Requests to
// jsdelivr (occt-import-js, the STEP importer Online3DViewer lazy-loads
// itself) are intentionally left unmocked so the STEP case exercises the
// real network path production uses — see SUMMARY.md for why that CDN
// dependency was kept rather than vendored.
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const { chromium } = require('./support/playwright.cjs');
const root = path.resolve(__dirname, '..'); // other_things/
const web = path.join(root, 'web');

// A 20 mm cube (tests/fixtures/cube.stp), kept in the project so the test runs on any PC.
const STEP_BYTES = fs.readFileSync(path.join(__dirname, 'fixtures', 'cube.stp'));
const STL_BYTES = Buffer.from(
  'solid test\n' +
  'facet normal 0 0 1\n  outer loop\n    vertex 0 0 0\n    vertex 10 0 0\n    vertex 0 10 0\n  endloop\nendfacet\n' +
  'facet normal 0 0 1\n  outer loop\n    vertex 10 0 0\n    vertex 10 10 0\n    vertex 0 10 0\n  endloop\nendfacet\n' +
  'facet normal 1 0 0\n  outer loop\n    vertex 10 0 0\n    vertex 10 0 10\n    vertex 10 10 0\n  endloop\nendfacet\n' +
  'endsolid test\n', 'utf8'
);
const CORRUPT_BYTES = Buffer.from('this is not a real stl file, just garbage bytes', 'utf8');

// ids: 301 bracket.stp (real STEP), 302 bracket.stl (real STL),
// 303 huge.stp (small bytes, declared size lies >50MB to trigger the guard),
// 304 corrupt.stl (garbage — proves the parse-failure error state).
const ATTACHMENTS = {
  305: {bytes:Buffer.from('%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF'), mime:'application/pdf',filename:'other.pdf'},
  301: { bytes: STEP_BYTES, mime: 'application/octet-stream', filename: 'bracket.stp', declaredSize: STEP_BYTES.length },
  302: { bytes: STL_BYTES, mime: 'application/octet-stream', filename: 'bracket.stl', declaredSize: STL_BYTES.length },
  303: { bytes: STEP_BYTES, mime: 'application/octet-stream', filename: 'huge.stp', declaredSize: 90 * 1024 * 1024 },
  304: { bytes: CORRUPT_BYTES, mime: 'application/octet-stream', filename: 'corrupt.stl', declaredSize: CORRUPT_BYTES.length },
};

function thread() {
  return {
    id: 1, subject: 'Drawings attached',
    messages: [
      {
        id: 10, subject: 'Drawings attached', from: { name: 'Alex Brown', email: 'alex@example.test' },
        to: [{ name: null, email: 'ops@example.test' }], cc: [], direction: 'incoming', is_read: true,
        occurred_at: '2026-09-19 12:00:00', folder: 'Inbox', web_link: null, removed: false,
        body_state: 'fetched', needs_inline_images: false,
        body_html: '<p>CAD files attached.</p>', signature_html: '', quoted_html: '', has_quoted: false, has_attachments: true,
        attachments: Object.keys(ATTACHMENTS).map((id) => ({
          id: Number(id), filename: ATTACHMENTS[id].filename, mime_type: ATTACHMENTS[id].mime,
          size: ATTACHMENTS[id].declaredSize, state: 'downloaded',
        })),
      },
    ],
    case: null,
  };
}

(async () => {
  const browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
  try {
    const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));

    const requestedUrls = [];
    page.on('request', (req) => requestedUrls.push(req.url()));

    await page.route('https://ecw.test/**', async (route) => {
      const url = new URL(route.request().url());
      const pathname = url.pathname;

      if (pathname === '/other_things/web/api/index.php') {
        const action = url.searchParams.get('action');
        if (action === 'session') return route.fulfill({ json: { user: { id: 1, name: 'Buyer', email: 'buyer@example.test' }, csrf: 'test', preferences: { thread_order: 'asc' } } });
        if (action === 'mailboxes') return route.fulfill({ json: { mailboxes: [{ id: 1, email_address: 'buyer@example.test', sync_status: 'ok', status_message: null }] } });
        if (action === 'folders') return route.fulfill({ json: { folders: [] } });
        if (action === 'threads') return route.fulfill({ json: { threads: [{
          id: 1, subject: 'Drawings attached', preview: '', from_name: 'Alex Brown', from_email: 'alex@example.test',
          direction: 'incoming', latest_at: '2026-09-19 12:00:00', message_count: 1,
          unread: false, unread_count: 0, has_attachments: true, case: null,
        }], next_cursor: null } });
        if (action === 'thread') return route.fulfill({ json: thread() });
        if (action === 'mark_read') return route.fulfill({ json: { updated: 0 } });
        if (action === 'attachment') {
          const id = Number(url.searchParams.get('id'));
          const att = ATTACHMENTS[id];
          if (!att) return route.fulfill({ status: 404, json: { error: 'not found' } });
          return route.fulfill({
            status: 200,
            headers: {
              'Content-Type': att.mime,
              'Content-Disposition': (id === 305 ? 'inline' : 'attachment') + '; filename="' + att.filename + '"',
              'Content-Length': String(att.bytes.length),
              'X-Content-Type-Options': 'nosniff',
            },
            body: att.bytes,
          });
        }
        return route.fulfill({ json: {} });
      }

      if (pathname === '/other_things/web/app.php') {
        let html = fs.readFileSync(path.join(web, 'app.php'), 'utf8')
          .replace(/<\?php[\s\S]*?\?>|<\?=[\s\S]*?\?>/g, '')
          .replace(/<style>body > :not\(#composerOverlay\)[\s\S]*?<\/style>/, '');
        return route.fulfill({ contentType: 'text/html', body: html });
      }

      var file = null;
      if (pathname.startsWith('/other_things/web/assets/')) file = path.join(web, pathname.replace('/other_things/web/', ''));
      else if (pathname.startsWith('/other_things/cad_viewer/')) file = path.join(root, pathname.replace('/other_things/', ''));
      if (!file || !fs.existsSync(file)) return route.abort();
      const ext = path.extname(file);
      const contentType = ext === '.css' ? 'text/css' : (ext === '.js' ? 'application/javascript' : 'application/octet-stream');
      return route.fulfill({ contentType, body: fs.readFileSync(file) });
    });

    await page.goto('https://ecw.test/other_things/web/app.php?thread=1&mailbox=1');
    await page.locator('#messageList a.attachment').first().waitFor();

    // ---------------------------------------------------- lazy-load: no 3D libs before any click
    const cadRequestsBeforeClick = requestedUrls.filter((u) => u.indexOf('cad_viewer') !== -1 || u.indexOf('occt-import-js') !== -1);
    assert.deepEqual(cadRequestsBeforeClick, [], 'no CAD viewer or importer request happens on page load');

    let downloadFired = false;
    page.on('download', () => { downloadFired = true; });

    // ---------------------------------------------------------------- STL: fully self-contained, no CDN needed
    await page.locator('#messageList a.attachment', { hasText: 'bracket.stl' }).click();
    await page.locator('.file-preview-overlay:not([hidden])').waitFor();
    assert.equal(await page.locator('.file-preview-name').innerText(), 'bracket.stl', 'header shows the clicked file name');
    await page.locator('.file-preview-cad:not([hidden]) canvas').waitFor({ timeout: 15000 });
    assert.equal(downloadFired, false, 'STL previews in place, no download');
    assert.equal(page.url(), 'https://ecw.test/other_things/web/app.php?thread=1&mailbox=1', 'the SPA never navigates away to serve the file');

    const cadRequestsAfterClick = requestedUrls.filter((u) => u.indexOf('ot-cad-viewer.js') !== -1);
    assert.ok(cadRequestsAfterClick.length > 0, 'CAD viewer module is fetched once a CAD file is actually opened');
    const ovRequests = requestedUrls.filter((u) => u.indexOf('/cad_viewer/third_party/o3dv.min.js') !== -1);
    assert.ok(ovRequests.length > 0, 'vendored O3DV library is fetched from this project\'s own cad_viewer/ copy');

    await page.locator('.file-preview-overlay').evaluate(el=>{el.style.width='1050px';el.style.height='750px';});
    await page.waitForFunction(()=>{const host=document.querySelector('.file-preview-cad'), canvas=host?.querySelector('canvas');return canvas && Math.abs(canvas.clientWidth-host.clientWidth)<2 && Math.abs(canvas.clientHeight-host.clientHeight)<2;});
    assert.equal(await page.locator('.file-preview-cad canvas').evaluate(el=>getComputedStyle(el).opacity),'1');
    await page.locator('.file-preview-overlay').evaluate(el=>{el.style.width='650px';el.style.height='520px';});
    await page.waitForFunction(()=>{const host=document.querySelector('.file-preview-cad'), canvas=host?.querySelector('canvas');return canvas && Math.abs(canvas.clientWidth-host.clientWidth)<2 && Math.abs(canvas.clientHeight-host.clientHeight)<2;});

    // rotate + zoom: a drag and a wheel scroll on the canvas must move the camera.
    // (toDataURL() readback is unreliable here — O3DV's renderer doesn't set
    // preserveDrawingBuffer, so the camera's actual position is the honest signal.)
    const canvas = page.locator('.file-preview-cad canvas');
    const box = await canvas.boundingBox();
    const getCameraPos = () => page.evaluate(() => {
      const v = document.querySelector('.file-preview-cad')._otCadViewer;
      return v.viewer.GetViewer().camera.position.toArray();
    });
    const beforeDrag = await getCameraPos();
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
    await page.mouse.down();
    await page.mouse.move(box.x + box.width / 2 + 120, box.y + box.height / 2 - 60, { steps: 12 });
    await page.mouse.up();
    await page.waitForTimeout(200);
    const afterDrag = await getCameraPos();
    assert.notDeepEqual(beforeDrag, afterDrag, 'orbit-drag on the canvas moves the camera');

    await page.mouse.wheel(0, -300); // zoom in
    await page.waitForTimeout(200);
    const afterWheel = await getCameraPos();
    assert.notDeepEqual(afterDrag, afterWheel, 'wheel-zoom on the canvas moves the camera again');

    await page.keyboard.press('Escape');
    await page.locator('.file-preview-overlay').waitFor({ state: 'detached' });
    assert.equal(await page.evaluate(() => document.querySelectorAll('canvas').length), 0, 'canvas is torn down on close, not leaked');

    // ---------------------------------------------------------------- STEP: real network path (occt-import-js from jsdelivr)
    await page.locator('#messageList a.attachment', { hasText: 'bracket.stp' }).click();
    await page.locator('.file-preview-overlay:not([hidden])').waitFor();
    // occt-import-js (WASM STEP importer) fetch + parse genuinely takes ~30s
    // on this box's single CPU core — measured at 30.76s in isolation, no
    // app bug. Generous timeout, not a correctness workaround.
    await page.locator('.file-preview-cad:not([hidden]) canvas').waitFor({ timeout: 60000 });
    assert.equal(downloadFired, false, 'STEP previews in place too, no download');
    await page.evaluate(()=>window.ecwOpenPreview({url:'api/index.php?action=attachment&id=305',name:'other.pdf',mime:'application/pdf',size:80}));
    await page.locator('.file-preview iframe:not([hidden])').waitFor();
    assert.equal(await page.locator('.file-preview-overlay').count(),2,'STEP and PDF use separate windows');
    assert.equal(await page.locator('.file-preview-cad canvas').count(),1,'opening a PDF leaves the STEP renderer intact');
    await page.locator('.file-preview-overlay').last().getByRole('button',{name:'Close preview',exact:true}).click();
    assert.equal(await page.locator('.file-preview-cad canvas').count(),1,'closing the PDF does not dispose the STEP viewer');

    await page.keyboard.press('Escape');
    await page.locator('.file-preview-overlay').waitFor({ state: 'detached' });

    // ---------------------------------------------------------------- size guard: declared size > 50MB
    await page.locator('#messageList a.attachment', { hasText: 'huge.stp' }).click();
    await page.locator('.file-preview-overlay:not([hidden])').waitFor();
    await page.locator('.file-preview-cad .ot-cad-confirm').waitFor({ timeout: 5000 });
    const confirmText = await page.locator('.file-preview-cad .ot-cad-confirm p').innerText();
    assert.match(confirmText, /90\.0 MB/, 'confirm shows the actual declared file size');

    await page.locator('.file-preview-cad .ot-cad-confirm-actions button', { hasText: 'Cancel' }).click();
    await page.locator('.file-preview-cad .ot-cad-error').waitFor({ timeout: 5000 });
    assert.equal(await page.locator('.file-preview-cad canvas').count(), 0, 'cancelling the size guard never fetches or loads the model');

    await page.keyboard.press('Escape');
    await page.locator('.file-preview-overlay').waitFor({ state: 'detached' });
    await page.locator('#messageList a.attachment', { hasText: 'huge.stp' }).click();
    await page.locator('.file-preview-cad .ot-cad-confirm').waitFor({ timeout: 5000 });
    await page.locator('.file-preview-cad .ot-cad-confirm-actions button', { hasText: 'Load Anyway' }).click();
    await page.locator('.file-preview-cad canvas').waitFor({ timeout: 60000 });
    await page.keyboard.press('Escape');
    await page.locator('.file-preview-overlay').waitFor({ state: 'detached' });

    // ---------------------------------------------------------------- corrupt file: parse failure -> error + Download fallback
    downloadFired = false;
    await page.locator('#messageList a.attachment', { hasText: 'corrupt.stl' }).click();
    await page.locator('.file-preview-overlay:not([hidden])').waitFor();
    await page.locator('.file-preview-cad .ot-cad-error').waitFor({ timeout: 10000 });
    const [fallbackDownload] = await Promise.all([
      page.waitForEvent('download'),
      page.locator('.file-preview-cad .ot-cad-error-actions a', { hasText: 'Download' }).click(),
    ]);
    assert.equal(fallbackDownload.suggestedFilename(), 'corrupt.stl', 'the error state\'s Download fallback serves the original file');
    await page.keyboard.press('Escape');
    await page.locator('.file-preview-overlay').waitFor({ state: 'detached' });

    assert.deepEqual(errors, []);
    console.log('PASS CAD viewer: STEP and STL open in-app with no download, lazy-loaded only on click, rotate/zoom render, size guard and parse-error fallback both work, canvas is disposed on close');
  } finally {
    await browser.close();
  }
})().catch((e) => { console.error(e); process.exitCode = 1; });
