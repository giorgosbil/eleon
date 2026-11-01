// Replying with the real Quill editor: an empty reply is not sent, the quoted original keeps
// its picture and borderless signature table, an answer typed inside the quote is kept, and
// the new answer goes above the original. Also: the message list widens by dragging its edge.
// Same route-mocking technique as app_details_pane_browser.cjs (a fake server at ecw.test).
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
    const errors = []; const saves=[];
    page.on('pageerror', (e) => errors.push(e.message));

    await page.route('https://ecw.test/**', async (route) => {
      const url = new URL(route.request().url()), rel = url.pathname.replace('/other_things/web/', '');
      if(url.pathname==='/logo.png') return route.fulfill({contentType:'image/png',body:PNG_BYTES});
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
        if(action==='signature_profiles_get') return route.fulfill({json:{profiles:[{id:'work',name:'Work',html:'<p>MY SIGNATURE</p>'}],default_id:'work'}});
        if(action==='compose') return route.fulfill({json:{to:[{email:'alex@example.test'}],subject:'Re: quote',signature_html:'<p>MY SIGNATURE</p>',quoted_html:'<blockquote>ORIGINAL RECEIVED MESSAGE<table style="border:0"><tr><td style="border:0"><p>Signature name</p><img src="https://ecw.test/logo.png"></td></tr></table></blockquote>',in_reply_to_message_id:10}});
        if(action==='body') return route.fulfill({json:thread()});
        if(action==='draft_save'){saves.push(route.request().postDataJSON());return route.fulfill({json:{draft_id:9}});}
        // A file attached while writing, and the same file opened again from its chip.
        if(action==='draft_attach') return route.fulfill({json:{id:'f1',filename:'photo.png',mime_type:'image/png',size:PNG_BYTES.length}});
        if(action==='draft_file'&&url.searchParams.get('file')==='f1') return route.fulfill({status:200,headers:{'Content-Type':'image/png','Content-Disposition':'inline; filename="photo.png"','X-Content-Type-Options':'nosniff'},body:PNG_BYTES});
        if(action==='send') return route.fulfill({json:{sent:true}});
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

    const beforeList=await page.locator('#listPane').boundingBox();
    const listHandle=await page.getByRole('separator',{name:'Resize Messages',exact:true}).boundingBox();
    // 40px: at 1400px wide the reading pane keeps its 440px (paneResizerInit.js READ_MIN), the list can give 40.
    await page.mouse.move(listHandle.x+5,listHandle.y+80);await page.mouse.down();await page.mouse.move(listHandle.x+45,listHandle.y+80);await page.mouse.up();
    assert.ok((await page.locator('#listPane').boundingBox()).width > beforeList.width+30,'list pane expands by dragging its edge');
    await page.locator('#messageList .message-actions button').filter({hasText:/^Reply$/}).first().click();
    await page.locator('#sendBtn').click();
    assert.equal(await page.locator('#composerOverlay').isVisible(),true,'empty reply stays open');
    await page.locator('#composeQuoteDetails summary').click();
    await page.locator('#quoteEditor .quote-editable').waitFor();   // filled when the section opens (its toggle event)
    assert.equal(await page.locator('#quoteEditor img').count(),1);
    await page.waitForFunction(()=>document.querySelector('#quoteEditor img')?.naturalWidth>0);
    assert.equal(await page.locator('#quoteEditor td').evaluate(el=>getComputedStyle(el).borderTopWidth),'0px');
    await page.locator('#quoteEditor .quote-editable').fill('ORIGINAL RECEIVED MESSAGE — INLINE ANSWER');
    await page.locator('#editor .ql-editor').fill('MY NEW ANSWER');
    // An attached file opens again from its chip, in the in-app preview.
    await page.locator('#fileInput').setInputFiles({name:'photo.png',mimeType:'image/png',buffer:PNG_BYTES});
    await page.locator('#composerAttachments a.att-open',{hasText:'photo.png'}).click();
    await page.locator('.file-preview-overlay:not([hidden]) .file-preview-body img:not([hidden])').waitFor({timeout:5000});
    assert.equal(await page.locator('.file-preview-name').innerText(),'photo.png','the attached file opens in the preview');
    await page.keyboard.press('Escape');
    await page.locator('.file-preview-overlay').waitFor({state:'detached'});
    await page.locator('#sendBtn').click();
    await page.waitForFunction(()=>document.getElementById('composerOverlay').hidden);
    assert.ok(saves.at(-1).body_html.includes('MY NEW ANSWER'),JSON.stringify(saves));
    assert.ok(saves.at(-1).body_html.indexOf('MY NEW ANSWER')<saves.at(-1).body_html.indexOf('ORIGINAL RECEIVED'));
    assert.ok(saves.at(-1).body_html.includes('INLINE ANSWER'));
    assert.ok(saves.at(-1).body_html.includes('MY SIGNATURE'));
    assert.equal((saves.at(-1).body_html.match(/ORIGINAL RECEIVED MESSAGE/g)||[]).length,1);
    assert.deepEqual(errors,[]);console.log('PASS actual Quill typing reaches send draft before original quotation');
  } finally { await browser.close(); }
})().catch(e=>{console.error(e);process.exitCode=1;});
