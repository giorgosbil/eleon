// Calendar: an event over several days is one continuous bar from its first to its last day
// (as in Outlook), not a piece per day. Across a week boundary it continues on the next row,
// squared off where it goes on. Single-day events stay chips in their day.
// Same route-mocking technique as app_details_pane_browser.cjs (a fake server at ecw.test).
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const { chromium } = require('./support/playwright.cjs');
const web = path.resolve(__dirname, '../web');

const pad = (n) => String(n).padStart(2, '0');
const key = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
const monday = new Date(); monday.setHours(0, 0, 0, 0); monday.setDate(monday.getDate() - ((monday.getDay() + 6) % 7));
const day = (n) => { const d = new Date(monday); d.setDate(d.getDate() + n); return d; };
const events = [
  // Tuesday to Thursday, all day (the end is the day after, as Outlook sends it).
  { id: 'trip', title: 'Factory visit', allDay: true, start: key(day(1)), end: key(day(4)), editable: true, busy: 'busy' },
  // Saturday to next Tuesday: over the week boundary.
  { id: 'fair', title: 'Trade fair', allDay: true, start: key(day(5)), end: key(day(9)), editable: true, busy: 'busy' },
  // One day only.
  { id: 'call', title: 'Supplier call', allDay: false, start: new Date(day(2).getTime() + 10 * 3600e3).toISOString(), end: new Date(day(2).getTime() + 11 * 3600e3).toISOString(), editable: true, busy: 'busy' },
];

(async () => {
  const browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
  try {
    const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.addInitScript(() => { window.ecwWebBase = '/other_things/web/'; });   // the top bar sets it in the app
    await page.route('https://ecw.test/**', async (route) => {
      const url = new URL(route.request().url()), rel = url.pathname.replace('/other_things/web/', '');
      if (rel === 'api/index.php' || rel === 'api/') {
        if (url.searchParams.get('action') === 'calendar_events') return route.fulfill({ json: { events } });
        return route.fulfill({ json: {} });
      }
      if (rel === 'calendar.php') {
        const html = fs.readFileSync(path.join(web, rel), 'utf8').replace(/<\?php[\s\S]*?\?>|<\?=[\s\S]*?\?>/g, '').replace('data-connected=""', 'data-connected="1"');
        return route.fulfill({ contentType: 'text/html', body: html });
      }
      const file = path.join(web, rel);
      if (!file.startsWith(path.join(web, 'assets') + path.sep) || !fs.existsSync(file)) return route.abort();
      return route.fulfill({ contentType: file.endsWith('.css') ? 'text/css' : 'application/javascript', body: fs.readFileSync(file) });
    });

    await page.goto('https://ecw.test/other_things/web/calendar.php');
    await page.locator('.calendar-span[data-id="trip"]').first().waitFor();

    // Month: the three-day event is ONE bar as wide as three days.
    const trip = page.locator('.calendar-span[data-id="trip"]');
    assert.equal(await trip.count(), 1, 'Tuesday–Thursday is one bar, not three pieces');
    const cellW = (await page.locator(`.calendar-cell[data-date="${key(day(1))}"]`).boundingBox()).width;
    const tripBox = await trip.boundingBox();
    assert.ok(tripBox.width > cellW * 2.6 && tripBox.width < cellW * 3.1, 'the bar runs from Tuesday to Thursday (' + Math.round(tripBox.width) + ' px for 3 days of ' + Math.round(cellW) + ')');
    const tueBox = await page.locator(`.calendar-cell[data-date="${key(day(1))}"]`).boundingBox();
    assert.ok(Math.abs(tripBox.x - tueBox.x) < 8, 'it starts at Tuesday');

    // Over the weekend into next week: a bar on each row, squared off where it continues.
    const fair = page.locator('.calendar-span[data-id="fair"]');
    assert.equal(await fair.count(), 2, 'Saturday–Tuesday: one bar on this week row, one on the next');
    assert.ok(await fair.nth(0).evaluate((n) => n.classList.contains('cont-right')) && await fair.nth(1).evaluate((n) => n.classList.contains('cont-left')), 'squared off where it goes on, picked up on the next row');

    // A one-day event stays a chip in its day, under the bars.
    const wed = page.locator(`.calendar-cell[data-date="${key(day(2))}"]`);
    assert.equal(await wed.locator('.calendar-event[data-id="call"]').count(), 1, 'the one-day call is in Wednesday');
    const callBox = await wed.locator('.calendar-event[data-id="call"]').boundingBox();
    assert.ok(callBox.y >= tripBox.y + tripBox.height - 1, 'below the bar crossing Wednesday, not hidden under it');

    // Week view: the all-day row draws the same one bar.
    await page.selectOption('#calendarView', 'week');
    await page.locator('.calendar-all-day .calendar-span[data-id="trip"]').waitFor();
    assert.equal(await page.locator('.calendar-all-day .calendar-span[data-id="trip"]').count(), 1, 'week view: one all-day bar');
    const colW = (await page.locator(`.calendar-all-day-cell[data-date="${key(day(1))}"]`).boundingBox()).width;
    const weekTrip = await page.locator('.calendar-all-day .calendar-span[data-id="trip"]').boundingBox();
    assert.ok(weekTrip.width > colW * 2.6, 'across three day columns');

    assert.deepEqual(errors, []);
    console.log('PASS calendar: a several-day event is one continuous bar (month and week), continued across weeks, single-day events below it');
  } finally {
    await browser.close();
  }
})().catch((e) => { console.error(e); process.exitCode = 1; });
