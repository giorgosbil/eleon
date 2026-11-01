/**
 * "Translate" on an email, in Mail and in the case timeline — shown only when
 * the email is in another language than yours (a quick look at its letters and
 * words, no AI needed for that). The AI translates it (api message_translate,
 * lib/translate.php); the translation replaces the text in place, with "Show
 * original" and other languages to choose from.
 *
 *   window.ecwTranslate.attach(bodyElement, messageId, { before: element })
 */
(function () {
  'use strict';
  const LANGS = { en: 'English', el: 'Ελληνικά', de: 'Deutsch', fr: 'Français', it: 'Italiano', es: 'Español', pt: 'Português', nl: 'Nederlands',
    pl: 'Polski', ro: 'Română', bg: 'Български', tr: 'Türkçe', ru: 'Русский', zh: '中文', ar: 'العربية' };
  const mine = (document.body.dataset.lang || document.documentElement.lang || 'en').slice(0, 2) === 'el' ? 'el' : 'en';
  const csrf = () => document.body.dataset.csrf || '';
  const EN = new Set('the and to of a in is you for that it on with we this be are have as at your please thank thanks regards dear hello hi our will can from not if or by an would could us me my all any'.split(' '));
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; };

  /** 'el', 'en', 'other' (another language), or null (too little text to tell). */
  function guess(text) {
    const t = String(text || '').slice(0, 4000);
    const count = (re) => (t.match(re) || []).length;
    const greek = count(/[Ͱ-Ͽἀ-῿]/g), latin = count(/[A-Za-zÀ-ɏ]/g);
    const other = count(/[Ѐ-ӿ֐-ۿ぀-ヿ㐀-鿿가-힯฀-๿]/g);
    const letters = greek + latin + other;
    if (letters < 25) return null;
    if (other > letters * 0.3) return 'other';
    if (greek > letters * 0.4) return 'el';
    const words = t.toLowerCase().match(/[a-zÀ-ɏ']+/g) || [];
    if (words.length < 8) return null;
    const en = words.filter((w) => EN.has(w)).length / words.length;
    return en >= 0.06 ? 'en' : 'other';
  }

  function attach(body, messageId, opts) {
    if (!body || !messageId || body.dataset.translateReady) return;
    body.dataset.translateReady = '1';
    const bar = el('div', 'tr-bar'); bar.hidden = true;
    const btn = el('button', 'tr-btn'); btn.type = 'button';
    const pick = el('select', 'tr-pick'); pick.setAttribute('aria-label', 'Translate into');
    Object.entries(LANGS).forEach(([k, v]) => { const o = el('option', '', v); o.value = k; pick.appendChild(o); });
    pick.value = mine;
    const note = el('span', 'tr-note muted');
    bar.append(btn, pick, note);
    (opts?.before || body).before(bar);

    let original = null, shown = null;
    const label = () => {
      btn.innerHTML = '';
      btn.append(el('i', 'fa fa-language'), document.createTextNode(shown ? ' Show original' : ' Translate'));
    };
    label();

    // Another language than yours: a clear button, into your language. Your own
    // language (or too little to tell): a quiet one, into the other language
    // (English ↔ Greek) unless you pick another — so translating is always possible.
    let picked = false;
    pick.addEventListener('change', () => { picked = true; });
    const decide = () => {
      if (shown) return;
      const hasText = (body.innerText || '').replace(/\s+/g, '').length >= 10;
      const g = guess(body.innerText);
      const foreign = !!(g && g !== mine);
      bar.hidden = !hasText;
      bar.classList.toggle('tr-quiet', !foreign);
      if (!picked) pick.value = foreign ? mine : (mine === 'en' ? 'el' : 'en');
    };
    decide();
    // The body may arrive later (downloaded after the list): look again then.
    new MutationObserver(() => { if (!shown) decide(); }).observe(body, { childList: true, subtree: true, characterData: true });

    async function translate(to) {
      btn.disabled = true; pick.disabled = true;
      const was = btn.innerHTML;
      btn.innerHTML = '<span class="ai-spin" aria-hidden="true"></span> Translating…';
      try {
        const res = await fetch('api/index.php?action=message_translate', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-ECW-CSRF': csrf() },
          body: JSON.stringify({ message_id: messageId, to }) });
        const d = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(d.error || 'Translation failed (' + res.status + ')');
        if (original === null) original = body.innerHTML;
        // The email's pictures stay, under the translated text.
        const pictures = Array.from(new DOMParser().parseFromString(original, 'text/html').querySelectorAll('img'))
          .filter((i) => !i.classList.contains('ecw-blocked-image') && (Number(i.getAttribute('width')) || 99) > 24);
        // SANITIZED SERVER-SIDE: plain text escaped into <p>, see lib/translate.php.
        body.innerHTML = d.html;
        if (pictures.length) { const box = el('div', 'tr-pictures'); pictures.forEach((i) => box.appendChild(document.importNode(i, true))); body.appendChild(box); }
        body.classList.add('is-translated');
        shown = to;
        note.textContent = 'Translated by AI' + (d.source ? ' from ' + d.source : '') + ' into ' + (LANGS[to] || to) + '.';
        btn.innerHTML = ''; btn.append(el('i', 'fa fa-language'), document.createTextNode(' Show original'));
      } catch (e) {
        btn.innerHTML = was;
        (window.ecwPageToast || ((m) => alert(m)))(e.message, true);
      } finally { btn.disabled = false; pick.disabled = false; }
    }
    btn.addEventListener('click', () => {
      if (shown) {
        body.innerHTML = original; body.classList.remove('is-translated');
        shown = null; note.textContent = ''; label();
        return;
      }
      translate(pick.value);
    });
    pick.addEventListener('change', () => { if (shown) translate(pick.value); });
  }

  window.ecwTranslate = { attach, guess };
})();
