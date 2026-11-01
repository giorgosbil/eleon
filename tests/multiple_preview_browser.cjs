// Task 04 — in-app preview for image/PDF attachments.
//
// Same route-mocking technique as app_details_pane_browser.cjs, extended
// with a fake `action=attachment` endpoint that behaves like the real one:
// ?inline=1 only ever returns Content-Disposition: inline for the four
// attachment ids this fixture marks as genuinely previewable, and plain
// (no ?inline=1) requests always come back as a download — exactly what
// the real server in web/api/index.php now does after finfo-checking the
// bytes, which this test can't re-exercise (that part is
// attachment_preview_test.php's job); this file only has to prove the
// client reacts correctly to those two response shapes.
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const { chromium } = require('./support/playwright.cjs');
const web = path.resolve(__dirname, '../web');

// A tiny but real PNG (1x1) and a minimal PDF — enough for the browser's
// native <img>/PDF viewer to actually decode something, not just bytes.
const PNG_BYTES = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');
const PDF_BYTES = Buffer.from(
  '%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n' +
  '3 0 obj<</Type/Page/Parent 2 0 R/Resources<<>>/MediaBox[0 0 200 200]>>endobj\n' +
  'xref\n0 4\ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n0\n%%EOF', 'utf8'
);
const HTML_DISGUISED_AS_PDF = Buffer.from('<html><body>not really a pdf</body></html>', 'utf8');

// ids: 201 quote.pdf (previewable), 202 photo.png (previewable),
// 203 invoice.pdf (claims application/pdf but the "server" refuses inline —
// stands in for the disguised-HTML case), 204 specs.docx (never previewable).
const ATTACHMENTS = {
  201: { bytes: PDF_BYTES, mime: 'application/pdf', filename: 'quote.pdf', allowInline: true },
  202: { bytes: PNG_BYTES, mime: 'image/png', filename: 'photo.png', allowInline: true },
  203: { bytes: HTML_DISGUISED_AS_PDF, mime: 'application/pdf', filename: 'invoice.pdf', allowInline: false },
  204: { bytes: Buffer.from('not a real docx'), mime: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', filename: 'specs.docx', allowInline: false },
};

function thread() {
  return {
    id: 1, subject: 'Quote attached',
    messages: [
      {
        id: 10, subject: 'Quote attached', from: { name: 'Alex Brown', email: 'alex@example.test' },
        to: [{ name: null, email: 'ops@example.test' }], cc: [], direction: 'incoming', is_read: true,
        occurred_at: '2026-09-19 12:00:00', folder: 'Inbox', web_link: 'https://outlook.office.com/mail/1', removed: false,
        body_state: 'fetched', needs_inline_images: false,
        body_html: '<p>Quote and image attached.</p>', signature_html: '', quoted_html: '', has_quoted: false, has_attachments: true,
        attachments: [
          { id: 201, filename: 'quote.pdf', mime_type: 'application/pdf', size: PDF_BYTES.length, state: 'downloaded' },
          { id: 202, filename: 'photo.png', mime_type: 'image/png', size: PNG_BYTES.length, state: 'downloaded' },
        ],
      },
      {
        id: 11, subject: 'Quote attached', from: { name: 'Alex Brown', email: 'alex@example.test' },
        to: [{ name: null, email: 'ops@example.test' }], cc: [], direction: 'incoming', is_read: true,
        occurred_at: '2026-09-19 12:05:00', folder: 'Inbox', web_link: null, removed: false,
        body_state: 'fetched', needs_inline_images: false,
        body_html: '<p>Also see the invoice and specs.</p>', signature_html: '', quoted_html: '', has_quoted: false, has_attachments: true,
        attachments: [
          { id: 203, filename: 'invoice.pdf', mime_type: 'application/pdf', size: HTML_DISGUISED_AS_PDF.length, state: 'downloaded' },
          { id: 204, filename: 'specs.docx', mime_type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', size: 15, state: 'downloaded' },
        ],
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

    await page.route('https://ecw.test/**', async (route) => {
      const url = new URL(route.request().url()), rel = url.pathname.replace('/other_things/web/', '');
      if (rel === 'api/index.php') {
        const action = url.searchParams.get('action');
        if (action === 'session') return route.fulfill({ json: { user: { id: 1, name: 'Buyer', email: 'buyer@example.test' }, csrf: 'test', preferences: { thread_order: 'asc' } } });
        if (action === 'mailboxes') return route.fulfill({ json: { mailboxes: [{ id: 1, email_address: 'buyer@example.test', sync_status: 'ok', status_message: null }] } });
        if (action === 'folders') return route.fulfill({ json: { folders: [] } });
        if (action === 'threads') return route.fulfill({ json: { threads: [{
          id: 1, subject: 'Quote attached', preview: '', from_name: 'Alex Brown', from_email: 'alex@example.test',
          direction: 'incoming', latest_at: '2026-09-19 12:05:00', message_count: 2,
          unread: false, unread_count: 0, has_attachments: true, case: null,
        }], next_cursor: null } });
        if (action === 'thread') return route.fulfill({ json: thread() });
        if (action === 'mark_read') return route.fulfill({ json: { updated: 0 } });
        if (action === 'attachment') {
          const id = Number(url.searchParams.get('id'));
          const att = ATTACHMENTS[id];
          if (!att) return route.fulfill({ status: 404, json: { error: 'not found' } });
          const inline = url.searchParams.get('inline') === '1' && att.allowInline;
          return route.fulfill({
            status: 200,
            headers: {
              'Content-Type': att.mime,
              'Content-Disposition': (inline ? 'inline' : 'attachment') + '; filename="' + att.filename + '"',
              'X-Content-Type-Options': 'nosniff',
              ...(inline ? { 'Content-Security-Policy': 'sandbox' } : {}),
            },
            body: att.bytes,
          });
        }
        return route.fulfill({ json: {} });
      }
      if (rel === 'app.php') {
        let html = fs.readFileSync(path.join(web, rel), 'utf8')
          .replace(/<\?php[\s\S]*?\?>|<\?=[\s\S]*?\?>/g, '')
          .replace(/<style>body > :not\(#composerOverlay\)[\s\S]*?<\/style>/, '');
        return route.fulfill({ contentType: 'text/html', body: html });
      }
      const file = path.join(web, rel);
      if (!file.startsWith(path.join(web, 'assets') + path.sep) || !fs.existsSync(file)) return route.abort();
      return route.fulfill({ contentType: file.endsWith('.css') ? 'text/css' : 'application/javascript', body: fs.readFileSync(file) });
    });

    await page.goto('https://ecw.test/other_things/web/app.php?thread=1&mailbox=1');
    await page.locator('#messageList a.attachment').first().waitFor();

    await page.locator('#messageList a.attachment', {hasText:'quote.pdf'}).click();
    await page.locator('.file-preview iframe:not([hidden])').waitFor();
    await page.locator('.file-preview-more summary').click();
    await page.getByRole('button',{name:'New viewer',exact:true}).click();
    assert.equal(await page.locator('.file-preview-overlay').count(),2);
    const first=page.locator('.file-preview-overlay').nth(0),second=page.locator('.file-preview-overlay').nth(1);
    await second.getByRole('button',{name:'Next file',exact:true}).click();
    await second.locator('img:not([hidden])').waitFor();
    assert.equal(await first.locator('iframe').isVisible(),true,'first PDF remains open');
    await second.locator('.file-preview-more summary').click();
    await second.getByRole('button',{name:'Tile viewers'}).click();
    const a=await first.boundingBox(),b=await second.boundingBox();assert.ok(a.x+a.width<=b.x);
    await first.getByRole('button',{name:'Close preview',exact:true}).click();
    assert.equal(await page.locator('.file-preview-overlay').count(),1);
    assert.equal(await page.locator('.file-preview img:not([hidden])').isVisible(),true);
    await page.setViewportSize({width:390,height:664});
    const mobile = page.locator('.file-preview-overlay');
    const mobileBox = await mobile.boundingBox();
    assert.equal(mobileBox.x,0); assert.equal(mobileBox.y,0);
    assert.equal(mobileBox.width,390); assert.equal(mobileBox.height,664);
    await mobile.locator('.file-preview-more summary').click();
    assert.equal(await mobile.getByRole('button',{name:'Download',exact:true}).isVisible(),true);
    const menuBox = await mobile.locator('.file-preview-actions').boundingBox();
    assert.ok(menuBox.x>=0 && menuBox.x+menuBox.width<=390, 'mobile menu stays on screen');
    await mobile.getByRole('button',{name:'Expand',exact:true}).click();
    assert.equal(await mobile.isVisible(),true,'mobile expansion preserves viewer');
    await mobile.getByRole('button',{name:'Close preview',exact:true}).click();
    assert.equal(await page.locator('.file-preview-overlay').count(),0);
    assert.deepEqual(errors,[]);console.log('PASS independent PDF/image viewers, tiling and individual close');
  } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
