// Task 03 — right-hand thread details panel, reading pane (app.php) variant:
// hidden with no thread open, reveals and fills in once one is, updates
// when switching threads.
//
// Same technique as appearance_layout_browser.cjs / case_details_pane_browser.cjs.
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const { chromium } = require('./support/playwright.cjs');
const web = path.resolve(__dirname, '../web');

function thread(id, subject) {
  return {
    id, subject,
    messages: [{
      id: id * 10, subject, from: { name: 'Alex Brown', email: 'alex@example.test' },
      to: [{ name: null, email: 'ops@example.test' }], cc: [], direction: 'incoming', is_read: true,
      occurred_at: '2026-09-19 12:0' + id + ':00', folder: 'Inbox', web_link: null, removed: false,
      body_state: 'fetched', needs_inline_images: false,
      body_html: '<p>See <a href="https://supplier.test/spec">spec</a> for ' + subject + '</p>',
      signature_html: '', quoted_html: '', has_quoted: false, has_attachments: true,
      attachments: [{ id: id * 100, filename: 'quote-' + id + '.pdf', mime_type: 'application/pdf', size: 51200, state: 'downloaded' }],
    }],
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
        if (action === 'threads') return route.fulfill({ json: { threads: [1, 2].map((id) => ({
          id, subject: 'Subject ' + id, preview: '', from_name: 'Alex Brown', from_email: 'alex@example.test',
          direction: 'incoming', latest_at: '2026-09-19 12:0' + id + ':00', message_count: 1,
          unread: false, unread_count: 0, has_attachments: true, case: null,
        })), next_cursor: null } });
        if (action === 'thread') return route.fulfill({ json: thread(Number(url.searchParams.get('id')), 'Subject ' + url.searchParams.get('id')) });
        if (action === 'mark_read') return route.fulfill({ json: { updated: 0 } });
        return route.fulfill({ json: {} });
      }
      if (rel === 'app.php') {
        // Naively stripping <?php ?> tags (same technique appearance_layout_browser.cjs
        // uses for case.php) leaves the *content* of app.php's `composer_only` PHP
        // `if` block behind — a <style> that hides the whole page outside the
        // composer. Real PHP only emits it when ?composer_only is set; drop it here too.
        let html = fs.readFileSync(path.join(web, rel), 'utf8')
          .replace(/<\?php[\s\S]*?\?>|<\?=[\s\S]*?\?>/g, '')
          .replace(/<style>body > :not\(#composerOverlay\)[\s\S]*?<\/style>/, '');
        return route.fulfill({ contentType: 'text/html', body: html });
      }
      const file = path.join(web, rel);
      if (!file.startsWith(path.join(web, 'assets') + path.sep) || !fs.existsSync(file)) return route.abort();
      return route.fulfill({ contentType: file.endsWith('.css') ? 'text/css' : 'application/javascript', body: fs.readFileSync(file) });
    });

    // No ?thread= yet: the pane must stay out of the layout entirely.
    await page.goto('https://ecw.test/other_things/web/app.php');
    await page.locator('#readingEmpty').waitFor();
    let width = await page.locator('#detailsPane').evaluate((el) => el.getBoundingClientRect().width);
    assert.equal(width, 0, 'no thread open yet: the details pane takes no space');

    await page.goto('https://ecw.test/other_things/web/app.php?thread=1&mailbox=1');
    await page.locator('#detailsBody .details-participant').first().waitFor();

    width = await page.locator('#detailsPane').evaluate((el) => el.getBoundingClientRect().width);
    assert.ok(width > 200, 'opening a thread reveals the pane at its resizable width');
    assert.match(await page.locator('#detailsBody .details-fact-list').innerText(), /Subject 1/);
    assert.match(await page.locator('#detailsBody .details-file-name').innerText(), /quote-1\.pdf/);
    assert.match(await page.locator('#detailsBody .details-link').innerText(), /supplier\.test/);

    // Collapse it, then switching threads must not resurrect it uncollapsed —
    // the collapse is a remembered preference, not tied to which thread is open.
    await page.locator('#detailsCollapseBtn').click();
    width = await page.locator('#detailsPane').evaluate((el) => el.getBoundingClientRect().width);
    // Folded, the pane keeps a thin strip of icons (its rail) to open it again.
    assert.ok(width > 0 && width <= 60, 'the collapse button folds the pane to its thin icon strip (' + width + 'px)');

    await page.locator('.thread-row[data-thread-id="2"]').click();
    // The pane is folded to its strip by the step above, so its content
    // is never "visible" to Playwright — wait on attachment/text instead of
    // the default visible state, which would hang forever here.
    await page.locator('#detailsBody .details-fact-list').filter({ hasText: 'Subject' })
      .waitFor({ state: 'attached' });
    assert.match(await page.locator('#detailsBody .details-fact-list').evaluate((el) => el.textContent), /Subject 2/);
    width = await page.locator('#detailsPane').evaluate((el) => el.getBoundingClientRect().width);
    assert.ok(width > 0 && width <= 60, 'switching threads keeps the folded preference instead of forcing the pane back open');

    await page.setViewportSize({width:390,height:664});
    await page.locator('#mailListBack').click();
    assert.equal(await page.locator('#readingPane').isVisible(), false, 'mobile back hides reader');
    assert.equal(await page.locator('#listPane').isVisible(), true, 'mobile back shows emails');
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'mail fits phone width');
    await page.locator('.thread-row[data-thread-id="1"]').click();
    assert.equal(await page.locator('#listPane').isVisible(), false, 'opening email hides mobile list');
    assert.equal(await page.locator('#readingPane').isVisible(), true);
    assert.equal(await page.locator('#detailsPane').isVisible(), false, 'desktop details stay hidden on phone');

    assert.deepEqual(errors, []);
    console.log('PASS reading pane: hidden with no thread open, reveals and fills in once one opens, collapse persists across a thread switch');
  } finally {
    await browser.close();
  }
})().catch((e) => { console.error(e); process.exitCode = 1; });
