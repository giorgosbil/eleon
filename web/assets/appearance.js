/*
 * Personal appearance (lib/appearance.php): theme, conversation colours,
 * background, doodles, accent, page / panel / text / top bar / button colours,
 * fonts, sizes, button shapes, spacing and corners.
 * Applied on every page from #appearanceConfig, and live while editing
 * Settings → Appearance (with its saved looks and starting points).
 */
(() => {
  'use strict';
  const root = document.documentElement;
  const config = document.getElementById('appearanceConfig');
  const assets = (document.currentScript && document.currentScript.src || '').replace(/appearance\.js.*$/, '');
  const defaults = {
    light: {canvas:'#f7f8fa', sent:'#edf4ff', received:'#ffffff'},
    dark: {canvas:'#151c27', sent:'#243b55', received:'#25303e'},
    mild: {canvas:'#eeeae2', sent:'#e1e9dc', received:'#fffdf7'},
    mist: {canvas:'#e9eef4', sent:'#dde7f1', received:'#fbfcfe'},
    sand: {canvas:'#f3eadb', sent:'#f6e6d2', received:'#fffaf2'},
    graphite: {canvas:'#1a1b1d', sent:'#2f3a33', received:'#2a2b2f'},
  };
  const isHex = (v) => /^#[0-9a-f]{6}$/i.test(v || '');
  const contrast = (color) => {
    const rgb = color.slice(1).match(/../g).map((x) => parseInt(x, 16) / 255)
      .map((x) => (x <= 0.04045 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4));
    return rgb[0] * 0.2126 + rgb[1] * 0.7152 + rgb[2] * 0.0722 > 0.179 ? '#17202c' : '#f5f7fa';
  };
  const clamp = (v, lo, hi, d) => { const n = Number(v); return Number.isFinite(n) ? Math.max(lo, Math.min(hi, n)) : d; };
  // A font name from lib/appearance.php ECW_APPEARANCE_FONTS, with fallbacks of its kind.
  const fontStack = (name) => {
    if (!/^[\w ]+$/.test(name || '')) return '';
    const tail = ['Georgia', 'Times New Roman'].includes(name) ? 'Georgia,serif' : name === 'Courier New' ? 'Courier,monospace' : "'Segoe UI',Helvetica,Arial,sans-serif";
    return "'" + name + "'," + tail;
  };
  const setVar = (name, value) => { if (value) root.style.setProperty(name, value); else root.style.removeProperty(name); };
  const setFlag = (name, value) => { if (value) root.dataset[name] = value; else delete root.dataset[name]; };
  const mix = (color, pct, base) => 'color-mix(in srgb, ' + color + ' ' + pct + '%, ' + base + ')';

  // ------------------------------------------------------------- doodles
  // One black tile (assets/doodles-pattern.svg) is fetched once, then recoloured
  // in the browser: theme text colour (or the chosen one) at the chosen strength.
  let tile = null, tileLoading = null, lastDoodle = '';
  function loadTile() {
    if (tile) return Promise.resolve(tile);
    if (!tileLoading) {
      tileLoading = fetch(assets + 'doodles-pattern.svg', { credentials: 'same-origin' })
        .then((r) => (r.ok ? r.text() : ''))
        .then((t) => { tile = t; return t; })
        .catch(() => '');
    }
    return tileLoading;
  }
  function paintDoodles(color, opacity) {
    const key = color + '|' + opacity;
    if (key === lastDoodle) return;
    lastDoodle = key;
    loadTile().then((svg) => {
      if (!svg || lastDoodle !== key) return;
      const tinted = svg.replace('fill="#000"', 'fill="' + color + '" fill-opacity="' + (opacity / 100).toFixed(3) + '"');
      root.style.setProperty('--doodle-image', 'url("data:image/svg+xml;charset=utf-8,' + encodeURIComponent(tinted) + '")');
    });
  }

  function apply(prefs) {
    prefs = prefs || {};
    const theme = defaults[prefs.theme] ? prefs.theme : 'light';
    root.dataset.theme = theme;
    let bg = prefs.background === 'doodles2' ? 'doodles' : prefs.background;
    root.dataset.threadBackground = ['dots', 'lines', 'doodles'].includes(bg) ? bg : 'plain';
    const own = (k) => (isHex(prefs[k]) ? prefs[k] : '');
    for (const key of ['canvas', 'sent', 'received']) {
      const color = isHex(prefs[key]) ? prefs[key] : defaults[theme][key];
      root.style.setProperty('--mail-' + key, color);
      root.style.setProperty('--mail-' + key + '-text', own(key + '_text') || contrast(color));
    }
    // Page, panels, text and borders: an empty one is the theme's. A panel colour picked without
    // a text colour gets dark or light text to suit it, so it stays readable.
    // A dark panel on a light page (or the other way round) would leave the text unreadable on one
    // of them: then the colour not picked follows the one picked (the page a shade darker than the panels).
    for (const n of ['--bg', '--bg-alt']) root.style.removeProperty(n);
    const theme0 = getComputedStyle(root), themeBg = theme0.getPropertyValue('--bg').trim(), themePage = theme0.getPropertyValue('--bg-alt').trim();
    const flips = (a, b) => isHex(a) && isHex(b) && contrast(a) !== contrast(b);
    const dark = (c) => contrast(c) !== '#17202c';
    let panel = own('color_panel'), page = own('color_page'), base = panel;
    if (panel && !page && flips(panel, themePage)) page = mix(panel, dark(panel) ? 78 : 96, '#000');
    if (page && !panel && !own('color_text') && flips(own('color_page'), themeBg)) { base = own('color_page'); panel = mix(base, dark(base) ? 90 : 25, '#fff'); }
    const autoText = base && !own('color_text') ? contrast(base) : '';
    setVar('--bg', panel);
    setVar('--bg-alt', page);
    setVar('--border', own('color_border') || (autoText && mix(autoText, 18, base)));
    setVar('--text', own('color_text') || autoText);
    setVar('--text-2', own('color_text2') || (autoText && mix(autoText, 78, base)));
    setVar('--muted', !own('color_text2') && autoText ? mix(autoText, 58, base) : '');
    const bar = own('color_topbar'), barText = own('color_topbar_text') || (bar ? contrast(bar) : '');
    setVar('--bar-bg', bar);
    setVar('--bar-text', barText);
    setFlag('customBar', bar || barText ? '1' : '');
    setVar('--link-color', own('color_link'));
    setFlag('customLink', own('color_link') ? '1' : '');
    // Buttons: own colour (text dark or light to suit), shape, filled or outline.
    const btn = own('button_color');
    setVar('--btn-color', btn);
    setVar('--btn-text', btn ? contrast(btn) : '');
    setFlag('customButton', btn ? '1' : '');
    setFlag('buttonShape', ['square', 'rounded', 'pill'].includes(prefs.button_shape) ? prefs.button_shape : '');
    setFlag('buttonStyle', prefs.button_style === 'outline' ? 'outline' : '');
    setFlag('bubbleEdge', ['flat', 'border', 'shadow'].includes(prefs.bubble_edge) ? prefs.bubble_edge : '');
    // Letters of the app, its size (zoom: the app's sizes are in px), and the font emails are read in.
    const uiFont = fontStack(prefs.ui_font), mailFamily = fontStack(prefs.mail_family);
    setVar('--ui-font', uiFont); setFlag('uiFont', uiFont ? '1' : '');
    setVar('--mail-family', mailFamily); setFlag('mailFamily', mailFamily ? '1' : '');
    const line = Number(prefs.mail_line), lineOn = line >= 120 && line <= 200;
    setVar('--mail-line', lineOn ? String(line / 100) : ''); setFlag('mailLine', lineOn ? '1' : '');
    const scale = clamp(prefs.ui_scale, 90, 130, 100);
    setVar('--ui-zoom', scale !== 100 ? String(scale / 100) : ''); setFlag('uiScale', scale !== 100 ? String(scale) : '');
    if (isHex(prefs.accent)) { root.dataset.customAccent = '1'; root.style.setProperty('--custom-accent', prefs.accent); }
    else { delete root.dataset.customAccent; root.style.removeProperty('--custom-accent'); }
    root.style.setProperty('--mail-font', clamp(prefs.mail_font, 12, 20, 14) + 'px');
    // The font new emails are written in (the composer shows what will be sent).
    // Saved: the full stack from the server. Live preview in Settings: the bare name.
    const stack = prefs.compose_stack || (/^[\w ]+$/.test(prefs.compose_font || '') ? "'" + prefs.compose_font + "'" : '');
    root.style.setProperty('--compose-font', stack || "'Segoe UI',Helvetica,Arial,sans-serif");
    root.style.setProperty('--compose-size', clamp(prefs.compose_size, 8, 36, 11) + 'pt');   // points, as Outlook shows them   // lib/appearance.php ECW_COMPOSE_SIZE_MIN / MAX
    if (isHex(prefs.compose_color)) root.style.setProperty('--compose-color', prefs.compose_color); else root.style.removeProperty('--compose-color');
    root.dataset.density = prefs.density === 'compact' ? 'compact' : 'comfortable';
    root.dataset.corners = ['square', 'round'].includes(prefs.corners) ? prefs.corners : 'rounded';
    root.dataset.listPreview = prefs.list_preview === false || String(prefs.list_preview) === '0' ? 'off' : 'on';
    root.dataset.ruleStyle = prefs.rule_style === 'text' ? 'text' : 'row';
    root.style.setProperty('--doodle-scale', String(clamp(prefs.doodle_size, 60, 160, 100) / 100));
    if (root.dataset.threadBackground === 'doodles') {
      const canvas = isHex(prefs.canvas) ? prefs.canvas : defaults[theme].canvas;
      paintDoodles(isHex(prefs.doodle_color) ? prefs.doodle_color : contrast(canvas), clamp(prefs.doodle_opacity, 3, 30, 9));
    }
  }
  let loaded = {};
  if (config) { try { loaded = JSON.parse(config.textContent) || {}; } catch (_) {} }
  // Font names and sizes offered in the composer (lib/appearance.php ECW_COMPOSE_FONTS).
  window.ecwComposeFontNames = loaded.compose_fonts || [];
  window.ecwComposeSizes = loaded.compose_sizes || [];
  window.ecwComposeDefault = { font: loaded.compose_font || 'Segoe UI', size: loaded.compose_size || 11 };
  apply(loaded);
  window.ecwApplyAppearance = apply;

  // ------------------- Settings → Appearance, Email & writing, Notifications
  // Each page has some of the choices: the preview is the saved ones with this page's on top.
  document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('appearanceForm');
    if (!form) return;
    const el = form.elements;
    // Colour pickers with an "Own colour" box: unticked, the value saved is empty (the theme's).
    const owns = [...form.querySelectorAll('input[type="color"]')].map((i) => i.name).filter((k) => el[k + '_custom']);
    // Unticked, a picker shows the colour in use now, so ticking it starts from there.
    const shows = { accent: '--accent', color_page: '--bg-alt', color_panel: '--bg', color_text: '--text', color_text2: '--text-2', color_border: '--border',
      color_topbar: ['--bar-bg', '--bg'], color_topbar_text: ['--bar-text', '--text-2'], color_link: '--accent', button_color: '--accent',
      sent_text: '--mail-sent-text', received_text: '--mail-received-text' };
    const showCurrent = () => {
      const css = getComputedStyle(root);
      for (const [k, vars] of Object.entries(shows)) {
        if (!el[k] || el[k + '_custom']?.checked) continue;
        const v = [].concat(vars).map((n) => css.getPropertyValue(n).trim()).find(Boolean);
        if (isHex(v)) el[k].value = v.toLowerCase();
      }
    };
    const readForm = () => {
      const data = Object.fromEntries(new FormData(form));
      owns.forEach((k) => { if (!el[k + '_custom'].checked) data[k] = ''; });
      const merged = Object.assign({}, loaded, data);
      if ('compose_font' in data) delete merged.compose_stack;   // the font picked here, not the saved one
      return merged;
    };
    const sync = () => {
      form.querySelectorAll('[data-show-when]').forEach((box) => {
        const [name, value] = box.dataset.showWhen.split('=');
        box.hidden = !el[name] || el[name].value !== value;
      });
      // Unticked, the picker stays usable but looks off; picking a colour ticks "Own" (below).
      owns.forEach((k) => el[k].classList.toggle('ap-color-off', !el[k + '_custom'].checked));
      form.querySelectorAll('output[data-for]').forEach((o) => { o.textContent = el[o.dataset.for].value + (o.dataset.unit || ''); });
    };
    const preview = () => { sync(); apply(readForm()); showCurrent(); };
    owns.forEach((k) => el[k].addEventListener('input', () => { el[k + '_custom'].checked = true; }));
    form.addEventListener('input', preview);
    form.addEventListener('change', preview);
    el.theme?.addEventListener('change', () => {
      for (const key of ['canvas', 'sent', 'received']) el[key].value = defaults[el.theme.value][key];
      preview();
    });
    // Reset puts back the defaults of the choices on this page only (lib/appearance.php).
    const resets = {
      theme: 'light', background: 'plain', canvas: defaults.light.canvas, sent: defaults.light.sent, received: defaults.light.received,
      doodle_opacity: 9, doodle_size: 100, mail_font: 14, density: 'comfortable', corners: 'rounded', rule_style: 'row',
      compose_font: 'Segoe UI', compose_size: 11, reply_mode: 'window', thread_order: 'asc', badge_mode: 'number',
    };
    // A whole look (a saved one, a starting point, or the defaults) into the form: every choice of the page.
    const fill = (look) => {
      const theme = defaults[look.theme] ? look.theme : 'light';
      for (const [k, v] of Object.entries(look)) {
        if (k === 'list_preview') { const lp = form.querySelector('input[type="checkbox"][name="list_preview"]'); if (lp) lp.checked = v !== false; continue; }
        if (!el[k]) continue;
        if (el[k + '_custom']) { el[k + '_custom'].checked = isHex(v); if (isHex(v)) el[k].value = v; }
        else if (['canvas', 'sent', 'received'].includes(k)) el[k].value = isHex(v) ? v : defaults[theme][k];
        else el[k].value = String(v);
      }
      form.querySelectorAll('[data-preset-bg]').forEach((x) => x.classList.toggle('active', x.dataset.presetBg === el.background?.value));
      preview();
    };
    const note = document.getElementById('lookNote');
    const say = (text, bad) => { if (note) { note.textContent = text; note.classList.toggle('ap-look-bad', !!bad); } };
    form.querySelectorAll('[data-look]').forEach((card) => card.addEventListener('click', () => {
      let look = {};
      try { look = JSON.parse(card.dataset.look) || {}; } catch (_) {}
      fill(look);
      form.querySelectorAll('.ap-look-card').forEach((x) => x.classList.toggle('active', x === card));
      if (card.dataset.lookName && el.look_name) el.look_name.value = card.dataset.lookName;
      say('“' + card.querySelector('.ap-look-name').textContent + '” is filled in. Save appearance to keep it, or change it first.');
    }));
    // Save as a look: it needs a name, and room for a new one (the same name replaces that look).
    const saveLook = () => {
      const name = (el.look_name?.value || '').trim();
      if (!name) { say('Give the look a name first.', true); el.look_name?.focus(); return; }
      const mine = [...form.querySelectorAll('[data-look-name]')].map((c) => c.dataset.lookName.toLowerCase());
      if (!mine.includes(name.toLowerCase()) && mine.length >= Number(form.dataset.looksMax || 12)) {
        say('You have ' + mine.length + ' saved looks, the most there can be. Delete one, or use the name of one to replace it.', true); return;
      }
      el.look_action.value = 'save';
      form.requestSubmit();
    };
    document.getElementById('lookSave')?.addEventListener('click', saveLook);
    el.look_name?.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); saveLook(); } });
    document.getElementById('appearanceReset')?.addEventListener('click', () => {
      if (form.dataset.defaults) {
        try { fill(JSON.parse(form.dataset.defaults)); } catch (_) {}
        form.querySelectorAll('.ap-look-card').forEach((x) => x.classList.remove('active'));
        return;
      }
      for (const [k, v] of Object.entries(resets)) if (el[k]) el[k].value = v;
      for (const k of ['accent_custom', 'doodle_color_custom', 'compose_color_custom']) if (el[k]) el[k].checked = false;
      const lp = form.querySelector('input[type="checkbox"][name="list_preview"]'); if (lp) lp.checked = true;
      form.querySelectorAll('[data-preset-bg]').forEach((x) => x.classList.toggle('active', x.dataset.presetBg === 'plain'));
      preview();
    });
    form.querySelectorAll('[data-preset-bg]').forEach((b) => b.addEventListener('click', () => {
      el.background.value = b.dataset.presetBg;
      form.querySelectorAll('[data-preset-bg]').forEach((x) => x.classList.toggle('active', x === b));
      preview();
    }));
    preview();
  });
})();
