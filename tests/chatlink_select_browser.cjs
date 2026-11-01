// Browser test: choosing chat messages to link to a case (modules/chatlink) on
// the Telegram tab, with synthetic data and no server: the bar stays one line,
// a dragged box selects what it touches, click / Shift+click, Done → case → link.
//   node tests/chatlink_select_browser.cjs
// Playwright: tests/support/playwright.cjs; ECW_SHOTS: a folder for screenshots.
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const { chromium } = require('./support/playwright.cjs');
const root = path.resolve(__dirname, '..'), web = path.join(root, 'web');
const shots = process.env.ECW_SHOTS || '';
const shot = (page, name) => (shots ? page.screenshot({ path: path.join(shots, name + '.png') }) : null);

const messages = Array.from({ length: 12 }, (_, i) => ({
  id: 100 + i, direction: i % 3 === 2 ? 'out' : 'in', kind: 'text', body: 'Message number ' + (i + 1) + (i === 4 ? ' — a longer one about the pump that broke on Friday, with the serial number' : ''),
  has_media: false, status: i % 3 === 2 ? 'sent' : 'received', sender: i % 3 === 2 ? 'Giorgos' : null, edited: false, at: new Date(Date.UTC(2026, 9, 18, 9, i)).toISOString(),
}));
const chat = { id: 7, name: 'Maria P', username: 'maria', phone: '+306900000000', title: 'Maria P', case_id: null, case_label: null, last_at: messages[11].at, preview: 'Message number 12', unread: 0, loading: false };
const status = { status: 'ready', phone: '+306900000001', name: 'Giorgos', username: '', hint: '', error: null, busy: false, here: true, daemon_ok: true, needs_api: false, has_api: true, synced: true };

(async () => {
  const browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
  try {
    const page = await browser.newPage({ viewport: { width: 1300, height: 820 } }), errors = [], linked = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.route('https://ecw.test/**', async (route) => {
      const url = new URL(route.request().url()), rel = url.pathname.replace('/other_things/web/', '');
      const action = url.searchParams.get('action');
      if (rel === 'api/index.php') {
        const answers = {
          tg_status: { status }, tg_chats: { chats: [chat], status }, tg_messages: { chat, messages },
          chatlink_marks: { marks: {} }, chatlink_suggest: { suggestion: null, ai: false },
          cases: { cases: [{ id: 41, case_number: 'C-41', name: 'Pump repair', status: 'open' }] },
        };
        if (action === 'chatlink_link') { linked.push(JSON.parse(route.request().postData())); return route.fulfill({ json: { linked: 3, case_id: 41, case_label: '#C-41 Pump repair' } }); }
        return route.fulfill({ json: answers[action] || {} });
      }
      if (rel === 'module.php' && url.searchParams.get('p') === 'telegram') {
        let html = fs.readFileSync(path.join(root, 'modules/telegram/web/telegram.php'), 'utf8').replace(/<\?php[\s\S]*?\?>|<\?=[\s\S]*?\?>/g, '');
        // What partials/nav.php adds on a real page: the chat styles and the modules' styles and scripts.
        html = html.replace('</head>', '<link rel="stylesheet" href="assets/teams.css"><link rel="stylesheet" href="assets/collab.css">'
          + '<link rel="stylesheet" href="module.php?m=telegram&asset=telegram.css"><link rel="stylesheet" href="module.php?m=chatlink&asset=chatlink.css"></head>');
        html = html.replace('</body>', '<script src="module.php?m=telegram&asset=telegram.js"></script><script src="module.php?m=chatlink&asset=chatlink.js"></script></body>');
        return route.fulfill({ contentType: 'text/html', body: html });
      }
      if (rel === 'module.php' && url.searchParams.get('asset')) {
        const file = path.join(root, 'modules', url.searchParams.get('m'), 'assets', url.searchParams.get('asset'));
        return route.fulfill({ contentType: file.endsWith('.css') ? 'text/css' : 'application/javascript', body: fs.readFileSync(file) });
      }
      const file = path.join(web, rel);
      if (!file.startsWith(path.join(web, 'assets')) || !fs.existsSync(file)) return route.abort();
      return route.fulfill({ contentType: file.endsWith('.css') ? 'text/css' : 'application/javascript', body: fs.readFileSync(file) });
    });

    await page.goto('https://ecw.test/other_things/web/module.php?m=telegram&p=telegram');
    await page.locator('#tgList .teams-chat-item').first().click();
    await page.locator('#tgMessages .teams-msg[data-id="111"]').waitFor();
    await shot(page, '1-chat');

    // "Link to case": a one-line bar, not a huge block.
    await page.locator('.chl-link-btn').click();
    const bar = await page.locator('.chl-bar').boundingBox();
    await shot(page, '2-selecting');
    assert.ok(bar && bar.height < 70, 'the bar is one line high (got ' + (bar && bar.height) + 'px)');
    const list = await page.locator('#tgMessages').boundingBox();
    assert.ok(list.height > 300, 'the conversation keeps its room (got ' + list.height + 'px)');

    // Drag a box from empty space beside message 2 down to message 4: 2, 3 and 4 are selected.
    await page.locator('#tgMessages').evaluate((e) => { e.scrollTop = 0; });
    const m2 = await page.locator('.teams-msg[data-id="101"]').boundingBox(), m4 = await page.locator('.teams-msg[data-id="103"]').boundingBox();
    await page.mouse.move(list.x + list.width - 30, m2.y + 4);
    await page.mouse.down();
    await page.mouse.move(list.x + list.width - 200, m4.y + m4.height / 2, { steps: 8 });
    assert.equal(await page.locator('.chl-band').isVisible(), true, 'a selection box is drawn while dragging');
    await shot(page, '3-dragging');
    await page.mouse.up();
    const sel = async () => page.locator('.teams-msg.chl-selected').evaluateAll((r) => r.map((x) => x.dataset.id));
    assert.deepEqual(await sel(), ['101', '102', '103'], 'the box selected what it touched');
    assert.equal(await page.locator('.chl-band').count(), 0, 'the box goes away');

    // Click toggles one; Shift+click selects the range from it.
    await page.locator('.teams-msg[data-id="106"] .teams-msg-bubble').click();
    await page.locator('.teams-msg[data-id="108"] .teams-msg-bubble').click({ modifiers: ['Shift'] });
    assert.deepEqual(await sel(), ['101', '102', '103', '106', '107', '108'], 'click and Shift+click');
    await page.locator('.teams-msg[data-id="102"] .teams-msg-bubble').click();
    assert.deepEqual(await sel(), ['101', '103', '106', '107', '108'], 'clicking a selected one takes it out');
    assert.match(await page.locator('.chl-bar-text').textContent(), /5 messages selected/);
    await shot(page, '4-selected');

    // Done → choose the case → linked, and the bar closes.
    await page.locator('.chl-bar button.primary').click();
    await page.locator('.chl-dialog .chl-cases li[role=option]').first().click();
    await page.locator('.chl-toast').waitFor();
    assert.deepEqual(linked[0].messages.map(String), ['101', '103', '106', '107', '108']);
    assert.equal(linked[0].case_id, 41);
    assert.equal(linked[0].source, 'telegram');
    assert.equal(await page.locator('.chl-bar').isVisible(), false);
    await shot(page, '5-linked');

    assert.deepEqual(errors, []);
    console.log('PASS chat messages: one-line bar, drag a box, click / Shift+click, Done → case → linked');

    // ---------------------------------------------------- on the case: a chat column
    const casePage = await browser.newPage({ viewport: { width: 1400, height: 900 } }), caseErrors = [], calls = [];
    casePage.on('pageerror', (e) => caseErrors.push(e.message));
    const bubble = (dir, text) => ({ from: { name: dir === 'incoming' ? 'Maria P' : 'Giorgos', email: 'x@example.test' }, to: [], cc: [], body_state: 'fetched', body_html: '<p>' + text + '</p>', direction: dir, attachments: [] });
    const item = (id, dir, sender, text, at, skipped) => ({ id, sender, direction: dir, text, html: null, filename: null, at, linked_by: 'Giorgos', linked_at: at, skipped_before: skipped });
    const timeline = {
      summary: { name: 'Pump repair', number: 'C-41', status: 'open', created_at: '2026-10-18 08:00:00', last_activity: '2026-10-18 10:00:00', thread_count: 2, timezone: 'UTC' },
      columns: [
        { kind: 'email', thread_id: 1, link_id: 1, mailbox_id: 1, label: 'Customer: pump', message_count: 2, participants: [], is_hidden: false, unread_count: 0 },
        { kind: 'chat', thread_id: 'chat:7', label: 'Maria P', icon: 'fa-brands fa-whatsapp', subject: 'WhatsApp chat: Maria P', subtitle: 'WhatsApp chat · only the messages linked to this case',
          message_count: 4, latest_at: '2026-10-18 09:40:00', is_hidden: false, open_url: 'module.php?m=whatsapp&p=whatsapp&chat=7', open_label: 'Open the chat',
          update_action: 'chatlink_column_update', unlink_action: 'chatlink_column_unlink', participants: [], unread_count: 0,
          reply_action: 'chatlink_column_reply', reply_note: 'Sent from the workspace’s WhatsApp number.', extra_actions: [{ label: 'Follow the chat', changes: { follow: true } }] },
      ],
      events: [
        { type: 'email', message_id: 11, thread_id: 1, occurred_at: '2026-10-18 09:00:00', bubble: bubble('incoming', 'The pump stopped working.') },
        { type: 'chat', thread_id: 'chat:7', occurred_at: '2026-10-18 09:10:00', items: [
          item(501, 'in', 'Maria P', 'Here is a photo of the label', '2026-10-18T09:10:00Z', null),
          item(502, 'in', 'Maria P', 'Serial 4471-B', '2026-10-18T09:11:00Z', null),
          item(503, 'out', 'Giorgos', 'Thanks, we check it', '2026-10-18T09:14:00Z', 3)] },
        { type: 'email', message_id: 12, thread_id: 1, occurred_at: '2026-10-18 09:30:00', bubble: bubble('outgoing', 'We sent a technician.') },
        { type: 'chat', thread_id: 'chat:7', occurred_at: '2026-10-18 09:40:00', items: [item(504, 'in', 'Maria P', 'He is here, thank you!', '2026-10-18T09:40:00Z', null)] },
      ],
      truncated: false,
    };
    await casePage.route('https://ecw.test/**', async (route) => {
      const url = new URL(route.request().url()), rel = url.pathname.replace('/other_things/web/', ''), action = url.searchParams.get('action');
      if (rel === 'api/index.php') {
        if (route.request().method() === 'POST') calls.push([action, JSON.parse(route.request().postData() || '{}')]);
        return route.fulfill({ json: action === 'case_timeline' ? timeline : {} });
      }
      if (rel === 'case.php') {
        let html = fs.readFileSync(path.join(web, 'case.php'), 'utf8').replace(/<\?php[\s\S]*?\?>|<\?=[\s\S]*?\?>/g, '').replace('data-case=""', 'data-case="41"');
        html = html.replace('</head>', '<link rel="stylesheet" href="assets/teams.css"><link rel="stylesheet" href="assets/collab.css"><link rel="stylesheet" href="module.php?m=chatlink&asset=chatlink.css"></head>');
        html = html.replace('</body>', '<script src="module.php?m=chatlink&asset=chatlink.js"></script></body>');
        return route.fulfill({ contentType: 'text/html', body: html });
      }
      if (rel === 'module.php' && url.searchParams.get('asset')) {
        const file = path.join(root, 'modules', url.searchParams.get('m'), 'assets', url.searchParams.get('asset'));
        return route.fulfill({ contentType: file.endsWith('.css') ? 'text/css' : 'application/javascript', body: fs.readFileSync(file) });
      }
      const file = path.join(web, rel);
      if (!file.startsWith(path.join(web, 'assets')) || !fs.existsSync(file)) return route.abort();
      return route.fulfill({ contentType: file.endsWith('.css') ? 'text/css' : 'application/javascript', body: fs.readFileSync(file) });
    });
    await casePage.goto('https://ecw.test/other_things/web/case.php');
    await casePage.locator('.tl-head[data-thread-id="chat:7"]').waitFor();
    await casePage.locator('.chl-tl-msg').first().waitFor();
    await shot(casePage, '6-case-columns');
    assert.match(await casePage.locator('.tl-head[data-thread-id="chat:7"] .tl-head-name').textContent(), /Maria P/);
    assert.equal(await casePage.locator('.tl-cell-chat').count(), 2, 'two chat rows: the email between splits the burst');
    assert.equal(await casePage.locator('.tl-cell-chat').first().locator('.chl-tl-msg').count(), 3, 'three messages share the first row');
    assert.match(await casePage.locator('.chl-tl-gap').first().textContent(), /3 messages not in this case/);
    assert.equal((await casePage.locator('.chl-tl-time').first().textContent()).replace('×', ''), await casePage.locator('.tl-rail-time').nth(1).textContent(), 'chat times use the case time zone, like the rail');
    // Rows go in time order across columns: chat (09:10) after the first email (09:00), before the reply (09:30).
    const y = async (sel) => (await casePage.locator(sel).first().boundingBox()).y;
    const e1 = await y('#msg-11'), c1 = await y('.tl-cell-chat'), e2 = await y('#msg-12');
    assert.ok(e1 < c1 && c1 < e2, 'the chat row sits between the two emails');
    const x = async (sel) => (await casePage.locator(sel).first().boundingBox()).x;
    assert.ok(await x('.tl-cell-chat') > await x('#msg-11'), 'in its own column, right of the email thread');
    // Column actions go to the module: Hide.
    await casePage.locator('.tl-head[data-thread-id="chat:7"]').hover();
    await casePage.locator('.tl-head[data-thread-id="chat:7"] .tl-head-actions button', { hasText: 'Hide' }).click({ force: true });
    await casePage.waitForTimeout(300);
    assert.deepEqual(calls.find((c) => c[0] === 'chatlink_column_update'), ['chatlink_column_update', { column: 'chat:7', is_hidden: true }]);
    // Follow and Reply, also through the module.
    await casePage.locator('.tl-head[data-thread-id="chat:7"] .tl-head-actions button', { hasText: 'Follow the chat' }).click({ force: true });
    await casePage.waitForTimeout(300);
    assert.ok(calls.some((c) => c[0] === 'chatlink_column_update' && c[1].follow === true), 'Follow the chat asks the module');
    await casePage.locator('.tl-head[data-thread-id="chat:7"] .tl-head-reply').click({ force: true });
    await casePage.locator('.tl-reply-dialog textarea').fill('The technician comes at 10.');
    await shot(casePage, '7-reply');
    await casePage.locator('.tl-reply-dialog button.primary').click();
    await casePage.waitForTimeout(300);
    assert.deepEqual(calls.find((c) => c[0] === 'chatlink_column_reply'), ['chatlink_column_reply', { column: 'chat:7', text: 'The technician comes at 10.' }]);
    assert.deepEqual(caseErrors, []);
    console.log('PASS case: the chat is a column on the time axis, bursts share a row, gap markers, column actions go to the module');
  } finally { await browser.close(); }
})().catch((e) => { console.error(e); process.exitCode = 1; });
