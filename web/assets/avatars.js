/**
 * window.ecwAvatar(person, size) — a round avatar: the person's Outlook photo
 * (person.avatar_url, served by avatar.php) or coloured initials when there is
 * none or it fails to load. person: {name, email, avatar_url}.
 */
(function () {
  'use strict';

  function initials(person) {
    const source = (person.name || person.email || '?').trim();
    const parts = source.split(/[\s@._-]+/).filter(Boolean);
    return ((parts[0] || '?')[0] + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
  }

  function hue(text) {
    let h = 0;
    for (const ch of String(text)) h = (h * 31 + ch.charCodeAt(0)) % 360;
    return h;
  }

  window.ecwAvatar = function (person, size) {
    const wrap = document.createElement('span');
    wrap.className = 'avatar';
    wrap.style.width = wrap.style.height = size + 'px';
    wrap.style.fontSize = Math.round(size * 0.4) + 'px';
    const key = person.email || person.name || '';
    const fallback = () => {
      wrap.textContent = initials(person);
      wrap.style.background = 'hsl(' + hue(key) + ' 45% 88%)';
      wrap.style.color = 'hsl(' + hue(key) + ' 45% 30%)';
    };
    if (person.avatar_url) {
      const img = document.createElement('img');
      img.src = person.avatar_url;
      img.alt = '';
      img.addEventListener('error', () => { img.remove(); fallback(); });
      wrap.appendChild(img);
    } else {
      fallback();
    }
    wrap.title = person.name || person.email || '';
    return wrap;
  };
})();
