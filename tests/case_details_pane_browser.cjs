// Desktop and mobile case layout: no details pane, scrollable thread comparison.
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const { chromium } = require('./support/playwright.cjs');
const web = path.resolve(__dirname, '../web');

(async () => {
  const browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
  try {
    const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));

    const bubbleBase = {
      to: [{ name: null, email: 'ops@example.test' }], cc: [], body_state: 'fetched',
      body_html: '<p>See <a href="https://supplier.test/spec">spec</a></p>',
      direction: 'incoming', attachments: [{ id: 9, filename: 'quote.pdf', mime_type: 'application/pdf', size: 51200 }],
    };
    const data = {
      summary: { name: 'Layout test', number: 'CASE-TEST', status: 'open', created_at: '2026-09-19 12:00:00', last_activity: '2026-09-19 12:04:00', thread_count: 2, timezone: 'UTC' },
      columns: [1, 2].map((id) => ({ thread_id: id, mailbox_id: 1, label: 'Thread ' + id, subject: 'Thread ' + id, message_count: 1, participants: [], is_hidden: false })),
      events: [1, 2].map((id) => ({
        type: 'email', message_id: id, thread_id: id, occurred_at: '2026-09-19 12:0' + id + ':00',
        bubble: { ...bubbleBase, from: { name: 'Alex Brown', email: 'alex@example.test' } },
      })),
    };

    await page.route('https://ecw.test/**', async (route) => {
      const url = new URL(route.request().url()), rel = url.pathname.replace('/other_things/web/', '');
      if (rel === 'api/index.php') {
        const action = url.searchParams.get('action');
        if (action === 'case_timeline') return route.fulfill({ json: data });
        if (action === 'mailboxes') return route.fulfill({ json: { mailboxes: [{ id: 1, email_address: 'buyer@example.test' }] } });
        return route.fulfill({ json: { users: [] } });
      }
      if (rel === 'case.php') {
        let html = fs.readFileSync(path.join(web, rel), 'utf8')
          .replace(/<\?php[\s\S]*?\?>|<\?=[\s\S]*?\?>/g, '')
          .replace('data-case=""', 'data-case="1"');
        return route.fulfill({ contentType: 'text/html', body: html });
      }
      const file = path.join(web, rel);
      if (!file.startsWith(path.join(web, 'assets') + path.sep) || !fs.existsSync(file)) return route.abort();
      return route.fulfill({ contentType: file.endsWith('.css') ? 'text/css' : 'application/javascript', body: fs.readFileSync(file) });
    });

    await page.goto('https://ecw.test/other_things/web/case.php');
    await page.locator('.bubble').last().waitFor();

    assert.equal(await page.locator('#detailsPane').count(), 0);
    await page.locator('.tl-head[data-thread-id="1"]').click();
    assert.equal(await page.locator('.focused').count(), 0);
    assert.equal(await page.locator('#detailsPane').count(), 0);

    // Enough real bubbles to exercise vertical scrolling on a short phone.
    data.events = Array.from({length:30}, (_,i) => ({...data.events[i%2], message_id:i+1}));
    await page.setViewportSize({width:390,height:664});
    // A phone opens a case of several conversations side by side ("All"), to swipe between.
    await page.waitForFunction(() => document.querySelectorAll('.tl-head:not(.rail)').length === 2 && document.querySelector('#timeline').classList.contains('mobile-compare'));
    const picker = page.locator('#threadPickerSelect');
    assert.equal(await picker.isVisible(), true);
    assert.equal(await picker.inputValue(), 'all');
    await page.waitForFunction(() => document.querySelector('#timelineScroll').scrollHeight > document.querySelector('#timelineScroll').clientHeight);
    assert.equal(await page.locator('#timelineScroll').evaluate(el => {el.scrollTop=180;return el.scrollTop>0;}),true,'messages scroll on mobile');
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),true,'case header fits phone');
    assert.equal(await page.locator('#timelineScroll').evaluate(el => {el.scrollLeft=200;return el.scrollLeft>0;}),true,'comparison scrolls horizontally');
    // Picking a conversation there slides to its column, without leaving the side-by-side view.
    await picker.selectOption('2');
    assert.equal(await picker.inputValue(), 'all');
    assert.equal(await page.locator('.tl-head:not(.rail)').count(), 2);
    await page.setViewportSize({width:1400,height:900});
    await page.waitForFunction(() => !document.querySelector('#timeline').classList.contains('mobile-compare'));

    // A case of one conversation on a phone: that conversation alone, as wide as the screen.
    data.columns = data.columns.slice(0, 1);
    data.events = data.events.filter((e) => e.thread_id === 1);
    await page.setViewportSize({width:390,height:664});
    await page.waitForFunction(() => document.querySelectorAll('.tl-head:not(.rail)').length === 1 && !document.querySelector('#timeline').classList.contains('mobile-compare'));
    const one = await page.locator('#timelineScroll').evaluate(el => ({width:el.clientWidth,scrollWidth:el.scrollWidth}));
    assert.ok(one.scrollWidth<=one.width+1,'single thread fits phone width');
    await page.setViewportSize({width:1400,height:900});

    assert.deepEqual(errors, []);
    console.log('PASS case desktop clicks, mobile scrolling, thread switching, swipe comparison and desktop restoration');
  } finally {
    await browser.close();
  }
})().catch((e) => { console.error(e); process.exitCode = 1; });
