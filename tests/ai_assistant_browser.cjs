// Real browser assistant actions against synthetic email and Claude responses.
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
    const errors = []; const saves=[]; const requests=[]; let sendCount=0;
    page.on('pageerror', (e) => errors.push(e.message));

    await page.route('https://ecw.test/**', async (route) => {
      const url = new URL(route.request().url()), rel = url.pathname.replace('/other_things/web/', '');
      if(url.pathname==='/logo.png') return route.fulfill({contentType:'image/png',body:PNG_BYTES});
      if (rel === 'api/index.php') {
        const action = url.searchParams.get('action');
        const aiResult={text:'AI suggestion <script>untrusted</script>',context_count:6,sources:[{id:10,label:'M10',thread_id:1,subject:'Quote',date:'2026-09-20'}]};
        if(action==='ai_status') return route.fulfill({json:{configured:true,provider:'anthropic'}});
        // Questions about the mail stream their steps, then the answer (NDJSON, as the real ai_agent).
        if(action==='ai_agent') { requests.push(route.request().postDataJSON()); return route.fulfill({contentType:'application/x-ndjson',body:JSON.stringify({type:'step',id:'s1',state:'done',label:'Searching your emails'})+'\n'+JSON.stringify({type:'done',result:aiResult})+'\n'}); }
        // A reply suggestion is one plain request.
        if(action==='ai_assist') { requests.push(route.request().postDataJSON()); return route.fulfill({json:aiResult}); }
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
        if(action==='send') {sendCount++;return route.fulfill({json:{sent:true}});}
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

    assert.equal(await page.locator('.attachment-marker.fa-paperclip').count(),1);
    await page.locator('#mailAssistantBtn').click();
    await page.locator('.ai-assistant .ai-narrow summary').click();   // the search terms sit under "Narrow the search"
    await page.locator('.ai-assistant input[type="search"]').fill('filename:quote.pdf');
    await page.locator('.ai-assistant textarea').fill('Find the quote');
    await page.locator('.ai-assistant .ai-send').click();
    await page.waitForFunction(()=>document.querySelector('.ai-answer')?.textContent.includes('AI suggestion'));
    assert.equal(requests[0].mailbox_id,1);assert.equal(requests[0].query,'filename:quote.pdf');
    assert.equal(await page.locator('.ai-answer script').count(),0,'provider output renders as text');
    assert.equal(await page.locator('.ai-sources a').count(),1);
    // On a phone the assistant fills the screen.
    await page.setViewportSize({width:390,height:664});
    const rect=await page.locator('.ai-assistant').boundingBox();assert.equal(rect.width,390);assert.equal(rect.height,664);
    await page.setViewportSize({width:1400,height:900});
    await page.getByRole('button',{name:'Close assistant'}).click();

    // In a reply, the AI button turns what was typed (the person's notes) into the full reply.
    await page.locator('#messageList .message-actions button').filter({hasText:/^Reply$/}).first().click();
    await page.locator('#editor .ql-editor').fill('MY NOTES: yes, 450 euro, Friday');
    await page.locator('#replyAssistantBtn').click();
    await page.waitForFunction(()=>document.querySelector('#editor .ql-editor').innerText.includes('AI suggestion'));
    assert.equal(requests[1].message_id,10);assert.equal(requests[1].mode,'reply');
    assert.ok(requests[1].question.includes('MY NOTES: yes, 450 euro, Friday'),'the typed notes are the instructions');
    assert.equal(await page.locator('#editor script').count(),0,'inserted suggestions stay plain text');
    assert.equal(sendCount,0,'assistant never sends email');
    assert.deepEqual(errors,[]);console.log('PASS assistant search, sources, safe output, mobile layout, reply drafted from the typed notes, no automatic send');
  } finally { await browser.close(); }
})().catch(e=>{console.error(e);process.exitCode=1;});
